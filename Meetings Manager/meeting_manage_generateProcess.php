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
use Gibbon\Module\MeetingsManager\MeetingReconciler;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$meetingsManagerDefinitionID = $_POST['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_POST['gibbonSchoolYearID'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/meeting_manage_preview.php&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID;

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

if (empty($definition) || $definition['active'] != 'Y') {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

try {
    $reconciler = $container->get(MeetingReconciler::class);
    $result = $reconciler->reconcile((int) $meetingsManagerDefinitionID);
} catch (\Exception $e) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$URL .= '&return=success0'
    .'&created='.(int) $result['created']
    .'&updated='.(int) $result['updated']
    .'&removed='.(int) $result['removed']
    .'&participants='.(int) $result['participants']
    .'&excludedByClosure='.(int) $result['excludedByClosure']
    .'&calendarHealed='.($result['calendarHealed'] ? '1' : '0');
header("Location: {$URL}");
