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
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;
use Gibbon\Module\MeetingsManager\AudienceResolver;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
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
        $row->addLabel('location', __('Location'));
        $row->addTextField('location')->maxLength(255)->setValue($definition['location']);

    $row = $form->addRow();
        $row->addLabel('gibbonPersonIDOrganiser', __('Organiser'));
        $row->addSelectStaff('gibbonPersonIDOrganiser')->required()->selected($definition['gibbonPersonIDOrganiser']);

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

    // ---------------------------------------------------------------
    // Audience Rules
    // ---------------------------------------------------------------

    echo '<h3>'.__('Audience').'</h3>';

    $ruleGateway = $container->get(MeetingAudienceRuleGateway::class);
    $rules = $ruleGateway->selectRulesByDefinition($meetingsManagerDefinitionID)->fetchAll();
    $audienceResolver = $container->get(AudienceResolver::class);

    if (empty($rules)) {
        echo '<p><i>'.__('No audience rules have been added yet. No participants will be resolved until at least one inclusion rule is added.').'</i></p>';
    } else {
        echo '<table class="smallIntBorder w-full">';
        foreach ($rules as $rule) {
            echo '<tr><td>'.htmlspecialchars($audienceResolver->describeRule($rule)).'</td><td class="w-16 text-right">';
            echo '<a class="text-red-700" href="'.$session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_edit_audience_deleteProcess.php?meetingsManagerAudienceRuleID='.$rule['meetingsManagerAudienceRuleID'].'&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID.'" onclick="return confirm(\''.__('Are you sure you want to remove this rule?').'\')">'.__('Remove').'</a>';
            echo '</td></tr>';
        }
        echo '</table>';

        $resolved = $audienceResolver->resolve((int) $definition['gibbonSchoolYearID'], $rules);
        echo '<p>'.sprintf(__('Resolved Participants: %1$s'), count($resolved)).'</p>';
    }

    $ruleTypes = [
        'AllTeachingStaff'      => __('All Teaching Staff'),
        'YearGroup'             => __('Teachers of Selected Year Groups'),
        'Department'            => __('Staff in Selected Departments'),
        'DepartmentCoordinator' => __('Department Coordinators'),
        'Individual'            => __('Specific Staff'),
        'ExcludeIndividual'     => __('Exclude Individual'),
    ];

    $ruleForm = Form::create('addRule', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_edit_audience_addProcess.php');
    $ruleForm->setFactory(DatabaseFormFactory::create($pdo));
    $ruleForm->addHiddenValue('address', $session->get('address'));
    $ruleForm->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
    $ruleForm->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

    $row = $ruleForm->addRow();
        $row->addLabel('type', __('Add Audience Rule'));
        $row->addSelect('type')->fromArray($ruleTypes)->required()->placeholder();

    // Year Groups/Departments/Staff can each be multi-selected - picking 3 Year Groups adds 3 rules
    // in one step rather than requiring the form to be submitted 3 times.
    $ruleForm->toggleVisibilityByClass('ruleYearGroup')->onSelect('type')->when('YearGroup');
    $row = $ruleForm->addRow()->addClass('ruleYearGroup');
        $row->addLabel('gibbonYearGroupID', __('Year Groups'));
        $row->addSelectYearGroup('gibbonYearGroupID')->selectMultiple();

    $ruleForm->toggleVisibilityByClass('ruleDepartment')->onSelect('type')->when(['Department', 'DepartmentCoordinator']);
    $row = $ruleForm->addRow()->addClass('ruleDepartment');
        $row->addLabel('gibbonDepartmentID', __('Departments'));
        $row->addSelectDepartment('gibbonDepartmentID')->selectMultiple();

    $ruleForm->toggleVisibilityByClass('ruleIndividual')->onSelect('type')->when(['Individual', 'ExcludeIndividual']);
    $row = $ruleForm->addRow()->addClass('ruleIndividual');
        $row->addLabel('gibbonPersonID', __('Staff Member(s)'));
        $row->addSelectStaff('gibbonPersonID')->selectMultiple();

    $row = $ruleForm->addRow();
        $row->addSubmit(__('Add Rule'));

    echo $ruleForm->getOutput();

    }
}
