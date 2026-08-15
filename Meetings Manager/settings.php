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

use Gibbon\Forms\Form;
use Gibbon\Domain\Calendar\CalendarEventTypeGateway;
use Gibbon\Module\MeetingsManager\CalendarEventService;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/settings.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $page->breadcrumbs->add(__('Meetings Manager Settings'));

    // Resolving here (rather than only on save) means the setting is always valid by the time this
    // page is viewed, even the very first time - the same self-healing behaviour Calendar generation
    // will rely on.
    $calendarEventService = $container->get(CalendarEventService::class);
    $currentEventTypeID = $calendarEventService->getEventTypeID();

    $eventTypes = $container->get(CalendarEventTypeGateway::class)->selectAllEventTypes()->fetchAll(\PDO::FETCH_KEY_PAIR);

    $form = Form::create('settings', $session->get('absoluteURL').'/modules/'.$session->get('module').'/settingsProcess.php');
    $form->addHiddenValue('address', $session->get('address'));

    $row = $form->addRow();
        $row->addLabel('gibbonCalendarEventTypeID', __('Calendar Event Type'))->description(__('The native Gibbon Calendar event type used for every meeting this module generates. Defaults to "Meeting", resolving or creating it automatically.'));
        $row->addSelect('gibbonCalendarEventTypeID')->fromArray($eventTypes)->required()->selected($currentEventTypeID);

    $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

    echo $form->getOutput();
}
