<?php
namespace Gibbon\Module\MeetingsManager;

/**
 * Audience Summary
 *
 * Turns a Meeting Definition's meetingsManagerAudienceRule rows (as returned by
 * MeetingAudienceRuleGateway::selectRulesByDefinition(), already carrying real Year Group/
 * Department/person names) into a short, human-readable line for the Manage Meetings list and
 * Preview - e.g. "Year 11 & Year 12 Teachers", "Science + Mathematics Department Coordinators",
 * "Year 10 Teachers + J Smith", "All Teaching Staff excluding J Smith".
 *
 * Pure text formatting only - never touches the database or resolves actual people. The full rule
 * list (and, in Preview, the fully resolved participant list) remains available separately; this is
 * a label, not a replacement for either.
 *
 * @version v0.2.00
 * @since   v0.2.00
 */
class AudienceSummary
{
    private const MAX_LENGTH = 70;

    /**
     * @param array $rules  Rows from MeetingAudienceRuleGateway::selectRulesByDefinition()
     * @param int|null $participantCount  Optional, used only in the truncated fallback
     */
    public function summarize(array $rules, ?int $participantCount = null): string
    {
        if (empty($rules)) {
            return __('No audience configured');
        }

        $inclusions = array_values(array_filter($rules, fn($rule) => $rule['type'] !== 'ExcludeIndividual'));
        $exclusions = array_values(array_filter($rules, fn($rule) => $rule['type'] === 'ExcludeIndividual'));

        $inclusionPhrases = $this->summarizeInclusions($inclusions);

        if ($inclusionPhrases === null) {
            // Too many distinct rule types combined to say concisely - degrade gracefully.
            return $this->fallback(count($rules), $participantCount);
        }

        $summary = implode(' + ', $inclusionPhrases);

        if (!empty($exclusions)) {
            $names = array_map(fn($rule) => $this->personLabel($rule), $exclusions);
            $summary .= ' ' . sprintf(__('excluding %1$s'), implode(', ', $names));
        }

        if (mb_strlen($summary) > self::MAX_LENGTH) {
            return $this->fallback(count($rules), $participantCount);
        }

        return $summary;
    }

    /**
     * @return string[]|null  One phrase per inclusion rule-type group, or null if the mix of types
     *                        present isn't one this can describe concisely (falls back to a count).
     */
    private function summarizeInclusions(array $inclusions): ?array
    {
        if (empty($inclusions)) {
            return [__('Nobody')];
        }

        $byType = [];
        foreach ($inclusions as $rule) {
            $byType[$rule['type']][] = $rule;
        }

        // AllStaff/AllTeachingStaff dominate - combining either with narrower inclusion rules is
        // redundant configuration, but still describable as just the one phrase. AllStaff is the
        // broader of the two, so it wins if a (redundant) combination of both is ever configured.
        if (isset($byType['AllStaff'])) {
            return [__('All Staff')];
        }
        if (isset($byType['AllTeachingStaff'])) {
            return [__('All Teaching Staff')];
        }

        $knownTypes = ['YearGroup', 'Department', 'DepartmentCoordinator', 'Individual', 'Role'];
        if (!empty(array_diff(array_keys($byType), $knownTypes))) {
            return null;
        }

        $phrases = [];

        if (!empty($byType['YearGroup'])) {
            $names = array_map(fn($rule) => $rule['yearGroupName'] ?? __('Unknown Year Group'), $byType['YearGroup']);
            $phrases[] = sprintf(__('%1$s Teachers'), $this->joinNames($names));
        }

        if (!empty($byType['Department'])) {
            $names = array_map(fn($rule) => $rule['departmentName'] ?? __('Unknown Department'), $byType['Department']);
            $phrases[] = sprintf(__('%1$s Staff'), $this->joinNames($names));
        }

        if (!empty($byType['DepartmentCoordinator'])) {
            $names = array_map(fn($rule) => $rule['departmentName'] ?? __('Unknown Department'), $byType['DepartmentCoordinator']);
            $label = count($names) === 1 ? __('Coordinator') : __('Coordinators');
            $phrases[] = sprintf(__('%1$s Department %2$s'), $this->joinNames($names), $label);
        }

        if (!empty($byType['Role'])) {
            $names = array_map(fn($rule) => $rule['roleName'] ?? __('Unknown Role'), $byType['Role']);
            $phrases[] = sprintf(__('%1$s Role Members'), $this->joinNames($names));
        }

        if (!empty($byType['Individual'])) {
            $names = array_map(fn($rule) => $this->personLabel($rule), $byType['Individual']);
            $phrases[] = implode(', ', $names);
        }

        return $phrases;
    }

    /**
     * "Year 11" / "Year 11 & Year 12" / "Year 10, 11 & 12" style joining.
     */
    private function joinNames(array $names): string
    {
        $names = array_values(array_unique($names));

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);
        return implode(', ', $names) . ' & ' . $last;
    }

    /**
     * Compact "J Smith" form - initial + surname, matching the brief's own examples exactly.
     */
    private function personLabel(array $rule): string
    {
        $preferredName = $rule['personPreferredName'] ?? '';
        $surname = $rule['personSurname'] ?? '';

        if ($surname === '') {
            return __('Unknown Person');
        }

        $initial = $preferredName !== '' ? mb_substr($preferredName, 0, 1) . ' ' : '';
        return $initial . $surname;
    }

    private function fallback(int $ruleCount, ?int $participantCount): string
    {
        $label = $ruleCount === 1 ? __('1 audience rule') : sprintf(__('%1$s audience rules'), $ruleCount);

        return $participantCount !== null
            ? $label . ', ' . sprintf(__('%1$s participants'), $participantCount)
            : $label;
    }
}
