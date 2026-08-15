<?php
namespace Gibbon\Module\MeetingsManager;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingExceptionGateway;

/**
 * Meeting Reconciler
 *
 * Compares a Meeting Definition's desired occurrences (MeetingDateResolver + AudienceResolver) with
 * its stored meetingsManagerOccurrence rows and applies the difference to native Calendar via
 * CalendarEventService - the only place that decides what gets created, updated, or removed.
 *
 * diff() and reconcile() share one pipeline: buildContext() resolves the desired state (read-only
 * on the diff path, self-healing on the write path), classify() turns that into a per-date decision
 * without writing anything, and reconcile() is the only place that actually applies those decisions.
 * A dry-run Preview calling diff() sees exactly the same classification reconcile() would act on.
 *
 * Ownership note: meetingsManagerOccurrence.gibbonCalendarEventID is the ONLY authoritative link
 * between an occurrence and its native Calendar event, everywhere in this class. gibbonCalendarEvent
 * .foreignTable/foreignTableID (set by CalendarEventService::createEvent()) are diagnostic provenance
 * only - native Calendar's own "Duplicate Event" bulk action blindly copies them onto unrelated new
 * events, so they must never be treated as proof of ownership on their own.
 *
 * Reconciliation is date-relative using each occurrence's EFFECTIVE start datetime (planned, unless
 * overridden by a Move/Retime exception), not just the date: an occurrence today at 16:00 is still
 * future at 10:00 and past at 17:00. Historical occurrences are never touched, regardless of whether
 * they're still "desired" by the current definition.
 *
 * Meeting Manager is authoritative for events it owns: reconciliation always overwrites an owned
 * future event to match the current Definition + any Exception, it never attempts to detect or merge
 * in manual native-Calendar edits.
 *
 * @version v0.2.00
 * @since   v0.1.00
 */
class MeetingReconciler
{
    private $db;
    private $definitionGateway;
    private $ruleGateway;
    private $occurrenceGateway;
    private $exceptionGateway;
    private $dateResolver;
    private $audienceResolver;
    private $calendarEventService;

    public function __construct(
        Connection $db,
        MeetingDefinitionGateway $definitionGateway,
        MeetingAudienceRuleGateway $ruleGateway,
        MeetingOccurrenceGateway $occurrenceGateway,
        MeetingExceptionGateway $exceptionGateway,
        MeetingDateResolver $dateResolver,
        AudienceResolver $audienceResolver,
        CalendarEventService $calendarEventService
    ) {
        $this->db = $db;
        $this->definitionGateway = $definitionGateway;
        $this->ruleGateway = $ruleGateway;
        $this->occurrenceGateway = $occurrenceGateway;
        $this->exceptionGateway = $exceptionGateway;
        $this->dateResolver = $dateResolver;
        $this->audienceResolver = $audienceResolver;
        $this->calendarEventService = $calendarEventService;
    }

    /**
     * Read-only dry run: exactly the classification reconcile() would act on, without writing
     * anything - never calls the self-healing calendar/event-type resolution, only their read-only
     * equivalents. Safe to call every time Preview loads.
     *
     * @return array{classifications:array,counts:array,participantsBefore:?int,participantsAfter:int,excludedByClosure:int,calendarStatus:string,eventTypeStatus:string}
     */
    public function diff(int $meetingsManagerDefinitionID): array
    {
        $context = $this->buildContext($meetingsManagerDefinitionID, false);
        $classifications = $this->classify($context);

        $counts = ['new' => 0, 'unchanged' => 0, 'updated' => 0, 'removed' => 0, 'missingRecreated' => 0, 'exceptionPreserved' => 0, 'unchangedPast' => 0];
        foreach ($classifications as $entry) {
            $counts[$entry['category']]++;
        }

        // "Before" (currentParticipantCount) counts every row actually on the event, which always
        // includes the organiser (added by syncParticipants() regardless of audience rules). "After"
        // must count on the same basis - the resolved audience alone under-counts by one whenever the
        // organiser isn't independently resolved into it - or every audience-unchanged definition
        // would misleadingly show a "before -> after" participant drop.
        $finalParticipantIDs = array_unique(array_merge($context['participantIDs'], [(int) $context['definition']['gibbonPersonIDOrganiser']]));

        return [
            'classifications' => $classifications,
            'counts' => $counts,
            'participantsBefore' => $this->currentParticipantCount($context),
            'participantsAfter' => count($finalParticipantIDs),
            'excludedByClosure' => $context['excludedByClosureCount'],
            'calendarStatus' => $context['calendar'] !== null ? 'exists' : 'will-be-created',
            'eventTypeStatus' => $context['eventTypeName'] !== null ? $context['eventTypeName'] : 'will-be-resolved',
        ];
    }

