<?php
namespace Gibbon\Module\MeetingsManager\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Meeting Exception Gateway
 *
 * At most one exception per occurrence (Cancel / Move / Retime). Authoritative input to
 * reconciliation - regeneration must read an existing exception before deciding what a "desired"
 * occurrence looks like for that date, never overwrite it silently.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class MeetingExceptionGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'meetingsManagerException';
    private static $primaryKey = 'meetingsManagerExceptionID';
}
