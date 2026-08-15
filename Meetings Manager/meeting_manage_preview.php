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
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Prefab\BulkActionForm;
use Gibbon\Tables\DataTable;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Services\Format;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\AudienceResolver;
use Gibbon\Module\MeetingsManager\MeetingDateResolver;
use Gibbon\Module\MeetingsManager\MeetingReconciler;
use Gibbon\Module\MeetingsManager\CalendarEventService;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage_preview.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $meetingsManagerDefinitionID = $_GET['meetingsManagerDefinitionID'] ?? '';
    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? '';

    $page->breadcrumbs
        ->add(__('Manage Meetings'), 'meeting_manage.php', ['gibbonSchoolYearID' => $gibbonSchoolYearID])
        ->add(__('Preview Meeting'));

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

    // Everything on this page down to "Create / Update Generated Events" is deterministic and
    // read-only: MeetingDateResolver, AudienceResolver, and MeetingReconciler::diff() only ever read
    // the stored Meeting Definition and current Calendar state. Nothing here writes a
    // gibbonCalendarEvent, gibbonCalendarEventPerson, or meetingsManagerOccurrence row - that only
    // happens if the explicit button at the bottom is submitted, via MeetingReconciler::reconcile().

    if (($_GET['return'] ?? '') === 'success0' && isset($_GET['created'])) {
        $messages = [sprintf(__('%1$s meetings created'), (int) $_GET['created'])];
        if ((int) $_GET['updated'] > 0) $messages[] = sprintf(__('%1$s updated'), (int) $_GET['updated']);
        if ((int) $_GET['removed'] > 0) $messages[] = sprintf(__('%1$s removed'), (int) $_GET['removed']);
        $messages[] = sprintf(__('%1$s participants resolved'), (int) $_GET['participants']);
        if ((int) ($_GET['excludedByClosure'] ?? 0) > 0) {
            $messages[] = sprintf(__('%1$s date(s) excluded because school was closed'), (int) $_GET['excludedByClosure']);
        }
        $page->addSuccess(implode(', ', $messages) . '.');

        if (!empty($_GET['calendarHealed'])) {
            $page->addMessage(__('A new "Meetings" calendar was created for this academic year, since none existed yet.'));
        }
    }

    // ---------------------------------------------------------------
    // Meeting
    // ---------------------------------------------------------------

    echo '<h3>'.__('Meeting').'</h3>';
    echo '<table class="smallIntBorder w-full">';
    echo '<tr><td class="w-40"><b>'.__('Name').'</b></td><td>'.htmlspecialchars($definition['name']).'</td></tr>';
    echo '<tr><td><b>'.__('Organiser').'</b></td><td>'.htmlspecialchars($definition['organiserName'] ?? '').'</td></tr>';
    if (!empty($definition['location'])) {
        echo '<tr><td><b>'.__('Location').'</b></td><td>'.htmlspecialchars($definition['location']).'</td></tr>';
    }
    if (!empty($definition['description'])) {
        echo '<tr><td><b>'.__('Description').'</b></td><td>'.nl2br(htmlspecialchars($definition['description'])).'</td></tr>';
    }
    echo '<tr><td><b>'.__('Schedule').'</b></td><td>'.htmlspecialchars(meetingsManagerScheduleSummary($definition)).'</td></tr>';
    if ($definition['active'] != 'Y') {
        echo '<tr><td><b>'.__('Status').'</b></td><td class="error">'.__('Archived').'</td></tr>';
    }
    echo '</table>';

    // ---------------------------------------------------------------
    // Participants
    // ---------------------------------------------------------------

    echo '<h3>'.__('Participants').'</h3>';

    $ruleGateway = $container->get(MeetingAudienceRuleGateway::class);
    $rules = $ruleGateway->selectRulesByDefinition($meetingsManagerDefinitionID)->fetchAll();
    $audienceResolver = $container->get(AudienceResolver::class);
    $resolved = $audienceResolver->resolve((int) $definition['gibbonSchoolYearID'], $rules);

    echo '<p><b>'.sprintf(__('Resolved Participants: %1$s'), count($resolved)).'</b></p>';

    if (empty($rules)) {
        echo '<p><i>'.__('No audience rules are configured. No participants will be generated until at least one inclusion rule is added.').'</i></p>';
    } else {
        $participantForm = BulkActionForm::create('removeParticipants', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_edit_audience_addProcess.php');
        $participantForm->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
        $participantForm->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);
        $participantForm->addHiddenValue('returnPage', 'meeting_manage_preview.php');
        $participantForm->addHiddenValue('type', 'ExcludeIndividual');

        $participantsTable = $participantForm->addRow()->addDataTable('participants', new QueryCriteria())->withData(array_values($resolved));
        $participantsTable->addColumn('name', __('Name'))->format(function ($person) use ($definition) {
            $name = Format::name($person['title'], $person['preferredName'], $person['surname'], 'Staff', true, true);
            $isOrganiser = (string) $person['gibbonPersonID'] === (string) $definition['gibbonPersonIDOrganiser'];
            return htmlspecialchars($name).($isOrganiser ? ' <b>('.__('Organiser').')</b>' : '');
        });
        $participantsTable->addColumn('sources', __('Included Via'))->format(function ($person) {
            return htmlspecialchars(implode(', ', $person['sources']));
        });

        if ($definition['active'] === 'Y') {
            $col = $participantForm->createBulkActionColumn(['Remove' => __('Remove')]);
            $col->addSubmit(__('Go'));
            $participantsTable->addMetaData('bulkActions', $col);

            // The organiser is always added to the generated event regardless of audience rules, so
            // removing them here would be a no-op - suppress their checkbox rather than offer a
            // control that does nothing.
            $participantsTable->addCheckboxColumn('gibbonPersonID')->format(function ($person) use ($definition) {
                $isOrganiser = (string) $person['gibbonPersonID'] === (string) $definition['gibbonPersonIDOrganiser'];
                return $isOrganiser ? '&nbsp;' : null;
            });
        }

        echo $participantForm->getOutput();

        if (!isset($resolved[$definition['gibbonPersonIDOrganiser']])) {
            echo '<p><i>'.sprintf(__('The organiser, %1$s, is not part of the resolved audience above and will still be added to the generated event as Organiser.'), htmlspecialchars($definition['organiserName'] ?? '')).'</i></p>';
        }
    }

    if ($definition['active'] === 'Y') {
        $addParticipantForm = Form::create('addParticipant', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_edit_audience_addProcess.php');
        $addParticipantForm->setFactory(DatabaseFormFactory::create($pdo));
        $addParticipantForm->addHiddenValue('address', $session->get('address'));
        $addParticipantForm->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
        $addParticipantForm->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);
        $addParticipantForm->addHiddenValue('returnPage', 'meeting_manage_preview.php');
        $addParticipantForm->addHiddenValue('type', 'Individual');

        $row = $addParticipantForm->addRow();
            $row->addLabel('gibbonPersonID', __('Add Participant'))->description(__('Adds a Specific Staff audience rule for the person(s) selected.'));
            $row->addSelectStaff('gibbonPersonID')->selectMultiple();

        $row = $addParticipantForm->addRow();
            $row->addSubmit(__('Add Participant'));

        echo $addParticipantForm->getOutput();
    }

    // ---------------------------------------------------------------
    // Occurrences (candidate dates from MeetingDateResolver)
    // ---------------------------------------------------------------

    echo '<h3>'.__('Occurrences').'</h3>';

    $dateResolver = $container->get(MeetingDateResolver::class);
    $candidates = $dateResolver->resolve($definition, $definition['timetableName'] ?? null);
    $diagnostic = $dateResolver->getScheduleDiagnostic($definition);

    if ($diagnostic) {
        // Distinguishes "no timetable ties configured yet" (a setup problem) from a legitimately
        // sparse/empty recurrence, so this doesn't read the same as "nothing scheduled".
        $page->addWarning($diagnostic['message']);
    }

    $willCreateCount = count(array_filter($candidates, function ($c) { return $c['willGenerate']; }));
    echo '<p>'.sprintf(__('%1$s of %2$s candidate dates will create an event.'), $willCreateCount, count($candidates)).'</p>';

    if (empty($candidates)) {
        if (!$diagnostic) {
            echo '<p><i>'.__('No candidate dates were resolved for this schedule.').'</i></p>';
        }
    } else {
        // Direct file paths, deliberately NOT routed via index.php?q= - these process scripts bootstrap
        // their own Gibbon environment with require_once '../../gibbon.php', which only resolves
        // correctly when the file is requested directly (so its own directory is the include base).
        $includeDateURL = $session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_preview_includeDateProcess.php';
        $excludeDateURL = $session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_preview_excludeDateProcess.php';

        echo '<div style="overflow-x:auto;"><table class="smallIntBorder w-full">';
        echo '<tr><th>'.__('Date').'</th><th>'.__('Day').'</th>';
        if ($definition['scheduleType'] === 'TimetableCycle') {
            echo '<th>'.__('Timetable Day').'</th>';
        }
        echo '<th>'.__('Time').'</th><th>'.__('Will Create').'</th><th>'.__('Context').'</th></tr>';

        foreach ($candidates as $candidate) {
            echo '<tr'.($candidate['willGenerate'] ? '' : ' class="error"').'>';
            echo '<td>'.Format::date($candidate['date']).'</td>';
            echo '<td>'.htmlspecialchars($candidate['dayOfWeek']).'</td>';
            if ($definition['scheduleType'] === 'TimetableCycle') {
                echo '<td>'.htmlspecialchars($candidate['tiedDayName'] ?? '').'</td>';
            }
            echo '<td>'.Format::time($candidate['timeStart']).'-'.Format::time($candidate['timeEnd']).'</td>';

            echo '<td class="text-center">';
            if (!empty($candidate['schoolClosure'])) {
                echo '<input type="checkbox" disabled title="'.__('School Closure cannot be overridden here.').'">';
            } else {
                echo '<form method="get" action="'.($candidate['willGenerate'] ? $excludeDateURL : $includeDateURL).'" style="display:inline;">';
                echo '<input type="hidden" name="date" value="'.$candidate['date'].'">';
                echo '<input type="hidden" name="meetingsManagerDefinitionID" value="'.$meetingsManagerDefinitionID.'">';
                echo '<input type="hidden" name="gibbonSchoolYearID" value="'.htmlspecialchars($gibbonSchoolYearID).'">';
                echo '<input type="checkbox" '.($candidate['willGenerate'] ? 'checked' : '').' onchange="'
                    .'var f=this.form;'
                    .'f.action=this.checked?&quot;'.$includeDateURL.'&quot;:&quot;'.$excludeDateURL.'&quot;;'
                    .'this.closest(&quot;tr&quot;).classList.toggle(&quot;error&quot;, !this.checked);'
                    .'f.submit();'
                    .'">';
                echo '</form>';
            }
            echo '</td>';

            $context = [];
            if ($candidate['reason']) {
                $context[] = htmlspecialchars($candidate['reason']);
            }
            if (!empty($candidate['offTimetable'])) {
                $groups = !empty($candidate['offTimetable']['affectedGroupNames']) ? implode(', ', $candidate['offTimetable']['affectedGroupNames']) : __('All');
                $context[] = sprintf(__('Off Timetable: %1$s (%2$s)'), htmlspecialchars($candidate['offTimetable']['name'] ?? ''), htmlspecialchars($groups));
            }
            if (!empty($candidate['timingChange'])) {
                $context[] = sprintf(__('Timing Change: %1$s'), htmlspecialchars($candidate['timingChange']['name'] ?? ''));
            }
            echo '<td>'.implode('<br/>', $context).'</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    // ---------------------------------------------------------------
    // What would happen (dry run) - only meaningful once at least one occurrence exists to compare
    // against; for a brand-new definition every desired date is simply "new", same as the count above.
    // ---------------------------------------------------------------

    $reconciler = $container->get(MeetingReconciler::class);
    $diffResult = $reconciler->diff((int) $meetingsManagerDefinitionID);

    if (array_sum($diffResult['counts']) > 0) {
        echo '<h3>'.__('What Update Generated Events Would Do').'</h3>';

        $labels = [
            'new' => __('New'), 'unchanged' => __('Unchanged'), 'updated' => __('Updated'),
            'removed' => __('Removed'), 'missingRecreated' => __('Missing, will be recreated'),
            'exceptionPreserved' => __('Exception preserved'), 'unchangedPast' => __('Past, untouched'),
        ];
        $parts = [];
        foreach ($diffResult['counts'] as $key => $count) {
            if ($count > 0) $parts[] = $count . ' ' . $labels[$key];
        }
        echo '<p>'.htmlspecialchars(implode(', ', $parts)).'</p>';

        if ($diffResult['participantsBefore'] !== null && $diffResult['participantsBefore'] !== $diffResult['participantsAfter']) {
            echo '<p><b>'.sprintf(__('Participants: %1$s &rarr; %2$s'), $diffResult['participantsBefore'], $diffResult['participantsAfter']).'</b></p>';
        }

        if ($diffResult['calendarStatus'] === 'will-be-created') {
            echo '<p><i>'.__('A "Meetings" calendar for this academic year does not exist yet and will be created automatically.').'</i></p>';
        }
        if ($diffResult['eventTypeStatus'] === 'will-be-resolved') {
            echo '<p><i>'.__('The "Meeting" Calendar event type has not been configured yet and will be resolved automatically (see Meetings Manager Settings).').'</i></p>';
        }
    }

    // ---------------------------------------------------------------
    // Status - a restrained operational snapshot, not a diagnostics subsystem
    // ---------------------------------------------------------------

    $occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
    $calendarEventService = $container->get(CalendarEventService::class);
    $existingOccurrences = $occurrenceGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();

    if (!empty($existingOccurrences)) {
        $generatedCount = 0;
        $missingCount = 0;
        foreach ($existingOccurrences as $occurrence) {
            if (empty($occurrence['gibbonCalendarEventID'])) continue;
            if ($calendarEventService->eventExists($occurrence['gibbonCalendarEventID'])) {
                $generatedCount++;
            } else {
                $missingCount++;
            }
        }

        echo '<h3>'.__('Status').'</h3>';
        echo '<table class="smallIntBorder w-full">';
        echo '<tr><td class="w-56"><b>'.__('Generated Calendar events').'</b></td><td>'.$generatedCount.'</td></tr>';
        if ($missingCount > 0) {
            echo '<tr><td><b>'.__('Missing Calendar events').'</b></td><td class="error">'.sprintf(__('%1$s - repaired automatically by Update Generated Events'), $missingCount).'</td></tr>';
        }
        echo '<tr><td><b>'.__('Meetings calendar').'</b></td><td>'.($diffResult['calendarStatus'] === 'exists' ? __('Configured') : __('Not yet created')).'</td></tr>';
        if (!empty($definition['timestampModified'])) {
            echo '<tr><td><b>'.__('Last Modified').'</b></td><td>'.Format::dateTime($definition['timestampModified']).'</td></tr>';
        }
        echo '</table>';
    }

    // ---------------------------------------------------------------
    // Create / Update Generated Events - the only action on this page that writes anything
    // ---------------------------------------------------------------

    if ($definition['active'] === 'Y') {
        $buttonLabel = empty($existingOccurrences) ? __('Create Meeting Series') : __('Update Generated Events');

        $generateForm = Form::create('generate', $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_generateProcess.php');
        $generateForm->addHiddenValue('address', $session->get('address'));
        $generateForm->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
        $generateForm->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);
        $generateForm->addRow()->addSubmit($buttonLabel);
        echo $generateForm->getOutput();
    }
}
