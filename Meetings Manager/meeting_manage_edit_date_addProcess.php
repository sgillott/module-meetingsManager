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
use Gibbon\Module\MeetingsManager\Domain\MeetingSelectedDateGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$meetingsManagerDefinitionID = $_POST['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_POST['gibbonSchoolYearID'] ?? '';
$date = trim($_POST['date'] ?? '');

$URL = $session->get('absoluteURL').'/index.php?q=/modules/'.getModuleName($_POST['address'])."/meeting_manage_edit.php&meetingsManagerDefinitionID=$meetingsManagerDefinitionID&gibbonSchoolYearID=$gibbonSchoolYearID";

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if (empty($meetingsManagerDefinitionID) || $date === '') {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$definitionGateway = $container->get(MeetingDefinitionGateway::class);
$definition = $definitionGateway->getByID($meetingsManagerDefinitionID);

if (empty($definition) || $definition['active'] != 'Y' || $definition['scheduleType'] !== 'SelectedDates') {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

if (!meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$selectedDateGateway = $container->get(MeetingSelectedDateGateway::class);

if (!$selectedDateGateway->unique(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID, 'date' => $date], ['meetingsManagerDefinitionID', 'date'])) {
    $URL .= '&return=error7';
    header("Location: {$URL}");
    exit;
}

$selectedDateGateway->insert([
    'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
    'date' => $date,
]);

$URL .= '&return=success0';
header("Location: {$URL}");
