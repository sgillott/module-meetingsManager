<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Excluded Date Gateway
 *
 * Definition-level dates manually vetoed from generation, regardless of scheduleType. Read by
 * MeetingDateResolver as an override on top of its normal willGenerate logic. See
 * MeetingExceptionGateway for the separate concept of cancelling/moving/retiming an occurrence that
 * has already been generated.
 *
 * @version v0.4.00
 * @since   v0.4.00
 */
class MeetingExcludedDateGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerExcludedDate';
    private static $primaryKey = 'meetingsManagerExcludedDateID';

    public function selectDatesByDefinition($meetingsManagerDefinitionID)
    {
        $sql = "SELECT * FROM meetingsManagerExcludedDate
                WHERE meetingsManagerDefinitionID = :meetingsManagerDefinitionID
                ORDER BY date";

        return $this->db()->select($sql, ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
    }
}
