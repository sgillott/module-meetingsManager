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
use Gibbon\Module\MeetingsManager\Domain\MeetingSelectedDateGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $page->return->addReturn('errorSpaceRequired', __('Your request failed because a Space is required for Internal meetings.'));

    $meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Edit Meeting'));

    $page->return->setEditLink($session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_edit.php&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID);

    if (empty($meetingsManagerDefinitionID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $definitionGateway = $container->get(MeetingDefinitionGateway::class);
    $definition = $definitionGateway->getDefinitionDetailsByID($meetingsManagerDefinitionID);

    if (empty($definition)) {
        $page->addError(__('The specified record does not exist.'));
        return;
    }

    if (!meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
        $page->addError(__('You do not have access to this action.'));
        return;
    }

    $scopedToSelf = meetingsManagerScopeToSelf($guid, $connection2, $session) !== null;

    $schoolYear = $container->get(SchoolYearGateway::class)->getByID($definition['gibbonSchoolYearID']);

    if ($definition['active'] != 'Y') {
        $page->addWarning(__('This meeting series is archived. Archived meetings are read-only.'));
    } else {

    // ---------------------------------------------------------------
    // Basic Details + Schedule
    // ---------------------------------------------------------------

    $form = Form::create('edit', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_editProcess.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
    $form->addHiddenValue('gibbonSchoolYearID', $definition['gibbonSchoolYearID']);

    $form->addRow()->addHeading(__('Meeting'));

    $row = $form->addRow();
        $row->addLabel('schoolYear', __('Academic Year'));
        $row->addTextField('schoolYear')->readonly()->setValue($schoolYear['name'] ?? '');

    $row = $form->addRow();
        $row->addLabel('name', __('Name'));
        $row->addTextField('name')->maxLength(120)->required()->setValue($definition['name']);

    $row = $form->addRow();
        $row->addLabel('description', __('Description'));
        $row->addTextArea('description')->setRows(3)->setValue($definition['description']);

    $row = $form->addRow();
        $row->addLabel('locationType', __('Location Type'));
        $row->addSelect('locationType')->fromArray(['Internal' => __('Internal'), 'External' => __('External')])->required()->selected($definition['locationType']);

    $form->toggleVisibilityByClass('internal')->onSelect('locationType')->when('Internal');
    $row = $form->addRow()->addClass('internal');
        $row->addLabel('gibbonSpaceID', __('Space'));
        $row->addSelectSpace('gibbonSpaceID')->required()->selected($definition['gibbonSpaceID'] ?? '');

    $form->toggleVisibilityByClass('external')->onSelect('locationType')->when('External');
    $row = $form->addRow()->addClass('external');
        $row->addLabel('locationDetail', __('Location Detail'));
        $row->addTextField('locationDetail')->maxLength(255)->setValue($definition['locationDetail'] ?? '');

    $row = $form->addRow();
        $row->addLabel('gibbonPersonIDOrganiser', __('Organiser'));
        if ($scopedToSelf) {
            // Manage Meetings_my never lets the organiser be reassigned - locked to self, and the
            // process script re-forces this server-side regardless of what's actually posted here.
            $row->addTextField('gibbonPersonIDOrganiserDisplay')->readonly()->setValue($definition['organiserName'] ?? '');
        } else {
            $row->addSelectStaff('gibbonPersonIDOrganiser')->required()->selected($definition['gibbonPersonIDOrganiser']);
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
        $row->addSelect('scheduleType')->fromArray($scheduleTypes)->required()->selected($definition['scheduleType']);

    $row = $form->addRow();
        $row->addLabel('timeStart', __('Start Time'));
        $row->addTime('timeStart')->required()->setValue($definition['timeStart']);

    $row = $form->addRow();
        $row->addLabel('timeEnd', __('End Time'));
        $row->addTime('timeEnd')->required()->setValue($definition['timeEnd']);

    $form->toggleVisibilityByClass('scheduleSingle')->onSelect('scheduleType')->when('Single');
    $currentSingleDate = $container->get(MeetingSelectedDateGateway::class)->selectDatesByDefinition($meetingsManagerDefinitionID)->fetch();
    $row = $form->addRow()->addClass('scheduleSingle');
        $row->addLabel('singleDate', __('Date'));
        $row->addDate('singleDate')->setValue($currentSingleDate['date'] ?? '');

    $form->toggleVisibilityByClass('scheduleSelectedDates')->onSelect('scheduleType')->when('SelectedDates');
    $row = $form->addRow()->addClass('scheduleSelectedDates');
        $row->addLabel('', '');
        $row->addContent('<i>'.__('Manage the selected dates below.').'</i>');

    $form->toggleVisibilityByClass('scheduleWeekly')->onSelect('scheduleType')->when('Weekly');
    $daysOfWeek = $container->get(DaysOfWeekGateway::class)->selectSchoolWeekdays()->fetchAll();
    $daysOfWeekOptions = [];
    foreach ($daysOfWeek as $day) {
        $daysOfWeekOptions[$day['gibbonDaysOfWeekID']] = $day['name'];
    }
    $row = $form->addRow()->addClass('scheduleWeekly');
        $row->addLabel('gibbonDaysOfWeekID', __('Day of the Week'));
        $row->addSelect('gibbonDaysOfWeekID')->fromArray($daysOfWeekOptions)->placeholder()->selected($definition['gibbonDaysOfWeekID']);

    $form->toggleVisibilityByClass('scheduleTimetableCycle')->onSelect('scheduleType')->when('TimetableCycle');
    $timetableDayOptions = [];
    $timetables = $container->get(TimetableGateway::class)->selectTimetablesBySchoolYear($definition['gibbonSchoolYearID'])->fetchAll();
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
            $row->addSelect('gibbonTTDayID')->fromArray($timetableDayOptions)->placeholder()->selected($definition['gibbonTTDayID']);
        }

    $form->toggleVisibilityByClass('scheduleRange')->onSelect('scheduleType')->when(['Weekly', 'TimetableCycle']);
    $row = $form->addRow()->addClass('scheduleRange');
        $row->addLabel('rangeStart', __('From'))->description(sprintf(__('Defaults to %1$s if left blank.'), Format::date($schoolYear['firstDay'] ?? '')));
        $row->addDate('rangeStart')->setValue($definition['rangeStart']);
    $row = $form->addRow()->addClass('scheduleRange');
        $row->addLabel('rangeEnd', __('To'))->description(sprintf(__('Defaults to %1$s if left blank.'), Format::date($schoolYear['lastDay'] ?? '')));
        $row->addDate('rangeEnd')->setValue($definition['rangeEnd']);

    // ---------------------------------------------------------------
    // Audience - injected into the same $form as rows (list + Add Rule picker together), so it
    // renders as one boxed section (matching Meeting/Schedule) above Submit.
    // ---------------------------------------------------------------

    meetingsManagerRenderAudienceSection($container, $pdo, $session, $form, $definition);

    $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

    echo $form->getOutput();

    // ---------------------------------------------------------------
    // Selected Dates (only relevant for the SelectedDates schedule type)
    // ---------------------------------------------------------------

    if ($definition['scheduleType'] === 'SelectedDates') {
        echo '<h3>'.__('Selected Dates').'</h3>';

        $dates = $container->get(MeetingSelectedDateGateway::class)->selectDatesByDefinition($meetingsManagerDefinitionID)->fetchAll();

        if (empty($dates)) {
            echo '<p><i>'.__('No dates have been added yet.').'</i></p>';
        } else {
            echo '<table class="smallIntBorder w-full">';
            foreach ($dates as $date) {
                echo '<tr><td>'.Format::date($date['date']).'</td><td class="w-16 text-right">';
                echo '<a class="text-red-700" href="'.$session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_edit_date_deleteProcess.php?meetingsManagerSelectedDateID='.$date['meetingsManagerSelectedDateID'].'&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID.'" onclick="return confirm(\''.__('Are you sure you want to remove this date?').'\')">'.__('Remove').'</a>';
                echo '</td></tr>';
            }
            echo '</table>';
        }

        $dateForm = Form::create('addDate', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_edit_date_addProcess.php');
        $dateForm->addHiddenValue('address', $session->get('address'));
        $dateForm->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
        $dateForm->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

        $row = $dateForm->addRow();
            $row->addLabel('date', __('Add Date'));
            $row->addDate('date')->required();

        $row = $dateForm->addRow();
            $row->addSubmit(__('Add Date'));

        echo $dateForm->getOutput();
    }

    }
}
