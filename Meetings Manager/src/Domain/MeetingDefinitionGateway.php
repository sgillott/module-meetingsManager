<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Definition Gateway
 *
 * The user-configured meeting series: name, organiser, schedule rule, and status. Owns nothing from
 * native Calendar directly - the calendar and event type used for generated events are resolved via
 * MeetingCalendarGateway and the gibbonCalendarEventTypeID module setting.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingDefinitionGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerDefinition';
    private static $primaryKey = 'meetingsManagerDefinitionID';
    private static $searchableColumns = ['meetingsManagerDefinition.name'];

    private static $enrichedCols = "meetingsManagerDefinition.*,
                CONCAT(organiser.preferredName, ' ', organiser.surname) AS organiserName,
                organiser.status AS organiserStatus,
                gibbonDaysOfWeek.name AS dayOfWeekName,
                gibbonTT.name AS timetableName,
                gibbonTTDay.name AS tiedDayName,
                gibbonSpace.name AS spaceName,
                (SELECT date FROM meetingsManagerSelectedDate WHERE meetingsManagerSelectedDate.meetingsManagerDefinitionID = meetingsManagerDefinition.meetingsManagerDefinitionID ORDER BY date LIMIT 1) AS singleDate";

    private static $enrichedJoins = "LEFT JOIN gibbonPerson AS organiser ON (organiser.gibbonPersonID = meetingsManagerDefinition.gibbonPersonIDOrganiser)
                LEFT JOIN gibbonDaysOfWeek ON (gibbonDaysOfWeek.gibbonDaysOfWeekID = meetingsManagerDefinition.gibbonDaysOfWeekID)
                LEFT JOIN gibbonTT ON (gibbonTT.gibbonTTID = meetingsManagerDefinition.gibbonTTID)
                LEFT JOIN gibbonTTDay ON (gibbonTTDay.gibbonTTDayID = meetingsManagerDefinition.gibbonTTDayID)
                LEFT JOIN gibbonSpace ON (gibbonSpace.gibbonSpaceID = meetingsManagerDefinition.gibbonSpaceID)";

    /**
     * Meeting Definitions for a school year, with organiser/schedule display fields joined and
     * cheap child-row counts for the Manage Meetings list. No person-resolution here - that's
     * Preview's job via AudienceResolver, kept off the list page for speed.
     *
     * $gibbonPersonIDOrganiser, when provided, restricts the list to definitions organised by that
     * person - used when the current session only holds Manage Meetings_my (see
     * moduleFunctions.php's meetingsManagerScopeToSelf()). Same shape as core Behaviour's own
     * queryBehaviourBySchoolYear($criteria, $schoolYearID, $gibbonPersonIDCreator = null).
     */
    public function selectDefinitionsBySchoolYear($gibbonSchoolYearID, $active = 'Y', $gibbonPersonIDOrganiser = null)
    {
        $cols = static::$enrichedCols;
        $joins = static::$enrichedJoins;

        $sql = "SELECT {$cols},
                (SELECT COUNT(*) FROM meetingsManagerAudienceRule WHERE meetingsManagerAudienceRule.meetingsManagerDefinitionID = meetingsManagerDefinition.meetingsManagerDefinitionID) AS audienceRuleCount,
                (SELECT COUNT(*) FROM meetingsManagerSelectedDate WHERE meetingsManagerSelectedDate.meetingsManagerDefinitionID = meetingsManagerDefinition.meetingsManagerDefinitionID) AS selectedDateCount
                FROM meetingsManagerDefinition
                {$joins}
                WHERE meetingsManagerDefinition.gibbonSchoolYearID = :gibbonSchoolYearID
                AND meetingsManagerDefinition.active = :active";

        $data = ['gibbonSchoolYearID' => $gibbonSchoolYearID, 'active' => $active];

        if (!empty($gibbonPersonIDOrganiser)) {
            $sql .= " AND meetingsManagerDefinition.gibbonPersonIDOrganiser = :gibbonPersonIDOrganiser";
            $data['gibbonPersonIDOrganiser'] = $gibbonPersonIDOrganiser;
        }

        $sql .= " ORDER BY meetingsManagerDefinition.name";

        return $this->db()->select($sql, $data);
    }

    /**
     * A single Meeting Definition with the same display fields joined, for Edit/Preview pages.
     */
    public function getDefinitionDetailsByID($meetingsManagerDefinitionID)
    {
        $cols = static::$enrichedCols;
        $joins = static::$enrichedJoins;

        $sql = "SELECT {$cols}
                FROM meetingsManagerDefinition
                {$joins}
                WHERE meetingsManagerDefinition.meetingsManagerDefinitionID = :meetingsManagerDefinitionID";

        return $this->db()->selectOne($sql, ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
    }
}
