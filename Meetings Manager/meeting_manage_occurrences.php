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

use Gibbon\Services\Format;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingExceptionGateway;
use Gibbon\Module\MeetingsManager\CalendarEventService;
use Gibbon\Module\MeetingsManager\MeetingDateResolver;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_occurrences.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Occurrences'));

    if (empty($meetingsManagerDefinitionID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $definitionGateway = $container->get(MeetingDefinitionGateway::class);
    $definition = $definitionGateway->getDefinitionDetailsByID($meetingsManagerDefinitionID);

    if (empty($definition)) {
        $page->addError(__('The specified record does not exist.'));
        return;
    }

    echo '<h3>'.htmlspecialchars($definition['name']).'</h3>';
    echo '<p>'.htmlspecialchars(meetingsManagerScheduleSummary($definition)).'</p>';

    // Missing-ties is as relevant here (the operational view of what's actually generated) as it is
    // on Preview - don't let this page show an empty/sparse table with no explanation either.
    $diagnostic = $container->get(MeetingDateResolver::class)->getScheduleDiagnostic($definition);
    if ($diagnostic) {
        $page->addWarning($diagnostic['message']);
    }

    $occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
    $exceptionGateway = $container->get(MeetingExceptionGateway::class);
    $calendarEventService = $container->get(CalendarEventService::class);

    $occurrences = $occurrenceGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();
    usort($occurrences, function ($a, $b) { return strcmp($a['plannedDate'].$a['plannedTimeStart'], $b['plannedDate'].$b['plannedTimeStart']); });

    if (empty($occurrences)) {
        if (!$diagnostic) {
            echo '<p><i>'.__('No occurrences have been generated yet. Use Preview to create this meeting series first.').'</i></p>';
        }
    } else {
        $now = date('Y-m-d H:i:s');
        $missingCount = 0;

        echo '<table class="smallIntBorder w-full">';
        echo '<tr><th>'.__('Date').'</th><th>'.__('Time').'</th><th>'.__('Effective Date/Time').'</th><th>'.__('Status').'</th><th>'.__('Exception').'</th><th>'.__('Calendar').'</th><th></th></tr>';

        foreach ($occurrences as $occurrence) {
            $exception = $exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $occurrence['meetingsManagerOccurrenceID']])->fetch() ?: null;

            $effectiveDate = $occurrence['plannedDate'];
            $effectiveTimeStart = $occurrence['plannedTimeStart'];
            $effectiveTimeEnd = $occurrence['plannedTimeEnd'];
            if ($exception && $exception['type'] === 'Move' && !empty($exception['newDate'])) $effectiveDate = $exception['newDate'];
            if ($exception && in_array($exception['type'], ['Move', 'Retime'], true)) {
                if (!empty($exception['newTimeStart'])) $effectiveTimeStart = $exception['newTimeStart'];
                if (!empty($exception['newTimeEnd'])) $effectiveTimeEnd = $exception['newTimeEnd'];
            }
            $effectiveDiffers = $effectiveDate !== $occurrence['plannedDate'] || $effectiveTimeStart !== $occurrence['plannedTimeStart'] || $effectiveTimeEnd !== $occurrence['plannedTimeEnd'];

            $isPast = ($effectiveDate.' '.$effectiveTimeStart) <= $now;
            $isCancelled = $exception && $exception['type'] === 'Cancel';

            $event = !empty($occurrence['gibbonCalendarEventID']) ? $calendarEventService->getEvent($occurrence['gibbonCalendarEventID']) : null;
            $eventMissing = !$isPast && empty($event);
            if ($eventMissing) $missingCount++;

            $rowClass = $isPast ? 'row-disabled' : ($eventMissing ? 'error' : ($isCancelled ? 'warning' : ''));
            echo '<tr'.($rowClass ? ' class="'.$rowClass.'"' : '').'>';
            echo '<td>'.Format::date($occurrence['plannedDate']).($isPast ? ' <span class="text-xs">('.__('past').')</span>' : '').'</td>';
            echo '<td>'.Format::time($occurrence['plannedTimeStart']).'-'.Format::time($occurrence['plannedTimeEnd']).'</td>';
            echo '<td>'.($effectiveDiffers ? Format::date($effectiveDate).' '.Format::time($effectiveTimeStart).'-'.Format::time($effectiveTimeEnd) : '<i>'.__('Same as planned').'</i>').'</td>';
            echo '<td>'.($isCancelled ? '<b>'.__('Cancelled').'</b>' : htmlspecialchars($occurrence['status'])).'</td>';

            if ($exception) {
                $exceptionLabel = ['Cancel' => __('Cancelled'), 'Move' => __('Moved'), 'Retime' => __('Time Changed')][$exception['type']] ?? $exception['type'];
                echo '<td>'.htmlspecialchars($exceptionLabel).'</td>';
            } else {
                echo '<td><i>'.__('None').'</i></td>';
            }

            if ($eventMissing) {
                echo '<td><b>'.__('Missing - use Update Generated Events to repair').'</b></td>';
            } else {
                echo '<td>'.(empty($event) ? '<i>'.__('Not yet generated').'</i>' : htmlspecialchars($event['status'])).'</td>';
            }

            echo '<td class="text-right">';
            if (!$isPast) {
                $baseURL = $session->get('absoluteURL').'/index.php?q=/modules/Meetings Manager/';
                // Direct file path, deliberately NOT routed via index.php?q= - this process script
                // bootstraps its own Gibbon environment with require_once '../../gibbon.php', which
                // only resolves correctly when the file is requested directly.
                $deleteProcessURL = $session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_occurrence_exception_deleteProcess.php';
                $params = 'meetingsManagerOccurrenceID='.$occurrence['meetingsManagerOccurrenceID'].'&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID;

                if (!$exception) {
                    echo '<a href="'.$baseURL.'meeting_manage_occurrence_exception.php&type=Cancel&'.$params.'">'.__('Cancel Meeting').'</a>';
                    echo ' &middot; <a href="'.$baseURL.'meeting_manage_occurrence_exception.php&type=Move&'.$params.'">'.__('Move Meeting').'</a>';
                    echo ' &middot; <a href="'.$baseURL.'meeting_manage_occurrence_exception.php&type=Retime&'.$params.'">'.__('Change Time').'</a>';
                } else {
                    $restoreLabel = $exception['type'] === 'Cancel' ? __('Restore Meeting') : __('Restore Original Schedule');
                    echo '<a href="'.$baseURL.'meeting_manage_occurrence_exception.php&'.$params.'">'.__('Change').'</a>';
                    echo ' &middot; <a class="text-red-700" href="'.$deleteProcessURL.'?'.$params.'" onclick="return confirm(\''.__('Are you sure?').'\')">'.htmlspecialchars($restoreLabel).'</a>';
                }
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';

        if ($missingCount > 0) {
            $page->addWarning(sprintf(__('%1$s occurrence(s) are missing their generated Calendar event. Use Update Generated Events (from Preview) to repair them.'), $missingCount));
        }
    }
}
