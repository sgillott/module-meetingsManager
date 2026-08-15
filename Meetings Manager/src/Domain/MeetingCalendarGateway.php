<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Calendar Gateway
 *
 * Maps a gibbonSchoolYearID to the gibbonCalendarID of that year's "Meetings" calendar.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingCalendarGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerCalendar';
    private static $primaryKey = 'meetingsManagerCalendarID';
}
