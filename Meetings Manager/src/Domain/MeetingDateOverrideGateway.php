<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Date Override Gateway
 *
 * Definition-level, per-date human overrides of MeetingDateResolver's normal willGenerate logic:
 * 'Exclude' vetoes a date that would otherwise generate, 'Include' forces one that otherwise
 * wouldn't (e.g. a School Closure). At most one row per (definition, date) - a date is only ever
 * excluded or included, never both, and once the requested state matches what the resolver would
 * produce naturally, the row is deleted entirely rather than kept as a redundant override. See
 * MeetingExceptionGateway for the separate concept of cancelling/moving/retiming an occurrence that
 * has already been generated.
 *
 * @version v0.6.00
 * @since   v0.4.00 (as MeetingExcludedDateGateway, Exclude-only)
 */
class MeetingDateOverrideGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerDateOverride';
    private static $primaryKey = 'meetingsManagerDateOverrideID';

    public function selectDatesByDefinition($meetingsManagerDefinitionID)
    {
        $sql = "SELECT * FROM meetingsManagerDateOverride
                WHERE meetingsManagerDefinitionID = :meetingsManagerDefinitionID
                ORDER BY date";

        return $this->db()->select($sql, ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]);
    }
}
