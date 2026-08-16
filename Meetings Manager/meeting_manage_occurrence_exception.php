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
use Gibbon\Services\Format;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingExceptionGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_occurrence_exception.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $meetingsManagerOccurrenceID = $_GET['meetingsManagerOccurrenceID'] ?? '';
    $meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Occurrences'), 'meeting_manage_occurrences.php', ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID, 'gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Exception'));

    if (empty($meetingsManagerOccurrenceID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
    $occurrence = $occurrenceGateway->getByID($meetingsManagerOccurrenceID);

    if (empty($occurrence) || (string) $occurrence['meetingsManagerDefinitionID'] !== (string) $meetingsManagerDefinitionID) {
        $page->addError(__('The specified record does not exist.'));
        return;
    }

    $definition = $container->get(MeetingDefinitionGateway::class)->getByID($meetingsManagerDefinitionID);
    if (empty($definition) || !meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
        $page->addError(__('You do not have access to this action.'));
        return;
    }

    $existingException = $container->get(MeetingExceptionGateway::class)->selectBy(['meetingsManagerOccurrenceID' => $meetingsManagerOccurrenceID])->fetch();
    $requestedType = $_GET['type'] ?? '';

    echo '<p>'.sprintf(__('Planned: %1$s, %2$s-%3$s'), Format::date($occurrence['plannedDate']), Format::time($occurrence['plannedTimeStart']), Format::time($occurrence['plannedTimeEnd'])).'</p>';
    echo '<p><i>'.__('The planned date and time above remain the recurrence anchor and are never changed by an exception - only the effective values used for the generated Calendar event change.').'</i></p>';

    $form = Form::create('exception', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_occurrence_exceptionProcess.php');
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('meetingsManagerOccurrenceID', $meetingsManagerOccurrenceID);
    $form->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
    $form->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

    $types = [
        'Cancel' => __('Cancel Meeting'),
        'Move'   => __('Move Meeting to a different date'),
        'Retime' => __('Change Time'),
    ];

    $row = $form->addRow();
        $row->addLabel('type', __('Action'));
        $row->addSelect('type')->fromArray($types)->required()->placeholder()->selected($existingException['type'] ?? $requestedType);

    $form->toggleVisibilityByClass('exceptionMove')->onSelect('type')->when('Move');
    $row = $form->addRow()->addClass('exceptionMove');
        $row->addLabel('newDate', __('New Date'));
        $row->addDate('newDate')->setValue($existingException['newDate'] ?? '');

    $form->toggleVisibilityByClass('exceptionTime')->onSelect('type')->when(['Move', 'Retime']);
    $row = $form->addRow()->addClass('exceptionTime');
        $row->addLabel('newTimeStart', __('New Start Time'))->description(__('Leave blank to keep the planned time.'));
        $row->addTime('newTimeStart')->setValue($existingException['newTimeStart'] ?? '');
    $row = $form->addRow()->addClass('exceptionTime');
        $row->addLabel('newTimeEnd', __('New End Time'))->description(__('Leave blank to keep the planned time.'));
        $row->addTime('newTimeEnd')->setValue($existingException['newTimeEnd'] ?? '');

    $row = $form->addRow();
        $row->addLabel('note', __('Note'));
        $row->addTextArea('note')->setRows(2)->setValue($existingException['note'] ?? '');

    $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

    echo $form->getOutput();
}
