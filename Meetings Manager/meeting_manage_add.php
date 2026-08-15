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

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_add.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Add Meeting'));

    if (isset($_GET['editID'])) {
        $editLink = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_edit.php&meetingsManagerDefinitionID='.$_GET['editID'].'&gibbonSchoolYearID='.$gibbonSchoolYearID;
        $page->return->setEditLink($editLink);
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
        $row->addLabel('location', __('Location'));
        $row->addTextField('location')->maxLength(255);

    $row = $form->addRow();
        $row->addLabel('gibbonPersonIDOrganiser', __('Organiser'));
        $row->addSelectStaff('gibbonPersonIDOrganiser')->required()->selected($session->get('gibbonPersonID'));

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
}
