<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Selected Date Gateway
 *
 * User-picked dates for the Single (exactly one row) and SelectedDates (one or more rows) schedule
 * types. Pure input - never written to by generation. See MeetingOccurrenceGateway for resolved output.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingSelectedDateGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerSelectedDate';
    private static $primaryKey = 'meetingsManagerSelectedDateID';

    public function selectDatesByDefinition($meetingsManagerDefinitionID)
    {
        $sql = "SELECT * FROM meetingsManagerSelectedDate
                WHERE meetingsManagerDefinitionID = :meetingsManagerDefinitionID
                ORDER BY date";

        return $this->db()->select($sql, ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
    }

    public function countDatesByDefinition($meetingsManagerDefinitionID): int
    {
        return (int) $this->db()->selectOne(
            "SELECT COUNT(*) FROM meetingsManagerSelectedDate WHERE meetingsManagerDefinitionID = :meetingsManagerDefinitionID",
            ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]
        );
    }
}
