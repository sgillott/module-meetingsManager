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

use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingDateOverrideGateway;
use Gibbon\Module\MeetingsManager\MeetingDateResolver;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';
$date = $_GET['date'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_preview.php&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID;

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_preview.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if (empty($meetingsManagerDefinitionID) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
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

if (!meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

// The candidate must genuinely come from the schedule's own date-generation - this can never be
// used to invent a date the resolver wouldn't otherwise have proposed.
$candidates = $container->get(MeetingDateResolver::class)->resolve($definition, null);
$candidate = null;
foreach ($candidates as $c) {
    if ($c['date'] === $date) {
        $candidate = $c;
        break;
    }
}

if ($candidate === null) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$dateOverrideGateway = $container->get(MeetingDateOverrideGateway::class);

if ($candidate['naturalWillGenerate'] === false) {
    // Requested state (excluded) already matches what the resolver would produce naturally - no
    // override needed. If one exists (e.g. a stale Include from before circumstances changed),
    // remove it rather than leave a redundant row behind.
    $dateOverrideGateway->deleteWhere(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID, 'date' => $date]);
} else {
    $dateOverrideGateway->insertAndUpdate([
        'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
        'date' => $date,
        'type' => 'Exclude',
        'gibbonPersonIDCreated' => $session->get('gibbonPersonID'),
        'timestampCreated' => date('Y-m-d H:i:s'),
    ], [
        'type' => 'Exclude',
    ]);
}

$URL .= '&return=success0';
header("Location: {$URL}");
