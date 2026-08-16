<?php
namespace Gibbon\Module\MeetingsManager;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\Calendar\CalendarGateway;
use Gibbon\Domain\Calendar\CalendarEventTypeGateway;
use Gibbon\Domain\Calendar\CalendarEventGateway;
use Gibbon\Domain\Calendar\CalendarEventPersonGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingCalendarGateway;

/**
 * Calendar Event Service
 *
 * The only place in Meetings Manager that writes to native gibbonCalendar/gibbonCalendarEvent/
 * gibbonCalendarEventPerson. Owns two responsibilities: resolving what the module writes TO
 * (the year's Meetings calendar, the Meeting event type), and maintaining the organiser invariant
 * whenever participants are written.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class CalendarEventService
{
    private $db;
    private $settingGateway;
    private $calendarGateway;
    private $calendarEventTypeGateway;
    private $calendarEventGateway;
    private $calendarEventPersonGateway;
    private $meetingCalendarGateway;

    public function __construct(
        Connection $db,
        SettingGateway $settingGateway,
        CalendarGateway $calendarGateway,
        CalendarEventTypeGateway $calendarEventTypeGateway,
        CalendarEventGateway $calendarEventGateway,
        CalendarEventPersonGateway $calendarEventPersonGateway,
        MeetingCalendarGateway $meetingCalendarGateway
    ) {
        $this->db = $db;
        $this->settingGateway = $settingGateway;
        $this->calendarGateway = $calendarGateway;
        $this->calendarEventTypeGateway = $calendarEventTypeGateway;
        $this->calendarEventGateway = $calendarEventGateway;
        $this->calendarEventPersonGateway = $calendarEventPersonGateway;
        $this->meetingCalendarGateway = $meetingCalendarGateway;
    }

    /**
     * Resolves the year-scoped "Meetings" calendar via the meetingsManagerCalendar mapping,
     * creating both the native gibbonCalendar row and the mapping if none exists yet, and
     * self-healing if the previously-mapped calendar has been deleted outside the module.
     * The calendar stays participant-only and non-editable by ordinary staff.
     *
     * Sets $healed=true on the returned array's synthetic 'meetingsManagerHealed' key when this
     * call actually created or repaired something, so a write-path caller (never a dry-run) can
     * surface that to the user rather than it happening invisibly - see getMeetingsCalendarIfExists()
     * for the read-only equivalent a dry-run should use instead.
     */
    public function getOrCreateMeetingsCalendar(int $gibbonSchoolYearID): array
    {
        $mapping = $this->meetingCalendarGateway->selectBy(['gibbonSchoolYearID' => $gibbonSchoolYearID])->fetch();

        if (!empty($mapping)) {
            $calendar = $this->calendarGateway->getByID($mapping['gibbonCalendarID']);
            if (!empty($calendar)) {
                $calendar['meetingsManagerHealed'] = false;
                return $calendar;
            }
            // Mapped calendar no longer resolves (deleted via native Calendar admin) - fall through
            // and recreate, then repair the mapping in place.
        }

        $gibbonCalendarID = $this->calendarGateway->insert([
            'gibbonSchoolYearID'      => $gibbonSchoolYearID,
            'name'                    => 'Meetings',
            // gibbonCalendar.color is nullable at the DB level, but core's timetable rendering
            // (AbstractTimetableLayer::getColor(): string) fatal-errors on a null value the moment
            // any user views a timetable that includes this calendar - so a value is mandatory here
            // even though the column itself would silently accept NULL.
            'color'                   => '#c4b5fd',
            'public'                  => 'N',
            'viewableStaff'           => 'N',
            'viewableStudents'        => 'N',
            'viewableParents'         => 'N',
            'viewableOther'           => 'N',
            'viewableParticipants'    => 'Y',
            'editableStaff'           => 'N',
            'sequenceNumber'          => 0,
        ]);

        if (!empty($mapping)) {
            $this->meetingCalendarGateway->update($mapping['meetingsManagerCalendarID'], ['gibbonCalendarID' => $gibbonCalendarID]);
        } else {
            $this->meetingCalendarGateway->insert([
                'gibbonSchoolYearID' => $gibbonSchoolYearID,
                'gibbonCalendarID'   => $gibbonCalendarID,
                'timestampCreated'   => date('Y-m-d H:i:s'),
            ]);
        }

        $calendar = $this->calendarGateway->getByID($gibbonCalendarID);
        $calendar['meetingsManagerHealed'] = true;
        return $calendar;
    }

    /**
     * Read-only equivalent of getOrCreateMeetingsCalendar() - looks at the mapping without ever
     * creating or repairing anything. Returns null if no calendar is mapped yet, or if the mapped
     * calendar no longer resolves. Exists specifically so a dry-run Preview can show accurate
     * "will self-heal on generation" status without itself writing anything.
     */
    public function getMeetingsCalendarIfExists(int $gibbonSchoolYearID): ?array
    {
        $mapping = $this->meetingCalendarGateway->selectBy(['gibbonSchoolYearID' => $gibbonSchoolYearID])->fetch();
        if (empty($mapping)) {
            return null;
        }

        $calendar = $this->calendarGateway->getByID($mapping['gibbonCalendarID']);
        return !empty($calendar) ? $calendar : null;
    }

    /**
     * Resolves the cached gibbonCalendarEventTypeID module setting, validating it still exists and
     * re-resolving (by name, creating "Meeting" if genuinely missing) only if it doesn't.
     */
    public function getEventTypeID(): int
    {
        $cachedID = $this->settingGateway->getSettingByScope('Meetings Manager', 'gibbonCalendarEventTypeID');

        if (!empty($cachedID) && $this->calendarEventTypeGateway->exists($cachedID)) {
            return (int) $cachedID;
        }

        $types = $this->calendarEventTypeGateway->selectAllEventTypes()->fetchAll();
        $meetingType = null;
        foreach ($types as $type) {
            if ($type['type'] === 'Meeting') {
                $meetingType = $type;
                break;
            }
        }

        $resolvedID = !empty($meetingType)
            ? $meetingType['value']
            : $this->calendarEventTypeGateway->insert(['type' => 'Meeting', 'color' => '', 'sequenceNumber' => 0]);

        $this->settingGateway->updateSettingByScope('Meetings Manager', 'gibbonCalendarEventTypeID', $resolvedID);

        return (int) $resolvedID;
    }

    /**
     * Read-only equivalent of getEventTypeID() - returns the cached type's name if it's still valid,
     * or null if generation would need to re-resolve/create it. Never writes. Used by dry-run Preview.
     */
    public function peekEventTypeName(): ?string
    {
        $cachedID = $this->settingGateway->getSettingByScope('Meetings Manager', 'gibbonCalendarEventTypeID');
        if (empty($cachedID)) {
            return null;
        }

        $type = $this->calendarEventTypeGateway->getByID($cachedID);
        return !empty($type) ? $type['type'] : null;
    }

    /**
     * Creates a new gibbonCalendarEvent for one occurrence and syncs its participants. The caller
     * (MeetingReconciler) owns the meetingsManagerOccurrence row and passes its ID so the event can
     * be linked back via foreignTable/foreignTableID as a secondary, best-effort ownership marker -
     * meetingsManagerOccurrence.gibbonCalendarEventID remains the authoritative link.
     */
    public function createEvent(array $definition, array $schedule, array $participantIDs, int $gibbonCalendarID, int $gibbonCalendarEventTypeID, int $meetingsManagerOccurrenceID): int
    {
        $now = date('Y-m-d H:i:s');
        $actingPersonID = $definition['gibbonPersonIDCreated'] ?? $definition['gibbonPersonIDOrganiser'];

        $data = [
            'gibbonCalendarID'          => $gibbonCalendarID,
            'gibbonCalendarEventTypeID' => $gibbonCalendarEventTypeID,
            'name'                      => $definition['name'],
            'description'               => $definition['description'] ?? '',
            'status'                    => 'Confirmed',
            'allDay'                    => 'N',
            'dateStart'                 => $schedule['date'],
            'dateEnd'                   => $schedule['date'],
            'timeStart'                 => $schedule['timeStart'],
            'timeEnd'                   => $schedule['timeEnd'],
            'locationType'              => $definition['locationType'] ?? 'External',
            'gibbonSpaceID'             => $definition['gibbonSpaceID'] ?? null,
            'locationDetail'            => $definition['locationDetail'] ?? '',
            'locationURL'               => '',
            // Diagnostic provenance only - NOT proof of ownership. Native Calendar's "Duplicate
            // Event" bulk action (calendar_event_manageProcessBulk.php) blindly copies these two
            // columns onto the new, unrelated duplicate event, so foreignTable/foreignTableID alone
            // can lie about ownership. The one authoritative link is always
            // meetingsManagerOccurrence.gibbonCalendarEventID - see MeetingReconciler, which never
            // trusts these two columns for anything beyond a "how did this event get here" hint.
            'foreignTable'              => 'meetingsManagerOccurrence',
            'foreignTableID'            => $meetingsManagerOccurrenceID,
            'timestampCreated'          => $now,
            'timestampModified'         => $now,
            'gibbonPersonIDCreated'     => $actingPersonID,
            'gibbonPersonIDModified'    => $actingPersonID,
            'gibbonPersonIDOrganiser'   => $definition['gibbonPersonIDOrganiser'],
        ];

        $gibbonCalendarEventID = $this->calendarEventGateway->insert($data);

        $this->syncParticipants($gibbonCalendarEventID, $participantIDs, $definition['gibbonPersonIDOrganiser'], $actingPersonID);

        return (int) $gibbonCalendarEventID;
    }

    /**
     * Updates an existing owned event's core fields and re-syncs its participants. Meeting Manager
     * is authoritative for events it owns - this always overwrites, it never attempts to merge in
     * manual native-Calendar changes.
     */
    public function updateEvent(int $gibbonCalendarEventID, array $definition, array $schedule, array $participantIDs): bool
    {
        $actingPersonID = $definition['gibbonPersonIDCreated'] ?? $definition['gibbonPersonIDOrganiser'];

        $data = [
            'name'                    => $definition['name'],
            'description'             => $definition['description'] ?? '',
            'dateStart'               => $schedule['date'],
            'dateEnd'                 => $schedule['date'],
            'timeStart'               => $schedule['timeStart'],
            'timeEnd'                 => $schedule['timeEnd'],
            'locationType'            => $definition['locationType'] ?? 'External',
            'gibbonSpaceID'           => $definition['gibbonSpaceID'] ?? null,
            'locationDetail'          => $definition['locationDetail'] ?? '',
            'gibbonPersonIDOrganiser' => $definition['gibbonPersonIDOrganiser'],
            'gibbonPersonIDModified'  => $actingPersonID,
            'timestampModified'       => date('Y-m-d H:i:s'),
        ];

        $success = $this->calendarEventGateway->update($gibbonCalendarEventID, $data);

        $this->syncParticipants($gibbonCalendarEventID, $participantIDs, $definition['gibbonPersonIDOrganiser'], $actingPersonID);

        return $success;
    }

    public function setEventStatus(int $gibbonCalendarEventID, string $status): bool
    {
        return $this->calendarEventGateway->update($gibbonCalendarEventID, [
            'status' => $status,
            'timestampModified' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Deletes an owned event and its participant rows. Callers must have already confirmed
     * ownership (via meetingsManagerOccurrence.gibbonCalendarEventID) before calling this - this
     * service never decides on its own which events belong to Meeting Manager.
     */
    public function deleteEvent(int $gibbonCalendarEventID): bool
    {
        $this->calendarEventPersonGateway->deleteWhere(['gibbonCalendarEventID' => $gibbonCalendarEventID]);

        return $this->calendarEventGateway->delete($gibbonCalendarEventID);
    }

    public function eventExists(int $gibbonCalendarEventID): bool
    {
        return $this->calendarEventGateway->exists($gibbonCalendarEventID);
    }

    /**
     * Read-only fetch of an owned event's current core fields, for the dry-run diff's
     * unchanged-vs-updated comparison. Returns null if the event no longer exists (the "missing,
     * would be recreated" case, handled separately via eventExists()/missing detection).
     */
    public function getEvent(int $gibbonCalendarEventID): ?array
    {
        $event = $this->calendarEventGateway->getByID($gibbonCalendarEventID);
        return !empty($event) ? $event : null;
    }

    public function countParticipants(int $gibbonCalendarEventID): int
    {
        return $this->calendarEventPersonGateway->selectBy(['gibbonCalendarEventID' => $gibbonCalendarEventID])->rowCount();
    }

    /**
     * The organiser invariant, maintained as a single diff/sync operation rather than separate
     * add/remove logic: builds the desired {personID: role} map (everyone in $participantIDs as
     * Attendee, the organiser always as Organiser regardless of whether AudienceResolver also
     * returned them), removes any existing gibbonCalendarEventPerson row not in that map, and
     * upserts every row that should exist. This one method is what correctly handles all three
     * organiser-change cases: the old organiser still in the audience is downgraded to Attendee (its
     * row is upserted with the new role rather than removed), the old organiser no longer in the
     * audience is removed entirely (absent from the target map), and the new organiser always ends
     * up with exactly one Organiser row (upserted, respecting the UNIQUE(event, person) constraint on
     * gibbonCalendarEventPerson - a person can only ever have one role on one event).
     */
    public function syncParticipants(int $gibbonCalendarEventID, array $participantIDs, $organiserID, int $actingPersonID): void
    {
        $organiserID = (int) $organiserID;

        $targetRoles = [];
        foreach ($participantIDs as $id) {
            $targetRoles[(int) $id] = 'Attendee';
        }
        $targetRoles[$organiserID] = 'Organiser';

        $existingRows = $this->calendarEventPersonGateway->selectBy(['gibbonCalendarEventID' => $gibbonCalendarEventID])->fetchAll();
        $existingByPerson = [];
        foreach ($existingRows as $row) {
            $existingByPerson[(int) $row['gibbonPersonID']] = $row;
        }

        foreach ($existingByPerson as $personID => $row) {
            if (!isset($targetRoles[$personID])) {
                $this->calendarEventPersonGateway->delete($row['gibbonCalendarEventPersonID']);
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($targetRoles as $personID => $role) {
            $data = [
                'gibbonCalendarEventID'   => $gibbonCalendarEventID,
                'gibbonPersonID'          => $personID,
                'role'                    => $role,
                'timestampCreated'        => $now,
                'timestampModified'       => $now,
                'gibbonPersonIDCreated'   => $actingPersonID,
                'gibbonPersonIDModified'  => $actingPersonID,
            ];
            $this->calendarEventPersonGateway->insertAndUpdate($data, [
                'role' => $role,
                'timestampModified' => $now,
                'gibbonPersonIDModified' => $actingPersonID,
            ]);
        }
    }
}
