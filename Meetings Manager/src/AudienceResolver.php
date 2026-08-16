<?php
namespace Gibbon\Module\MeetingsManager;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\Staff\StaffGateway;
use Gibbon\Domain\Departments\DepartmentStaffGateway;

/**
 * Audience Resolver
 *
 * Resolves a Meeting Definition's stored meetingsManagerAudienceRule rows into a deduplicated set of
 * gibbonPersonID values, per the approved semantics: union(inclusion rules) - union(exclusion rules).
 *
 * Rule rows are expected to already carry the display fields joined by
 * MeetingAudienceRuleGateway::selectRulesByDefinition() (yearGroupName, departmentName,
 * personTitle/personPreferredName/personSurname), so this class never has to look up a rule's own
 * target name - only resolve it to people.
 *
 * @version v0.1.00
 * @since   v0.1.00
 */
class AudienceResolver
{
    private $db;
    private $staffGateway;

    public function __construct(Connection $db, StaffGateway $staffGateway)
    {
        $this->db = $db;
        $this->staffGateway = $staffGateway;
    }

    /**
     * @param int $gibbonSchoolYearID
     * @param iterable $audienceRules  Rows from MeetingAudienceRuleGateway::selectRulesByDefinition()
     * @return array  [gibbonPersonID => ['gibbonPersonID','title','preferredName','surname','sources' => [labels]]]
     */
    public function resolve(int $gibbonSchoolYearID, iterable $audienceRules): array
    {
        $included = [];
        $excludedIDs = [];

        foreach ($audienceRules as $rule) {
            $label = $this->describeRule($rule);

            if ($rule['type'] === 'ExcludeIndividual') {
                if (!empty($rule['gibbonPersonID'])) {
                    $excludedIDs[$rule['gibbonPersonID']] = true;
                }
                continue;
            }

            foreach ($this->resolveRule($gibbonSchoolYearID, $rule) as $person) {
                $id = $person['gibbonPersonID'];
                if (!isset($included[$id])) {
                    $included[$id] = [
                        'gibbonPersonID' => $id,
                        'title'          => $person['title'] ?? '',
                        'preferredName'  => $person['preferredName'] ?? '',
                        'surname'        => $person['surname'] ?? '',
                        'sources'        => [],
                    ];
                }
                $included[$id]['sources'][] = $label;
            }
        }

        foreach (array_keys($excludedIDs) as $id) {
            unset($included[$id]);
        }

        uasort($included, function ($a, $b) {
            return strcmp($a['surname'].$a['preferredName'], $b['surname'].$b['preferredName']);
        });

        return $included;
    }

    /**
     * Human-readable label for a rule, used both as Preview provenance and as the rule's own
     * description in the Manage Audience Rules list.
     */
    public function describeRule(array $rule): string
    {
        switch ($rule['type']) {
            case 'AllTeachingStaff':
                return __('All Teaching Staff');
            case 'YearGroup':
                return sprintf(__('Teachers of %1$s'), $rule['yearGroupName'] ?? __('Unknown Year Group'));
            case 'Department':
                return sprintf(__('Staff in %1$s'), $rule['departmentName'] ?? __('Unknown Department'));
            case 'DepartmentCoordinator':
                return sprintf(__('%1$s Coordinator'), $rule['departmentName'] ?? __('Unknown Department'));
            case 'Individual':
                return sprintf(__('Individual: %1$s'), $this->personName($rule));
            case 'ExcludeIndividual':
                return sprintf(__('Exclude: %1$s'), $this->personName($rule));
            case 'AllStaff':
                return __('All Staff');
            case 'Role':
                return sprintf(__('Members of the %1$s Role'), $rule['roleName'] ?? __('Unknown Role'));
            default:
                return $rule['type'];
        }
    }

    private function personName(array $rule): string
    {
        $name = trim(($rule['personPreferredName'] ?? '').' '.($rule['personSurname'] ?? ''));
        return $name !== '' ? $name : __('Unknown Person');
    }

    private function resolveRule(int $gibbonSchoolYearID, array $rule): array
    {
        switch ($rule['type']) {
            case 'AllTeachingStaff':
                return $this->selectAllTeachingStaff();
            case 'YearGroup':
                return empty($rule['gibbonYearGroupID']) ? [] : $this->selectTeachersByYearGroup($gibbonSchoolYearID, $rule['gibbonYearGroupID']);
            case 'Department':
                return empty($rule['gibbonDepartmentID']) ? [] : $this->selectStaffByDepartment($rule['gibbonDepartmentID']);
            case 'DepartmentCoordinator':
                return empty($rule['gibbonDepartmentID']) ? [] : $this->selectDepartmentCoordinators($rule['gibbonDepartmentID']);
            case 'Individual':
                return empty($rule['gibbonPersonID']) ? [] : $this->selectIndividual($rule['gibbonPersonID']);
            case 'AllStaff':
                return $this->selectAllStaff();
            case 'Role':
                return empty($rule['gibbonRoleID']) ? [] : $this->selectStaffByRole($rule['gibbonRoleID']);
            default:
                return [];
        }
    }