    /**
     * Generates/updates/removes native Calendar events so they match the Meeting Definition's
     * current schedule and audience. Safe to call repeatedly (idempotent) - an unchanged desired
     * occurrence with an existing, matching event is just re-synced, not duplicated. Consumes the
     * exact same classify() output diff() shows the user beforehand.
     *
     * @return array{created:int,updated:int,removed:int,unchangedPast:int,participants:int,calendarHealed:bool,excludedByClosure:int}
     */
    public function reconcile(int $meetingsManagerDefinitionID): array
    {
        $context = $this->buildContext($meetingsManagerDefinitionID, true);
        $classifications = $this->classify($context);

        $counts = [
            'created' => 0, 'updated' => 0, 'removed' => 0, 'unchangedPast' => 0,
            'participants' => count($context['participantIDs']),
            'calendarHealed' => $context['calendarHealed'],
            'excludedByClosure' => $context['excludedByClosureCount'],
        ];

        $this->db->beginTransaction();
        try {
            foreach ($classifications as $entry) {
                switch ($entry['category']) {
                    case 'new':
                        $this->createOccurrence($meetingsManagerDefinitionID, $context, $entry['date']);
                        $counts['created']++;
                        break;
                    case 'unchanged':
                    case 'updated':
                    case 'missingRecreated':
                    case 'exceptionPreserved':
                        $this->reconcileOccurrence($entry['existing'], $entry['exception'], $entry['effective'], $context);
                        $counts['updated']++;
                        break;
                    case 'removed':
                        $this->removeOccurrence($entry['existing']);
                        $counts['removed']++;
                        break;
                    case 'unchangedPast':
                        $counts['unchangedPast']++;
                        break;
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $counts;
    }

    /**
     * Removes every future occurrence of a definition (deleting their owned Calendar events) while
     * preserving history - the Calendar-side half of "Archive Meeting Series". The Definition,
     * Occurrence rows, and their history are left alone by this method; the caller is responsible
     * for setting active='N' on the Definition itself.
     *
     * @return array{removed:int,unchangedPast:int}
     */
    public function archiveDefinition(int $meetingsManagerDefinitionID): array
    {
        $now = date('Y-m-d H:i:s');
        $counts = ['removed' => 0, 'unchangedPast' => 0];

        $existingOccurrences = $this->occurrenceGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();

        $this->db->beginTransaction();
        try {
            foreach ($existingOccurrences as $existing) {
                $exception = $this->exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']])->fetch() ?: null;
                $effective = $this->effectiveSchedule($existing, $exception);

                if ($this->isPast($effective, $now)) {
                    $counts['unchangedPast']++;
                    continue;
                }

                $this->removeOccurrence($existing);
                $counts['removed']++;
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $counts;
    }

    /**
     * Re-resolves the audience and re-syncs participants on every future, already-generated event -
     * without touching dates, exceptions, or creating/removing occurrences. Adds newly eligible
     * people, removes people no longer in the audience, preserves organiser role semantics (via
     * CalendarEventService::syncParticipants), and never touches historical events.
     *
     * @return array{eventsRefreshed:int,participants:int}
     */
    public function refreshParticipants(int $meetingsManagerDefinitionID): array
    {
        $definition = $this->definitionGateway->getDefinitionDetailsByID($meetingsManagerDefinitionID);
        if (empty($definition)) {
            throw new \InvalidArgumentException('Unknown meetingsManagerDefinitionID.');
        }

        $rules = $this->ruleGateway->selectRulesByDefinition($meetingsManagerDefinitionID)->fetchAll();
        $resolvedAudience = $this->audienceResolver->resolve((int) $definition['gibbonSchoolYearID'], $rules);
        $participantIDs = array_keys($resolvedAudience);

        $existingOccurrences = $this->occurrenceGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();
        $now = date('Y-m-d H:i:s');
        $refreshed = 0;

        foreach ($existingOccurrences as $existing) {
            $exception = $this->exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']])->fetch() ?: null;
            $effective = $this->effectiveSchedule($existing, $exception);

            if ($this->isPast($effective, $now)) {
                continue;
            }
            if (empty($existing['gibbonCalendarEventID']) || !$this->calendarEventService->eventExists($existing['gibbonCalendarEventID'])) {
                continue;
            }

            $actingPersonID = $definition['gibbonPersonIDCreated'] ?? $definition['gibbonPersonIDOrganiser'];
            $this->calendarEventService->syncParticipants((int) $existing['gibbonCalendarEventID'], $participantIDs, $definition['gibbonPersonIDOrganiser'], $actingPersonID);
            $refreshed++;
        }

        return ['eventsRefreshed' => $refreshed, 'participants' => count($participantIDs)];
    }

    /**
     * Resolves everything both diff() and reconcile() need: the definition, its desired dates and
     * audience, and its currently stored occurrences. $allowWrite controls whether the
     * self-healing Calendar/EventType resolution is used (reconcile()) or their read-only
     * equivalents (diff()) - this is what keeps a dry-run genuinely read-only.
     */
    private function buildContext(int $meetingsManagerDefinitionID, bool $allowWrite): array
    {
        $definition = $this->definitionGateway->getDefinitionDetailsByID($meetingsManagerDefinitionID);
        if (empty($definition)) {
            throw new \InvalidArgumentException('Unknown meetingsManagerDefinitionID.');
        }

        $calendarHealed = false;
        if ($allowWrite) {
            $calendar = $this->calendarEventService->getOrCreateMeetingsCalendar((int) $definition['gibbonSchoolYearID']);
            $calendarHealed = !empty($calendar['meetingsManagerHealed']);
            $eventTypeID = $this->calendarEventService->getEventTypeID();
            $eventTypeName = null;
        } else {
            $calendar = $this->calendarEventService->getMeetingsCalendarIfExists((int) $definition['gibbonSchoolYearID']);
            $eventTypeID = null;
            $eventTypeName = $this->calendarEventService->peekEventTypeName();
        }

        $rules = $this->ruleGateway->selectRulesByDefinition($meetingsManagerDefinitionID)->fetchAll();
        $resolvedAudience = $this->audienceResolver->resolve((int) $definition['gibbonSchoolYearID'], $rules);
        $participantIDs = array_keys($resolvedAudience);

        $candidates = $this->dateResolver->resolve($definition, $definition['timetableName'] ?? null);
        $desiredDates = [];
        $excludedByClosureCount = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['willGenerate']) {
                $desiredDates[$candidate['date']] = $candidate;
            } elseif (!empty($candidate['schoolClosure'])) {
                $excludedByClosureCount++;
            }
        }

        $existingOccurrences = $this->occurrenceGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();
        $existingByDate = [];
        foreach ($existingOccurrences as $occurrence) {
            $existingByDate[$occurrence['plannedDate']] = $occurrence;
        }

        return [
            'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
            'definition' => $definition,
            'calendar' => $calendar,
            'calendarHealed' => $calendarHealed,
            'eventTypeID' => $eventTypeID,
            'eventTypeName' => $eventTypeName,
            'participantIDs' => $participantIDs,
            'desiredDates' => $desiredDates,
            'existingByDate' => $existingByDate,
            'excludedByClosureCount' => $excludedByClosureCount,
        ];
    }

    /**
     * The single source of truth for what should happen to every date, desired or stored - both
     * diff() (read-only reporting) and reconcile() (applies these decisions) call this and nothing
     * else decides. Never writes; eventExists()/getEvent() calls are reads only.
     */
    private function classify(array $context): array
    {
        $now = date('Y-m-d H:i:s');
        $existingByDate = $context['existingByDate'];
        $results = [];

        foreach ($context['desiredDates'] as $date => $candidate) {
            $existing = $existingByDate[$date] ?? null;
            unset($existingByDate[$date]);

            if ($existing === null) {
                $results[] = ['date' => $date, 'category' => 'new', 'existing' => null, 'exception' => null, 'effective' => null];
                continue;
            }

            $exception = $this->exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']])->fetch() ?: null;
            $effective = $this->effectiveSchedule($existing, $exception);

            if ($this->isPast($effective, $now)) {
                $results[] = ['date' => $date, 'category' => 'unchangedPast', 'existing' => $existing, 'exception' => $exception, 'effective' => $effective];
                continue;
            }

            $eventMissing = empty($existing['gibbonCalendarEventID']) || !$this->calendarEventService->eventExists($existing['gibbonCalendarEventID']);

            if ($eventMissing) {
                $category = 'missingRecreated';
            } elseif ($exception) {
                $category = 'exceptionPreserved';
            } elseif ($this->eventWouldChange($existing, $effective, $context)) {
                $category = 'updated';
            } else {
                $category = 'unchanged';
            }

            $results[] = ['date' => $date, 'category' => $category, 'existing' => $existing, 'exception' => $exception, 'effective' => $effective];
        }

        foreach ($existingByDate as $existing) {
            $exception = $this->exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']])->fetch() ?: null;
            $effective = $this->effectiveSchedule($existing, $exception);

            $category = $this->isPast($effective, $now) ? 'unchangedPast' : 'removed';
            $results[] = ['date' => $existing['plannedDate'], 'category' => $category, 'existing' => $existing, 'exception' => $exception, 'effective' => $effective];
        }

        return $results;
    }

    /**
     * Cheap "would this actually change" check for the dry-run's unchanged-vs-updated split. Not
     * exhaustive (doesn't diff participants row-by-row - the before/after participant COUNT already
     * surfaces audience changes), but catches the common cases: a renamed/relocated/retimed
     * definition. reconcile() re-writes "unchanged" occurrences anyway (cheap and safe), this only
     * affects how diff() labels them for the user.
     */
    private function eventWouldChange(array $existing, array $effective, array $context): bool
    {
        $event = $this->calendarEventService->getEvent((int) $existing['gibbonCalendarEventID']);
        if ($event === null) {
            return true;
        }

        $definition = $context['definition'];
        return $event['name'] !== $definition['name']
            || $event['dateStart'] !== $effective['date']
            || $event['timeStart'] !== $effective['timeStart']
            || $event['timeEnd'] !== $effective['timeEnd']
            || (string) $event['locationDetail'] !== (string) ($definition['location'] ?? '')
            || (int) $event['gibbonPersonIDOrganiser'] !== (int) $definition['gibbonPersonIDOrganiser'];
    }

    /**
     * "Before" participant count for the dry-run's "Participants: 18 -> 27" comparison - taken from
     * the first future, still-existing generated event found (all future events should carry the
     * same audience normally, so any one of them is representative).
     */
    private function currentParticipantCount(array $context): ?int
    {
        $now = date('Y-m-d H:i:s');

        foreach ($context['existingByDate'] as $existing) {
            $exception = $this->exceptionGateway->selectBy(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']])->fetch() ?: null;
            $effective = $this->effectiveSchedule($existing, $exception);

            if ($this->isPast($effective, $now) || empty($existing['gibbonCalendarEventID'])) {
                continue;
            }

            $event = $this->calendarEventService->getEvent((int) $existing['gibbonCalendarEventID']);
            if ($event === null) {
                continue;
            }

            return $this->calendarEventService->countParticipants((int) $existing['gibbonCalendarEventID']);
        }

        return null; // nothing generated yet - no "before" to show
    }

    private function createOccurrence(int $meetingsManagerDefinitionID, array $context, string $date): void
    {
        $definition = $context['definition'];
        $now = date('Y-m-d H:i:s');

        $occurrenceID = $this->occurrenceGateway->insert([
            'meetingsManagerDefinitionID' => $meetingsManagerDefinitionID,
            'plannedDate'                 => $date,
            'plannedTimeStart'            => $definition['timeStart'],
            'plannedTimeEnd'              => $definition['timeEnd'],
            'status'                      => 'Planned',
            'timestampCreated'            => $now,
            'timestampModified'           => $now,
        ]);

        $gibbonCalendarEventID = $this->calendarEventService->createEvent(
            $definition,
            ['date' => $date, 'timeStart' => $definition['timeStart'], 'timeEnd' => $definition['timeEnd']],
            $context['participantIDs'],
            $context['calendar']['gibbonCalendarID'],
            $context['eventTypeID'],
            $occurrenceID
        );

        $this->occurrenceGateway->update($occurrenceID, [
            'gibbonCalendarEventID' => $gibbonCalendarEventID,
            'status' => 'Generated',
            'timestampModified' => $now,
        ]);
    }

    private function reconcileOccurrence(array $existing, ?array $exception, array $effective, array $context): void
    {
        $definition = $context['definition'];
        $schedule = ['date' => $effective['date'], 'timeStart' => $effective['timeStart'], 'timeEnd' => $effective['timeEnd']];
        $status = ($exception && $exception['type'] === 'Cancel') ? 'Cancelled' : 'Confirmed';
        $occurrenceStatus = $exception
            ? ($exception['type'] === 'Cancel' ? 'Cancelled' : ($exception['type'] === 'Move' ? 'Moved' : 'Generated'))
            : 'Generated';

        $eventMissing = empty($existing['gibbonCalendarEventID']) || !$this->calendarEventService->eventExists($existing['gibbonCalendarEventID']);

        if ($eventMissing) {
            $gibbonCalendarEventID = $this->calendarEventService->createEvent($definition, $schedule, $context['participantIDs'], $context['calendar']['gibbonCalendarID'], $context['eventTypeID'], $existing['meetingsManagerOccurrenceID']);
        } else {
            $gibbonCalendarEventID = (int) $existing['gibbonCalendarEventID'];
            $this->calendarEventService->updateEvent($gibbonCalendarEventID, $definition, $schedule, $context['participantIDs']);
        }

        // Meeting Manager is authoritative: status always reflects the current exception state,
        // overwriting any manual native-Calendar change (e.g. a status hand-edited via Calendar).
        $this->calendarEventService->setEventStatus($gibbonCalendarEventID, $status);

        $this->occurrenceGateway->update($existing['meetingsManagerOccurrenceID'], [
            'gibbonCalendarEventID' => $gibbonCalendarEventID,
            'status' => $occurrenceStatus,
            'timestampModified' => date('Y-m-d H:i:s'),
        ]);
    }

    private function removeOccurrence(array $existing): void
    {
        if (!empty($existing['gibbonCalendarEventID']) && $this->calendarEventService->eventExists($existing['gibbonCalendarEventID'])) {
            $this->calendarEventService->deleteEvent((int) $existing['gibbonCalendarEventID']);
        }

        $this->exceptionGateway->deleteWhere(['meetingsManagerOccurrenceID' => $existing['meetingsManagerOccurrenceID']]);
        $this->occurrenceGateway->delete($existing['meetingsManagerOccurrenceID']);
    }

    /**
     * The planned date/time is the recurrence anchor and is never mutated by an exception - Move and
     * Retime exceptions supply the effective values used for the actual Calendar event, while
     * meetingsManagerOccurrence.plannedDate/plannedTimeStart/plannedTimeEnd keep recording what the
     * recurrence rule itself would produce. This is what lets future reconciliation still recognise
     * which desired occurrence an exception belongs to.
     */
    private function effectiveSchedule(array $occurrence, ?array $exception): array
    {
        $date = $occurrence['plannedDate'];
        $timeStart = $occurrence['plannedTimeStart'];
        $timeEnd = $occurrence['plannedTimeEnd'];

        if ($exception && $exception['type'] === 'Move' && !empty($exception['newDate'])) {
            $date = $exception['newDate'];
        }
        if ($exception && in_array($exception['type'], ['Move', 'Retime'], true)) {
            if (!empty($exception['newTimeStart'])) $timeStart = $exception['newTimeStart'];
            if (!empty($exception['newTimeEnd'])) $timeEnd = $exception['newTimeEnd'];
        }

        return ['date' => $date, 'timeStart' => $timeStart, 'timeEnd' => $timeEnd];
    }

    private function isPast(array $effective, string $now): bool
    {
        return ($effective['date'] . ' ' . $effective['timeStart']) <= $now;
    }
}
