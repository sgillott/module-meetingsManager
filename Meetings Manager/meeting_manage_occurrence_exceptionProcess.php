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

use Gibbon\Data\Validator;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingExceptionGateway;
use Gibbon\Module\MeetingsManager\MeetingReconciler;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$meetingsManagerOccurrenceID = $_POST['meetingsManagerOccurrenceID'] ?? '';
$meetingsManagerDefinitionID = $_POST['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_POST['gibbonSchoolYearID'] ?? '';
$type = $_POST['type'] ?? '';
$newDate = trim($_POST['newDate'] ?? '');
$newTimeStart = trim($_POST['newTimeStart'] ?? '');
$newTimeEnd = trim($_POST['newTimeEnd'] ?? '');
$note = trim($_POST['note'] ?? '');

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_occurrences.php&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID;

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_occurrence_exception.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$validTypes = ['Cancel', 'Move', 'Retime'];
if (empty($meetingsManagerOccurrenceID) || !in_array($type, $validTypes, true)) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
$occurrence = $occurrenceGateway->getByID($meetingsManagerOccurrenceID);

if (empty($occurrence) || (string) $occurrence['meetingsManagerDefinitionID'] !== (string) $meetingsManagerDefinitionID) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$definition = $container->get(MeetingDefinitionGateway::class)->getByID($meetingsManagerDefinitionID);
if (empty($definition) || !meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if (($occurrence['plannedDate'].' '.$occurrence['plannedTimeStart']) <= date('Y-m-d H:i:s')) {
    // Historical occurrences are never touched.
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$data = [
    'meetingsManagerOccurrenceID' => $meetingsManagerOccurrenceID,
    'type'                        => $type,
    'newDate'                     => null,
    'newTimeStart'                => null,
    'newTimeEnd'                  => null,
    'note'                        => $note,
    'gibbonPersonIDCreated'       => $session->get('gibbonPersonID'),
    'timestampCreated'            => date('Y-m-d H:i:s'),
];

if ($type === 'Move') {
    if ($newDate === '') {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $data['newDate'] = $newDate;
}

if ($type === 'Move' || $type === 'Retime') {
    $data['newTimeStart'] = $newTimeStart !== '' ? $newTimeStart : null;
    $data['newTimeEnd'] = $newTimeEnd !== '' ? $newTimeEnd : null;

    if ($type === 'Retime' && $newTimeStart === '' && $newTimeEnd === '') {
        // A Retime exception with nothing actually retimed isn't meaningful.
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    $effectiveStart = $data['newTimeStart'] ?? $occurrence['plannedTimeStart'];
    $effectiveEnd = $data['newTimeEnd'] ?? $occurrence['plannedTimeEnd'];
    if ($effectiveEnd <= $effectiveStart) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
}

$exceptionGateway = $container->get(MeetingExceptionGateway::class);

try {
    // At most one exception per occurrence (UNIQUE meetingsManagerOccurrenceID) - replace any
    // existing exception rather than trying to update in place, since the type itself may change.
    $exceptionGateway->deleteWhere(['meetingsManagerOccurrenceID' => $meetingsManagerOccurrenceID]);
    $exceptionGateway->insert($data);

    // Apply immediately, not just on the next full regenerate - reconcile() reads the exception via
    // MeetingReconciler::effectiveSchedule() and updates the owned Calendar event accordingly.
    $reconciler = $container->get(MeetingReconciler::class);
    $reconciler->reconcile((int) $meetingsManagerDefinitionID);
} catch (\Exception $e) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$URL .= '&return=success0';
header("Location: {$URL}");