    /**
     * All active, full-status teaching staff, current as of today. No existing core gateway method
     * selects the full "all teaching staff" set without a QueryCriteria/pagination round-trip
     * (StaffGateway::queryAllStaff is paginated for the Manage Staff grid), so this mirrors the same
     * WHERE-clause idiom StaffGateway::selectStaffByID() already uses, applied without needing IDs
     * up front.
     */
    private function selectAllTeachingStaff(): array
    {
        $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname
                FROM gibbonPerson
                JOIN gibbonStaff ON (gibbonStaff.gibbonPersonID = gibbonPerson.gibbonPersonID)
                WHERE gibbonPerson.status = 'Full'
                AND gibbonStaff.type = 'Teaching'
                AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= :today)
                AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= :today)
                ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db->select($sql, ['today' => date('Y-m-d')])->fetchAll();
    }

    /**
     * Teachers of courses tied to the given year group, via gibbonCourse.gibbonYearGroupIDList
     * (FIND_IN_SET, no foreign key - confirmed convention). Mirrors the join shape already used for
     * this exact purpose in modules\Messenger\src\MessageTargets.php, with the gibbonCourseClassPerson
     * role='Teacher' filter that reference was missing.
     */
    private function selectTeachersByYearGroup(int $gibbonSchoolYearID, $gibbonYearGroupID): array
    {
        $sql = "SELECT DISTINCT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname
                FROM gibbonCourse
                JOIN gibbonCourseClass ON (gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID)
                JOIN gibbonCourseClassPerson ON (gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID)
                JOIN gibbonPerson ON (gibbonPerson.gibbonPersonID = gibbonCourseClassPerson.gibbonPersonID)
                WHERE gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID
                AND FIND_IN_SET(:gibbonYearGroupID, gibbonCourse.gibbonYearGroupIDList)
                AND gibbonCourseClassPerson.role = 'Teacher'
                AND gibbonPerson.status = 'Full'
                ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db->select($sql, ['gibbonSchoolYearID' => $gibbonSchoolYearID, 'gibbonYearGroupID' => $gibbonYearGroupID])->fetchAll();
    }

    /**
     * Members of the given Department, via gibbonDepartmentStaff. Prefers core's own
     * DepartmentStaffGateway when it's actually available - checked at runtime with class_exists()/
     * method_exists() rather than a hardcoded Gibbon version number, so this automatically keeps
     * using the real gateway on any install where it exists (including future ones), and only ever
     * falls back to an equivalent direct SQL query on installs where it doesn't (confirmed missing
     * from the container on at least one tested Gibbon v30 install - a NotFoundException there,
     * since the whole AudienceResolver object has to resolve even when no Department rule exists
     * yet, if it were still a constructor dependency).
     */
    private function selectDepartmentMembers($gibbonDepartmentID): array
    {
        if (class_exists(DepartmentStaffGateway::class) && method_exists(DepartmentStaffGateway::class, 'seletStaffListByDepartment')) {
            return (new DepartmentStaffGateway($this->db))->seletStaffListByDepartment($gibbonDepartmentID)->fetchAll();
        }

        $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonDepartmentStaff.role, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname
                FROM gibbonDepartmentStaff
                JOIN gibbonPerson ON (gibbonDepartmentStaff.gibbonPersonID = gibbonPerson.gibbonPersonID)
                JOIN gibbonStaff ON (gibbonStaff.gibbonPersonID = gibbonPerson.gibbonPersonID)
                WHERE gibbonPerson.status = 'Full'
                AND gibbonDepartmentStaff.gibbonDepartmentID = :gibbonDepartmentID
                ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db->select($sql, ['gibbonDepartmentID' => $gibbonDepartmentID])->fetchAll();
    }

    private function selectStaffByDepartment($gibbonDepartmentID): array
    {
        return $this->selectDepartmentMembers($gibbonDepartmentID);
    }

    private function selectDepartmentCoordinators($gibbonDepartmentID): array
    {
        $members = $this->selectDepartmentMembers($gibbonDepartmentID);

        return array_values(array_filter($members, function ($member) {
            return ($member['role'] ?? '') === 'Coordinator';
        }));
    }

    private function selectIndividual($gibbonPersonID): array
    {
        return $this->staffGateway->selectStaffByID($gibbonPersonID)->fetchAll();
    }

    /**
     * Every active staff member, teaching or not - same idiom as selectAllTeachingStaff() above,
     * just without the gibbonStaff.type='Teaching' filter. Matches core's own "the staff list"
     * shape (gibbonPerson JOIN gibbonStaff WHERE status='Full'), e.g. DatabaseFormFactory's own
     * createSelectStaff().
     */
    private function selectAllStaff(): array
    {
        $sql = "SELECT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname
                FROM gibbonPerson
                JOIN gibbonStaff ON (gibbonStaff.gibbonPersonID = gibbonPerson.gibbonPersonID)
                WHERE gibbonPerson.status = 'Full'
                AND (gibbonPerson.dateStart IS NULL OR gibbonPerson.dateStart <= :today)
                AND (gibbonPerson.dateEnd IS NULL OR gibbonPerson.dateEnd >= :today)
                ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db->select($sql, ['today' => date('Y-m-d')])->fetchAll();
    }

    /**
     * Everyone holding the given Gibbon Role, whether as their primary role or one of their
     * secondary roles (gibbonRoleIDAll is a CSV) - mirrors RoleGateway::queryUsersByRole()'s own
     * join shape.
     */
    private function selectStaffByRole($gibbonRoleID): array
    {
        $sql = "SELECT DISTINCT gibbonPerson.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname
                FROM gibbonPerson
                JOIN gibbonRole ON (gibbonPerson.gibbonRoleIDPrimary = gibbonRole.gibbonRoleID OR FIND_IN_SET(gibbonRole.gibbonRoleID, gibbonPerson.gibbonRoleIDAll))
                WHERE gibbonRole.gibbonRoleID = :gibbonRoleID
                AND gibbonPerson.status = 'Full'
                ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db->select($sql, ['gibbonRoleID' => $gibbonRoleID])->fetchAll();
    }
}
