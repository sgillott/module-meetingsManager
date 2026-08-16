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
use Gibbon\Domain\Staff\StaffGateway;
use Gibbon\Domain\School\DaysOfWeekGateway;
use Gibbon\Domain\Timetable\TimetableGateway;
use Gibbon\Domain\Timetable\TimetableDayGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingSelectedDateGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$meetingsManagerDefinitionID = $_POST['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_POST['gibbonSchoolYearID'] ?? '';
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$locationType = $_POST['locationType'] ?? '';
$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$locationDetail = trim($_POST['locationDetail'] ?? '');
$gibbonPersonIDOrganiser = $_POST['gibbonPersonIDOrganiser'] ?? '';
$scheduleType = $_POST['scheduleType'] ?? '';
$timeStart = $_POST['timeStart'] ?? '';
$timeEnd = $_POST['timeEnd'] ?? '';
$singleDate = trim($_POST['singleDate'] ?? '');
$gibbonDaysOfWeekID = $_POST['gibbonDaysOfWeekID'] ?? '';
$gibbonTTDayID = $_POST['gibbonTTDayID'] ?? '';
$rangeStart = trim($_POST['rangeStart'] ?? '');
$rangeEnd = trim($_POST['rangeEnd'] ?? '');

$URL = $session->get('absoluteURL').'/index.php?q=/modules/'.getModuleName($_POST['address'])."/meeting_manage_edit.php&meetingsManagerDefinitionID=$meetingsManagerDefinitionID&gibbonSchoolYearID=$gibbonSchoolYearID";

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if (empty($meetingsManagerDefinitionID)) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$definitionGateway = $container->get(MeetingDefinitionGateway::class);
$existing = $definitionGateway->getByID($meetingsManagerDefinitionID);

if (empty($existing)) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

if (!meetingsManagerCanManage($guid, $connection2, $session, $existing)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if ($existing['active'] != 'Y') {
    // Archived definitions are read-only in v1.
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

// Manage Meetings_my never lets the organiser be reassigned, regardless of what the form posted -
// the UI locks this field, but the server is what actually enforces it.
if (meetingsManagerScopeToSelf($guid, $connection2, $session) !== null) {
    $gibbonPersonIDOrganiser = $session->get('gibbonPersonID');
}

$validScheduleTypes = ['Single', 'SelectedDates', 'Weekly', 'TimetableCycle'];
if ($name === '' || empty($gibbonPersonIDOrganiser) || !in_array($scheduleType, $validScheduleTypes, true) || empty($timeStart) || empty($timeEnd)) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

if ($timeEnd <= $timeStart) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$staffGateway = $container->get(StaffGateway::class);
if ($staffGateway->selectStaffByID($gibbonPersonIDOrganiser)->rowCount() == 0) {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

// Location: Internal requires a real gibbonSpaceID (no dedicated gateway exists for gibbonSpace in
// core, so this is a direct existence check, same as core's own createSelectSpace() reads it raw).
// External never stores a space, Internal never stores free text - only one is ever meaningful.
if ($locationType === 'Internal') {
    $validSpace = !empty($gibbonSpaceID) && (int) $pdo->selectOne('SELECT COUNT(*) FROM gibbonSpace WHERE gibbonSpaceID=:gibbonSpaceID', ['gibbonSpaceID' => $gibbonSpaceID]) > 0;
    if (!$validSpace) {
        $URL .= '&return=errorSpaceRequired';
        header("Location: {$URL}");
        exit;
    }
    $locationDetail = null;
} elseif ($locationType === 'External') {
    $gibbonSpaceID = null;
} else {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$data = [
    'name'                    => $name,
    'description'             => $description,
    'locationType'            => $locationType,
    'gibbonSpaceID'           => $gibbonSpaceID,
    'locationDetail'          => $locationDetail,
    'gibbonPersonIDOrganiser' => $gibbonPersonIDOrganiser,
    'scheduleType'            => $scheduleType,
    'timeStart'               => $timeStart,
    'timeEnd'                 => $timeEnd,
    'gibbonDaysOfWeekID'      => null,
    'gibbonTTID'              => null,
    'gibbonTTDayID'           => null,
    'rangeStart'              => null,
    'rangeEnd'                => null,
    'timestampModified'       => date('Y-m-d H:i:s'),
];

if ($scheduleType === 'Single') {
    if ($singleDate === '') {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
} elseif ($scheduleType === 'Weekly') {
    $validDays = $container->get(DaysOfWeekGateway::class)->selectSchoolWeekdays()->fetchAll(\PDO::FETCH_COLUMN, 0);
    if (empty($gibbonDaysOfWeekID) || !in_array($gibbonDaysOfWeekID, $validDays)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $data['gibbonDaysOfWeekID'] = $gibbonDaysOfWeekID;
    $data['rangeStart'] = $rangeStart ?: null;
    $data['rangeEnd'] = $rangeEnd ?: null;
} elseif ($scheduleType === 'TimetableCycle') {
    $timetableGateway = $container->get(TimetableGateway::class);
    $timetableDayGateway = $container->get(TimetableDayGateway::class);

    $validTTDayID = false;
    $ownerTTID = null;
    if (!empty($gibbonTTDayID)) {
        $timetables = $timetableGateway->selectTimetablesBySchoolYear($existing['gibbonSchoolYearID'])->fetchAll();
        foreach ($timetables as $timetable) {
            $days = $timetableDayGateway->selectTTDaysByTimetable($timetable['gibbonTTID'])->fetchAll();
            foreach ($days as $day) {
                if ((int) $day['value'] === (int) $gibbonTTDayID) {
                    $validTTDayID = true;
                    $ownerTTID = $timetable['gibbonTTID'];
                    break 2;
                }
            }
        }
    }

    if (!$validTTDayID) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $data['gibbonTTID'] = $ownerTTID;
    $data['gibbonTTDayID'] = $gibbonTTDayID;
    $data['rangeStart'] = $rangeStart ?: null;
    $data['rangeEnd'] = $rangeEnd ?: null;
}

if (!empty($data['rangeStart']) && !empty($data['rangeEnd']) && $data['rangeEnd'] < $data['rangeStart']) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

try {
    $pdo->beginTransaction();

    $success = $definitionGateway->update($meetingsManagerDefinitionID, $data);

    if (!$success) {
        throw new \Exception('Could not update the meeting definition.');
    }

    // Single mode stores its one date in meetingsManagerSelectedDate too - replace whatever is there
    // (there should be at most one row) with the newly submitted date. SelectedDates rows are managed
    // independently via meeting_manage_edit_date_add/deleteProcess.php, untouched here.
    if ($scheduleType === 'Single') {
        $selectedDateGateway = $container->get(MeetingSelectedDateGateway::class);
        $selectedDateGateway->deleteWhere(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
        $selectedDateGateway->insert([
            'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
            'date' => $singleDate,
        ]);
    }

    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$URL .= '&return=success0';
header("Location: {$URL}");
