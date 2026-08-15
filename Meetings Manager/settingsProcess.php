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
use Gibbon\Domain\Calendar\CalendarEventTypeGateway;
use Gibbon\Domain\System\SettingGateway;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$gibbonCalendarEventTypeID = $_POST['gibbonCalendarEventTypeID'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/'.getModuleName($_POST['address'])."/settings.php";

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/settings.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

// Never trust the posted ID merely because it came from the form - it must be a real event type.
if (empty($gibbonCalendarEventTypeID) || !$container->get(CalendarEventTypeGateway::class)->exists($gibbonCalendarEventTypeID)) {
    $URL .= '&return=error1';
    header("Location: {$URL}");
    exit;
}

$container->get(SettingGateway::class)->updateSettingByScope('Meetings Manager', 'gibbonCalendarEventTypeID', $gibbonCalendarEventTypeID);

$URL .= '&return=success0';
header("Location: {$URL}");
