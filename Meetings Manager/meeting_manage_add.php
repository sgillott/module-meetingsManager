<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\School\SchoolYearGateway;
use Gibbon\Domain\School\DaysOfWeekGateway;
use Gibbon\Domain\Timetable\TimetableGateway;
use Gibbon\Domain\Timetable\TimetableDayGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_add.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $page->return->addReturn('errorSpaceRequired', __('Your request failed because a Space is required for Internal meetings.'));

    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Add Meeting'));

    $justCreatedDefinition = null;
    if (isset($_GET['editID'])) {
        $editLink = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_edit.php&meetingsManagerDefinitionID='.$_GET['editID'].'&gibbonSchoolYearID='.$gibbonSchoolYearID;
        $page->return->setEditLink($editLink);

        // The meeting itself was just created (see meeting_manage_addProcess.php's redirect) - offer
        // to configure its Audience right here, without a separate trip to Edit. Re-check ownership
        // even though the create just succeeded, in case the definition was somehow reassigned.
        $justCreatedDefinition = $container->get(MeetingDefinitionGateway::class)->getDefinitionDetailsByID($_GET['editID']);
        if (empty($justCreatedDefinition) || !meetingsManagerCanManage($guid, $connection2, $session, $justCreatedDefinition)) {
            $justCreatedDefinition = null;
        }
    }

    if (empty($gibbonSchoolYearID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $schoolYear = $container->get(SchoolYearGateway::class)->getByID($gibbonSchoolYearID);
    if (empty($schoolYear)) {
        $page->addError(__('The specified record does not exist.'));
        return;
    }

    $scopedToSelf = meetingsManagerScopeToSelf($guid, $connection2, $session) !== null;

    $form = Form::create('add', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_addProcess.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

    $form->addRow()->addHeading(__('Meeting'));

    $row = $form->addRow();
        $row->addLabel('schoolYear', __('Academic Year'));
        $row->addTextField('schoolYear')->readonly()->setValue($schoolYear['name']);

    $row = $form->addRow();
        $row->addLabel('name', __('Name'));
        $row->addTextField('name')->maxLength(120)->required();

    $row = $form->addRow();
        $row->addLabel('description', __('Description'));
        $row->addTextArea('description')->setRows(3);

    $row = $form->addRow();
        $row->addLabel('locationType', __('Location Type'));
        $row->addSelect('locationType')->fromArray(['Internal' => __('Internal'), 'External' => __('External')])->required()->selected('Internal');

    $form->toggleVisibilityByClass('internal')->onSelect('locationType')->when('Internal');
    $row = $form->addRow()->addClass('internal');
        $row->addLabel('gibbonSpaceID', __('Space'));
        $row->addSelectSpace('gibbonSpaceID')->required();

    $form->toggleVisibilityByClass('external')->onSelect('locationType')->when('External');
    $row = $form->addRow()->addClass('external');
        $row->addLabel('locationDetail', __('Location Detail'));
        $row->addTextField('locationDetail')->maxLength(255);

    $row = $form->addRow();
        $row->addLabel('gibbonPersonIDOrganiser', __('Organiser'));
        if ($scopedToSelf) {
            // Manage Meetings_my never lets the organiser be anyone but self - locked, and the
            // process script re-forces this server-side regardless of what's actually posted here.
            $row->addTextField('gibbonPersonIDOrganiserDisplay')->readonly()->setValue(Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', true, true));
        } else {
            $row->addSelectStaff('gibbonPersonIDOrganiser')->required()->selected($session->get('gibbonPersonID'));
        }

    $form->addRow()->addHeading(__('Schedule'));

    $scheduleTypes = [
        'Single'         => __('Once'),
        'SelectedDates'  => __('Selected Dates'),
        'Weekly'         => __('Every Week'),
        'TimetableCycle' => __('School Timetable Cycle'),
    ];

    $row = $form->addRow();
        $row->addLabel('scheduleType', __('Schedule Type'));
        $row->addSelect('scheduleType')->fromArray($scheduleTypes)->required()->placeholder();

    $row = $form->addRow();
        $row->addLabel('timeStart', __('Start Time'));
        $row->addTime('timeStart')->required();

    $row = $form->addRow();
        $row->addLabel('timeEnd', __('End Time'));
        $row->addTime('timeEnd')->required();

    // Single: a single date, created immediately as its one meetingsManagerSelectedDate row.
    $form->toggleVisibilityByClass('scheduleSingle')->onSelect('scheduleType')->when('Single');
    $row = $form->addRow()->addClass('scheduleSingle');
        $row->addLabel('singleDate', __('Date'));
        $row->addDate('singleDate');

    // SelectedDates: no dates here - added on the Edit page once the definition exists.
    $form->toggleVisibilityByClass('scheduleSelectedDates')->onSelect('scheduleType')->when('SelectedDates');
    $row = $form->addRow()->addClass('scheduleSelectedDates');
        $row->addLabel('', '');
        $row->addContent('<i>'.__('You will be able to add specific dates once this meeting has been created.').'</i>');

    // Weekly: a day of the week (school days only) plus an optional date range.
    $form->toggleVisibilityByClass('scheduleWeekly')->onSelect('scheduleType')->when('Weekly');
    $daysOfWeek = $container->get(DaysOfWeekGateway::class)->selectSchoolWeekdays()->fetchAll();
    $daysOfWeekOptions = [];
    foreach ($daysOfWeek as $day) {
        $daysOfWeekOptions[$day['gibbonDaysOfWeekID']] = $day['name'];
    }
    $row = $form->addRow()->addClass('scheduleWeekly');
        $row->addLabel('gibbonDaysOfWeekID', __('Day of the Week'));
        $row->addSelect('gibbonDaysOfWeekID')->fromArray($daysOfWeekOptions)->placeholder();

    // TimetableCycle: a Timetable Day, grouped by timetable, restricted to this school year's timetables.
    $form->toggleVisibilityByClass('scheduleTimetableCycle')->onSelect('scheduleType')->when('TimetableCycle');
    $timetableDayOptions = [];
    $timetables = $container->get(TimetableGateway::class)->selectTimetablesBySchoolYear($gibbonSchoolYearID)->fetchAll();
    foreach ($timetables as $timetable) {
        $days = $container->get(TimetableDayGateway::class)->selectTTDaysByTimetable($timetable['gibbonTTID'])->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (!empty($days)) {
            $timetableDayOptions[$timetable['name']] = $days;
        }
    }
    $row = $form->addRow()->addClass('scheduleTimetableCycle');
        $row->addLabel('gibbonTTDayID', __('Timetable Day'))->description(__('Grouped by timetable. Only actual configured timetable days for this academic year are listed.'));
        if (empty($timetableDayOptions)) {
            $row->addContent('<i>'.__('No timetables with configured days are available for this academic year.').'</i>');
        } else {
            $row->addSelect('gibbonTTDayID')->fromArray($timetableDayOptions)->placeholder();
        }

    // Weekly / TimetableCycle: an optional date range, defaulting to the school year's own bounds.
    $form->toggleVisibilityByClass('scheduleRange')->onSelect('scheduleType')->when(['Weekly', 'TimetableCycle']);
    $row = $form->addRow()->addClass('scheduleRange');
        $row->addLabel('rangeStart', __('From'))->description(sprintf(__('Defaults to %1$s if left blank.'), Format::date($schoolYear['firstDay'] ?? '')));
        $row->addDate('rangeStart');
    $row = $form->addRow()->addClass('scheduleRange');
        $row->addLabel('rangeEnd', __('To'))->description(sprintf(__('Defaults to %1$s if left blank.'), Format::date($schoolYear['lastDay'] ?? '')));
        $row->addDate('rangeEnd');

    $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

    echo $form->getOutput();

    // ---------------------------------------------------------------
    // Audience - only shown once a meeting has actually been created (via the editID redirect from
    // meeting_manage_addProcess.php), since audience rules need a real meetingsManagerDefinitionID.
    // Reuses the exact same rendering as meeting_manage_edit.php's Audience section.
    // ---------------------------------------------------------------

    if ($justCreatedDefinition !== null) {
        $page->addMessage(sprintf(__('"%1$s" was created. Now add who should attend below.'), htmlspecialchars($justCreatedDefinition['name'])));

        // A container purely for the shared section's layout/styling - its own default action is
        // never actually used, since the Add Rule button inside it overrides its target via
        // formaction, and rule removal is a plain link, not a submission.
        $audienceContainer = Form::create('audience', $editLink);
        meetingsManagerRenderAudienceSection($container, $pdo, $session, $audienceContainer, $justCreatedDefinition);

        echo $audienceContainer->getOutput();
    }
}
