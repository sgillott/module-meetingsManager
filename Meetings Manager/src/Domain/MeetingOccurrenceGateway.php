<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Occurrence Gateway
 *
 * Generated/resolved output only, for every scheduleType including SelectedDates. An occurrence can
 * exist as 'Planned' before its native gibbonCalendarEvent is generated. gibbonCalendarEventID is the
 * unambiguous, unique link back to Calendar that keeps regeneration idempotent - Meetings Manager may
 * only alter or delete a Calendar event that a row here demonstrably points to.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingOccurrenceGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerOccurrence';
    private static $primaryKey = 'meetingsManagerOccurrenceID';

    public function countByDefinition($meetingsManagerDefinitionID): int
    {
        return (int) $this->db()->selectOne(
            "SELECT COUNT(*) FROM meetingsManagerOccurrence WHERE meetingsManagerDefinitionID = :meetingsManagerDefinitionID",
            ['meetingsManagerDefinitionID' => $meetingsManagerDefinitionID]
        );
    }
}
