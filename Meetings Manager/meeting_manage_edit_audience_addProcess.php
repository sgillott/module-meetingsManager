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
use Gibbon\Domain\School\YearGroupGateway;
use Gibbon\Domain\Departments\DepartmentGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$meetingsManagerDefinitionID = $_POST['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_POST['gibbonSchoolYearID'] ?? '';
$type = $_POST['type'] ?? '';

// Year Group/Department/Staff fields are multi-select - each selected value becomes its own rule
// row, so picking 3 Year Groups in one submission is 3 rules, not one rule with 3 targets.
$gibbonYearGroupIDs = (array) ($_POST['gibbonYearGroupID'] ?? []);
$gibbonDepartmentIDs = (array) ($_POST['gibbonDepartmentID'] ?? []);
$gibbonPersonIDs = (array) ($_POST['gibbonPersonID'] ?? []);

// Reachable from both Edit (full rule management) and Preview (quick add/remove of an individual) -
// return to whichever page the form was submitted from.
$returnPage = ($_POST['returnPage'] ?? '') === 'meeting_manage_preview.php' ? 'meeting_manage_preview.php' : 'meeting_manage_edit.php';
$URL = $session->get('absoluteURL').'/index.php?q=/modules/'.getModuleName($_POST['address'])."/$returnPage&meetingsManagerDefinitionID=$meetingsManagerDefinitionID&gibbonSchoolYearID=$gibbonSchoolYearID";

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$validTypes = ['AllTeachingStaff', 'YearGroup', 'Department', 'DepartmentCoordinator', 'Individual', 'ExcludeIndividual'];
if (empty($meetingsManagerDefinitionID) || !in_array($type, $validTypes, true)) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$definitionGateway = $container->get(MeetingDefinitionGateway::class);
$definition = $definitionGateway->getByID($meetingsManagerDefinitionID);

if (empty($definition) || $definition['active'] != 'Y') {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$ruleGateway = $container->get(MeetingAudienceRuleGateway::class);

// Never trust a posted target ID merely because it came from the form - re-validate every one
// exists, and build the actual insert list only from values that passed validation.
$rowsToInsert = [];

if ($type === 'AllTeachingStaff') {
    $rowsToInsert[] = ['gibbonYearGroupID' => null, 'gibbonDepartmentID' => null, 'gibbonPersonID' => null];
} elseif ($type === 'YearGroup') {
    if (empty($gibbonYearGroupIDs)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $yearGroupGateway = $container->get(YearGroupGateway::class);
    foreach ($gibbonYearGroupIDs as $id) {
        if (!empty($yearGroupGateway->getByID($id))) {
            $rowsToInsert[] = ['gibbonYearGroupID' => $id, 'gibbonDepartmentID' => null, 'gibbonPersonID' => null];
        }
    }
} elseif ($type === 'Department' || $type === 'DepartmentCoordinator') {
    if (empty($gibbonDepartmentIDs)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $departmentGateway = $container->get(DepartmentGateway::class);
    foreach ($gibbonDepartmentIDs as $id) {
        if (!empty($departmentGateway->getByID($id))) {
            $rowsToInsert[] = ['gibbonYearGroupID' => null, 'gibbonDepartmentID' => $id, 'gibbonPersonID' => null];
        }
    }
} elseif ($type === 'Individual' || $type === 'ExcludeIndividual') {
    if (empty($gibbonPersonIDs)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }
    $staffGateway = $container->get(StaffGateway::class);
    foreach ($gibbonPersonIDs as $id) {
        if ($staffGateway->selectStaffByID($id)->rowCount() > 0) {
            $rowsToInsert[] = ['gibbonYearGroupID' => null, 'gibbonDepartmentID' => null, 'gibbonPersonID' => $id];
        }
    }
}

if (empty($rowsToInsert)) {
    // Nothing posted validated against real data - don't silently insert an empty/meaningless rule.
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

// Individual and ExcludeIndividual are a reversible pair for the same person - an exclusion always
// wins over any inclusion rule (see AudienceResolver::resolve()), so adding someone back via
// Individual would otherwise be silently overridden by a still-present earlier ExcludeIndividual rule
// for them, and vice versa. Clear the opposite rule for this person so Add/Remove actually reverses.
if ($type === 'Individual' || $type === 'ExcludeIndividual') {
    $oppositeType = $type === 'Individual' ? 'ExcludeIndividual' : 'Individual';
    foreach ($rowsToInsert as $row) {
        $ruleGateway->deleteWhere([
            'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
            'type' => $oppositeType,
            'gibbonPersonID' => $row['gibbonPersonID'],
        ]);
    }
}

foreach ($rowsToInsert as $row) {
    $ruleGateway->insert(array_merge(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID, 'type' => $type], $row));
}

$URL .= '&return=success0';
header("Location: {$URL}");
