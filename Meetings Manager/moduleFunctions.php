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

/**
 * One-line human description of a Meeting Definition's schedule, e.g. "17 September 2026, 16:00-17:00"
 * or "Middle & Upper School: Wednesday B, 16:00-17:00" - always using the school's own actual
 * configured timetable/day names, never inventing "Week A/B" terminology of its own. Shared by the
 * list, edit, preview, and occurrence pages so the wording never drifts between them. Expects a row
 * shaped like MeetingDefinitionGateway's enriched queries (dayOfWeekName, timetableName, tiedDayName,
 * singleDate, selectedDateCount already joined).
 */
function meetingsManagerScheduleSummary(array $definition): string
{
    $time = Format::time($definition['timeStart'] ?? '').'-'.Format::time($definition['timeEnd'] ?? '');

    switch ($definition['scheduleType'] ?? '') {
        case 'Single':
            $date = $definition['singleDate'] ?? null;
            return $date ? sprintf(__('%1$s, %2$s'), Format::date($date), $time) : sprintf(__('Once, %1$s'), $time);
        case 'SelectedDates':
            $count = $definition['selectedDateCount'] ?? null;
            return $count !== null
                ? sprintf(__('%1$s selected dates, %2$s'), $count, $time)
                : sprintf(__('Selected dates, %1$s'), $time);
        case 'Weekly':
            return sprintf(__('Every %1$s, %2$s'), $definition['dayOfWeekName'] ?? '?', $time);
        case 'TimetableCycle':
            return sprintf(__('%1$s: %2$s, %3$s'), $definition['timetableName'] ?? '?', $definition['tiedDayName'] ?? '?', $time);
        default:
            return $time;
    }
}

