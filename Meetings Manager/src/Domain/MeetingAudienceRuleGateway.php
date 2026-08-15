<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Audience Rule Gateway
 *
 * One row per audience rule on a Meeting Definition. Kept as dynamic rules rather than a resolved
 * person list, so participants can be re-resolved against current Gibbon staff/department data.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingAudienceRuleGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerAudienceRule';
    private static $primaryKey = 'meetingsManagerAudienceRuleID';

    /**
     * Audience rules for a definition, with their target's display name joined so callers
     * (AudienceResolver::describeRule(), the Manage Audience Rules list) never need a second lookup.
     */
    public function selectRulesByDefinition($meetingsManagerDefinitionID)
    {
        $sql = "SELECT meetingsManagerAudienceRule.*,
                gibbonYearGroup.name AS yearGroupName,
                gibbonDepartment.name AS departmentName,
                gibbonPerson.title AS personTitle,
                gibbonPerson.preferredName AS personPreferredName,
                gibbonPerson.surname AS personSurname
                FROM meetingsManagerAudienceRule
                LEFT JOIN gibbonYearGroup ON (gibbonYearGroup.gibbonYearGroupID = meetingsManagerAudienceRule.gibbonYearGroupID)
                LEFT JOIN gibbonDepartment ON (gibbonDepartment.gibbonDepartmentID = meetingsManagerAudienceRule.gibbonDepartmentID)
                LEFT JOIN gibbonPerson ON (gibbonPerson.gibbonPersonID = meetingsManagerAudienceRule.gibbonPersonID)
                WHERE meetingsManagerAudienceRule.meetingsManagerDefinitionID = :meetingsManagerDefinitionID
                ORDER BY meetingsManagerAudienceRule.meetingsManagerAudienceRuleID";

        return $this->db()->select($sql, ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
    }
}
