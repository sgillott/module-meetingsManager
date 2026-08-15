<?php
namespace Gibbon\Module\MeetingsManager;

use DateTime;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\School\DaysOfWeekGateway;
use Gibbon\Domain\School\SchoolYearGateway;
use Gibbon\Domain\School\SchoolYearSpecialDayGateway;
use Gibbon\Domain\School\SchoolYearTermGateway;
use Gibbon\Domain\Timetable\TimetableDayGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingSelectedDateGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingExcludedDateGateway;

/**
 * Meeting Date Resolver
 *
 * The sole implementation of scheduling/recurrence logic for Meetings Manager. Turns a Meeting
 * Definition's scheduleType and configuration into a list of candidate dates, each annotated through
 * the same school-calendar pipeline regardless of source. Both the Preview page and (in Phase 5)
 * Calendar generation consume this exact output - no duplicate date logic anywhere else.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingDateResolver
{
    private $db;
    private $selectedDateGateway;
    private $excludedDateGateway;
    private $timetableDayGateway;
    private $schoolYearGateway;
    private $schoolYearTermGateway;
    private $daysOfWeekGateway;
    private $specialDayGateway;

    public function __construct(
        Connection $db,
        MeetingSelectedDateGateway $selectedDateGateway,
        MeetingExcludedDateGateway $excludedDateGateway,
        TimetableDayGateway $timetableDayGateway,
        SchoolYearGateway $schoolYearGateway,
        SchoolYearTermGateway $schoolYearTermGateway,
        DaysOfWeekGateway $daysOfWeekGateway,
        SchoolYearSpecialDayGateway $specialDayGateway
    ) {
        $this->db = $db;
        $this->selectedDateGateway = $selectedDateGateway;
        $this->excludedDateGateway = $excludedDateGateway;
        $this->timetableDayGateway = $timetableDayGateway;
        $this->schoolYearGateway = $schoolYearGateway;
        $this->schoolYearTermGateway = $schoolYearTermGateway;
        $this->daysOfWeekGateway = $daysOfWeekGateway;
        $this->specialDayGateway = $specialDayGateway;
    }

    /**
     * @param array $definition  A meetingsManagerDefinition row (plus meetingsManagerDefinitionID)
     * @param array $timetableName  Optional label for TimetableCycle candidates, e.g. selected gibbonTT.name
     * @return array  List of annotated candidate rows - the Preview model.
     */
    public function resolve(array $definition, ?string $timetableName = null): array
    {
        [$rangeStart, $rangeEnd] = $this->resolveRange($definition);

        switch ($definition['scheduleType']) {
            case 'Single':
            case 'SelectedDates':
                $dates = $this->datesFromSelectedDates($definition['meetingsManagerDefinitionID']);
                break;
            case 'Weekly':
                $dates = $this->datesFromWeekly($definition['gibbonDaysOfWeekID'] ?? null, $rangeStart, $rangeEnd);
                break;
            case 'TimetableCycle':
                $dates = $this->datesFromTimetableCycle($definition['gibbonTTID'] ?? null, $definition['gibbonTTDayID'] ?? null, $rangeStart, $rangeEnd);
                break;
            default:
                $dates = [];
        }

        $excludedDates = $this->excludedDateGateway
            ->selectDatesByDefinition($definition['meetingsManagerDefinitionID'])
            ->fetchAll();
        $excludedDates = array_flip(array_column($excludedDates, 'date'));

        $candidates = [];
        foreach ($dates as $dateInfo) {
            $candidates[] = $this->annotate($dateInfo['date'], $definition, $dateInfo, $timetableName, $excludedDates);
        }

        usort($candidates, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $candidates;
    }

    /**
     * Distinguishes "this timetable genuinely has no dates tied to the selected day within this
     * range" (expected, e.g. the cycle position just doesn't recur that often) from "this timetable
     * has no ties configured at all yet" (a setup problem - the admin needs to visit Timetable Admin
     * > Tie Days to Dates before this Meeting Definition can produce anything). Returns null when
     * candidates already exist, or when scheduleType isn't TimetableCycle - i.e. only ever returns
     * something when resolve() would otherwise silently return an empty/sparse list with no
     * explanation of which of the two situations applies.
     */
    public function getScheduleDiagnostic(array $definition): ?array
    {
        if (($definition['scheduleType'] ?? null) !== 'TimetableCycle') {
            return null;
        }
        if (empty($definition['gibbonTTID']) || empty($definition['gibbonTTDayID'])) {
            return null;
        }

        [$rangeStart, $rangeEnd] = $this->resolveRange($definition);
        if (empty($rangeStart) || empty($rangeEnd)) {
            return null;
        }

        $rows = $this->timetableDayGateway->selectTTDaysByDateRange($definition['gibbonTTID'], $rangeStart, $rangeEnd)->fetchAll();

        if (empty($rows)) {
            return [
                'level' => 'warning',
                'message' => __('No timetable dates have been configured for this timetable within the selected range. Check Timetable Admin > Tie Days to Dates.'),
            ];
        }

        $hasMatchingDay = false;
        foreach ($rows as $row) {
            if ((int) $row['gibbonTTDayID'] === (int) $definition['gibbonTTDayID']) {
                $hasMatchingDay = true;
                break;
            }
        }

        if (!$hasMatchingDay) {
            return [
                'level' => 'warning',
                'message' => sprintf(
                    __('No dates tied to %1$s were found within the selected range, even though other days in this timetable are tied. Check Timetable Admin > Tie Days to Dates if this is unexpected.'),
                    $definition['tiedDayName'] ?? __('the selected timetable day')
                ),
            ];
        }

        return null;
    }

    /**
     * Resolves rangeStart/rangeEnd, falling back to the definition's school year bounds when unset.
     */
    private function resolveRange(array $definition): array
    {
        $rangeStart = $definition['rangeStart'] ?? null;
        $rangeEnd = $definition['rangeEnd'] ?? null;

        if (empty($rangeStart) || empty($rangeEnd)) {
            $schoolYear = $this->schoolYearGateway->getByID($definition['gibbonSchoolYearID']);
            $rangeStart = $rangeStart ?: ($schoolYear['firstDay'] ?? null);
            $rangeEnd = $rangeEnd ?: ($schoolYear['lastDay'] ?? null);
        }

        return [$rangeStart, $rangeEnd];
    }

    /**
     * Single = exactly 1 row, SelectedDates = 1 or more. Cardinality is validated by the calling page
     * (server-side) before save - this resolver just reads whatever rows exist.
     */
    private function datesFromSelectedDates($meetingsManagerDefinitionID): array
    {
        $rows = $this->selectedDateGateway->selectBy(['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID])->fetchAll();

        return array_map(function ($row) {
            return ['date' => $row['date']];
        }, $rows);
    }

    /**
     * Every date matching the selected weekday between the range bounds. This is plain weekly
     * (7-day-step) arithmetic, which is legitimate here - Weekly is defined as a calendar weekday
     * recurrence with no timetable-cycle dependency, unlike TimetableCycle below.
     */
    private function datesFromWeekly($gibbonDaysOfWeekID, $rangeStart, $rangeEnd): array
    {
        if (empty($gibbonDaysOfWeekID) || empty($rangeStart) || empty($rangeEnd)) {
            return [];
        }

        $day = $this->daysOfWeekGateway->getByID($gibbonDaysOfWeekID);
        if (empty($day)) {
            return [];
        }

        $dates = [];
        $cursor = new DateTime($rangeStart);
        $endDate = $rangeEnd;

        // Advance to the first matching weekday within range.
        while ($cursor->format('l') !== $day['name'] && $cursor->format('Y-m-d') <= $endDate) {
            $cursor->modify('+1 day');
        }

        while ($cursor->format('Y-m-d') <= $endDate) {
            $dates[] = ['date' => $cursor->format('Y-m-d')];
            $cursor->modify('+7 days');
        }

        return $dates;
    }

    /**
     * Resolves real dates from gibbonTTDayDate for the selected timetable + day, bounded by the
     * configured range. Never computed arithmetically - a date only becomes a candidate here if it
     * has an actual tie in the authoritative core table.
     */
    private function datesFromTimetableCycle($gibbonTTID, $gibbonTTDayID, $rangeStart, $rangeEnd): array
    {
        if (empty($gibbonTTID) || empty($gibbonTTDayID) || empty($rangeStart) || empty($rangeEnd)) {
            return [];
        }

        $rows = $this->timetableDayGateway->selectTTDaysByDateRange($gibbonTTID, $rangeStart, $rangeEnd)->fetchAll();

        $dates = [];
        foreach ($rows as $row) {
            // Compare numerically, not as exact strings - gibbonTTDayID may arrive zero-padded
            // (e.g. from a zerofill column round-trip) or plain (e.g. passed directly in code), and
            // both represent the same ID.
            if ((int) $row['gibbonTTDayID'] === (int) $gibbonTTDayID) {
                $dates[] = ['date' => $row['date'], 'tiedDayName' => $row['name']];
            }
        }

        return $dates;
    }

    /**
     * The common school-calendar annotation stage every candidate date passes through, regardless of
     * scheduleType. Keeps isSchoolOpen and willGenerate as separate fields per the approved design:
     * Weekly/TimetableCycle exclude on School Closure, Single/SelectedDates only warn.
     */
    private function annotate(string $date, array $definition, array $dateInfo, ?string $timetableName, array $excludedDates = []): array
    {
        $dayOfWeekRow = $this->daysOfWeekGateway->getDayOfWeekByDate($date);
        $dayOfWeekName = $dayOfWeekRow['name'] ?? date('l', strtotime($date));
        $isSchoolDay = ($dayOfWeekRow['schoolDay'] ?? 'N') === 'Y';

        $term = $this->schoolYearTermGateway->getCurrentTermByDate($date);
        $inTerm = !empty($term);

        $specialDays = $this->specialDayGateway->selectSpecialDaysByDateRange($date, $date)->fetchAll();
        $schoolClosure = null;
        $offTimetable = null;
        $timingChange = null;
        foreach ($specialDays as $specialDay) {
            if ($specialDay['type'] === 'School Closure' && $schoolClosure === null) $schoolClosure = $specialDay;
            if ($specialDay['type'] === 'Off Timetable' && $offTimetable === null) $offTimetable = $specialDay;
            if ($specialDay['type'] === 'Timing Change' && $timingChange === null) $timingChange = $specialDay;
        }

        if ($offTimetable !== null) {
            $offTimetable['affectedGroupNames'] = $this->resolveGroupNames($offTimetable['gibbonYearGroupIDList'] ?? '', $offTimetable['gibbonFormGroupIDList'] ?? '', $definition['gibbonSchoolYearID']);
        }

        $isSchoolOpen = $inTerm && $isSchoolDay && $schoolClosure === null;

        $isRecurring = in_array($definition['scheduleType'], ['Weekly', 'TimetableCycle'], true);

        if ($isRecurring) {
            $willGenerate = $isSchoolOpen;
            if ($willGenerate) {
                $status = __('Will Create');
                $reason = null;
            } elseif ($schoolClosure !== null) {
                $status = __('Excluded: School Closure');
                $reason = $schoolClosure['name'] ?? __('School Closure');
            } elseif (!$inTerm) {
                $status = __('Excluded: Outside School Year');
                $reason = __('This date does not fall within any school year term.');
            } else {
                $status = __('Excluded: Not a School Day');
                $reason = sprintf(__('%1$s is not configured as a school day.'), $dayOfWeekName);
            }
        } else {
            // Single / SelectedDates - deliberate human choice, always generated.
            $willGenerate = true;
            if ($isSchoolOpen) {
                $status = __('Will Create');
                $reason = null;
            } else {
                $status = __('Will Create: Warning');
                $reason = $schoolClosure !== null
                    ? sprintf(__('School is closed on this date (%1$s).'), $schoolClosure['name'] ?? __('School Closure'))
                    : __('School is not open on this date.');
            }
        }

        // A manual exclusion is a deliberate human veto and takes priority over every rule above,
        // regardless of scheduleType - including Single/SelectedDates, which otherwise always generate.
        $manuallyExcluded = isset($excludedDates[$date]);
        if ($manuallyExcluded) {
            $willGenerate = false;
            $status = __('Excluded: Manually Excluded');
            $reason = __('This date was manually excluded.');
        }

        return [
            'date' => $date,
            'dayOfWeek' => $dayOfWeekName,
            'gibbonTTID' => $definition['gibbonTTID'] ?? null,
            'timetableName' => $timetableName,
            'gibbonTTDayID' => $definition['gibbonTTDayID'] ?? null,
            'tiedDayName' => $dateInfo['tiedDayName'] ?? null,
            'timeStart' => $definition['timeStart'] ?? null,
            'timeEnd' => $definition['timeEnd'] ?? null,
            'isSchoolOpen' => $isSchoolOpen,
            'schoolClosure' => $schoolClosure,
            'offTimetable' => $offTimetable,
            'timingChange' => $timingChange,
            'manuallyExcluded' => $manuallyExcluded,
            'willGenerate' => $willGenerate,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Resolves the CSV gibbonYearGroupIDList/gibbonFormGroupIDList on an Off Timetable special day to
     * readable names, for Preview context. No existing core method does this generically - every
     * SchoolYearSpecialDayGateway method that reads these lists is student-enrolment-specific.
     *
     * gibbonYearGroup is a global, non-year-scoped table, so its names never need year filtering.
     * gibbonFormGroup IS year-scoped (gibbonSchoolYearID) - a special day's own gibbonFormGroupIDList
     * can carry stale IDs left over from a different year's editing, so this filters to the Meeting
     * Definition's own school year rather than showing every matching ID regardless of year.
     */
    private function resolveGroupNames(string $yearGroupIDList, string $formGroupIDList, $gibbonSchoolYearID): array
    {
        $names = [];

        if (trim($yearGroupIDList) !== '') {
            $rows = $this->db->select(
                "SELECT name FROM gibbonYearGroup WHERE FIND_IN_SET(gibbonYearGroupID, :list) ORDER BY sequenceNumber",
                ['list' => $yearGroupIDList]
            )->fetchAll(\PDO::FETCH_COLUMN, 0);
            $names = array_merge($names, $rows);
        }

        if (trim($formGroupIDList) !== '') {
            $rows = $this->db->select(
                "SELECT name FROM gibbonFormGroup WHERE FIND_IN_SET(gibbonFormGroupID, :list) AND gibbonSchoolYearID = :gibbonSchoolYearID ORDER BY name",
                ['list' => $formGroupIDList, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
            )->fetchAll(\PDO::FETCH_COLUMN, 0);
            $names = array_merge($names, $rows);
        }

        return $names;
    }
}
