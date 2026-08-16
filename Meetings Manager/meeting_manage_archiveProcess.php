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
use Gibbon\Module\MeetingsManager\MeetingReconciler;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage.php&gibbonSchoolYearID='.$gibbonSchoolYearID;

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage.php') == false) {
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
$definition = $definitionGateway->getByID($meetingsManagerDefinitionID);

if (empty($definition)) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

if (!meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

try {
    // Delete every future generated event for this definition (via its own occurrence's
    // gibbonCalendarEventID only, never a bulk delete), preserving history, then flip the flag.
    $reconciler = $container->get(MeetingReconciler::class);
    $result = $reconciler->archiveDefinition((int) $meetingsManagerDefinitionID);

    $definitionGateway->update($meetingsManagerDefinitionID, [
        'active' => 'N',
        'timestampModified' => date('Y-m-d H:i:s'),
    ]);
} catch (\Exception $e) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$URL .= '&return=success0&removed='.(int) $result['removed'];
header("Location: {$URL}");
