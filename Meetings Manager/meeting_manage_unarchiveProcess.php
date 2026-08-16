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

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage.php&gibbonSchoolYearID='.$gibbonSchoolYearID.'&show=archived';

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

if (empty($definition) || $definition['active'] != 'N') {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

if (!meetingsManagerCanManage($guid, $connection2, $session, $definition)) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

// No Reconciler involvement needed - archiving already removed every future occurrence row and its
// Calendar event, leaving only past history untouched. Flipping active back to Y is the whole
// operation; the user reviews and regenerates future dates through the normal Preview flow.
$definitionGateway->update($meetingsManagerDefinitionID, [
    'active' => 'Y',
    'timestampModified' => date('Y-m-d H:i:s'),
]);

$URL .= '&return=success0';
header("Location: {$URL}");
