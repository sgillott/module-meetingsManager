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
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;

require_once '../../gibbon.php';

$meetingsManagerAudienceRuleID = $_GET['meetingsManagerAudienceRuleID'] ?? '';
$meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
$gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

// Reachable from both Edit (full rule management) and Preview (quick add/remove of an individual) -
// return to whichever page the link was clicked from.
$returnPage = ($_GET['returnPage'] ?? '') === 'meeting_manage_preview.php' ? 'meeting_manage_preview.php' : 'meeting_manage_edit.php';
$URL = $session->get('absoluteURL')."/index.php?q=/modules/Meetings Manager/$returnPage&meetingsManagerDefinitionID=$meetingsManagerDefinitionID&gibbonSchoolYearID=$gibbonSchoolYearID";

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

if (empty($meetingsManagerAudienceRuleID) || empty($meetingsManagerDefinitionID)) {
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
$rule = $ruleGateway->getByID($meetingsManagerAudienceRuleID);

// Ownership check - only ever delete a row that genuinely belongs to this definition.
if (empty($rule) || (string) $rule['meetingsManagerDefinitionID'] !== (string) $meetingsManagerDefinitionID) {
    $URL .= '&return=error2';
    header("Location: {$URL}");
    exit;
}

$ruleGateway->delete($meetingsManagerAudienceRuleID);

$URL .= '&return=success0';
header("Location: {$URL}");
