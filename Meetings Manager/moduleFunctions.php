<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Services\Format;
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;
use Gibbon\Module\MeetingsManager\AudienceResolver;

/**
 * One-line human description of a Meeting Definition's schedule, e.g. "17 September 2026, 16:00-17:00"
 * or "Middle & Upper School: Wednesday B, 16:00-17:00" - always using the school's own actual
 * configured timetable/day names, never inventing "Week A/B" terminology of its own. Shared by the
 * list, edit, preview, and occurrence pages so the wording never drifts between them. Expects a row
 * shaped like MeetingDefinitionGateway's enriched queries (dayOfWeekName, timetableName, tiedDayName,
 * singleDate, selectedDateCount already joined).
 */
function meetingsManagerScheduleSummary(array $definition): string
{
    $time = Format::time($definition['timeStart'] ?? '').'-'.Format::time($definition['timeEnd'] ?? '');

    switch ($definition['scheduleType'] ?? '') {
        case 'Single':
            $date = $definition['singleDate'] ?? null;
            return $date ? sprintf(__('%1$s, %2$s'), Format::date($date), $time) : sprintf(__('Once, %1$s'), $time);
        case 'SelectedDates':
            $count = $definition['selectedDateCount'] ?? null;
            return $count !== null
                ? sprintf(__('%1$s selected dates, %2$s'), $count, $time)
                : sprintf(__('Selected dates, %1$s'), $time);
        case 'Weekly':
            return sprintf(__('Every %1$s, %2$s'), $definition['dayOfWeekName'] ?? '?', $time);
        case 'TimetableCycle':
            return sprintf(__('%1$s: %2$s, %3$s'), $definition['timetableName'] ?? '?', $definition['tiedDayName'] ?? '?', $time);
        default:
            return $time;
    }
}

/**
 * Resolves which grouped Manage Meetings action the current role holds, if any, using core's own
 * getHighestGroupedAction() (which orders by gibbonAction.precedence DESC, so a role holding both
 * would correctly resolve to 'all' since _all=1 beats _my=0). Returns 'all' (unrestricted),
 * 'my' (organiser-only), or false (deny - no recognised grouped action). Deliberately fails closed:
 * only an explicit 'Manage Meetings_all' match returns 'all' - every other outcome, including an
 * unrecognised or missing grouped action, is treated as restrictive by the two helpers below.
 */
function meetingsManagerScope($guid, $connection2, $session)
{
    $highest = getHighestGroupedAction($guid, '/modules/Meetings Manager/meeting_manage.php', $connection2);

    if ($highest === 'Manage Meetings_all') return 'all';
    if ($highest === 'Manage Meetings_my') return 'my';

    return false;
}

/**
 * For list-filtering and form-lock decisions: the gibbonPersonID to restrict to, or null if
 * unrestricted. Only an explicit 'all' scope unlocks the unfiltered case - 'my' and the fail-closed
 * false scope both restrict to self, so an unrecognised permission state never silently shows
 * everyone's meetings.
 */
function meetingsManagerScopeToSelf($guid, $connection2, $session): ?string
{
    return meetingsManagerScope($guid, $connection2, $session) === 'all' ? null : $session->get('gibbonPersonID');
}

/**
 * For single-record pages/process scripts: may the current session manage this specific Meeting
 * Definition? Organiser identity alone is never sufficient - a recognised management scope is
 * required first, so a person with neither Manage Meetings_all nor _my is denied even for a
 * definition they happen to organise.
 */
function meetingsManagerCanManage($guid, $connection2, $session, array $definition): bool
{
    $scope = meetingsManagerScope($guid, $connection2, $session);

    if ($scope === 'all') return true;
    if ($scope === 'my') return (string) $definition['gibbonPersonIDOrganiser'] === (string) $session->get('gibbonPersonID');

    return false;
}

/**
 * Renders the Audience section - shared by meeting_manage_edit.php and (once a meeting has been
 * created) meeting_manage_add.php, so the two pages never drift apart.
 *
 * Injects both the read-only rules list AND the "Add Audience Rule" picker as rows directly into
 * the given $form, positioned wherever the caller has built $form up to when this is called (call
 * this before adding the Submit row, so the whole Audience block ends up above it). Since neither
 * call adds a new addHeading() in between, they all stay grouped under the one 'Audience' heading
 * and render as a single boxed section, matching Meeting/Schedule.
 *
 * The "Add Rule" button posts to a different endpoint than $form's own action, and HTML doesn't
 * allow a nested <form> to do that - instead it stays a single submit-type button inside $form's
 * own <form>, using the standard HTML5 formaction attribute to redirect just that one button's
 * submission to meeting_manage_edit_audience_addProcess.php. formnovalidate is set alongside it so
 * clicking "Add Rule" is never blocked by unrelated required fields elsewhere in $form (e.g. the
 * meeting's own Name field). Gibbon's own client-side "required" enforcement is a custom Alpine
 * behaviour (x-validate), not the native HTML required attribute, so the rule type picker itself is
 * deliberately left without ->required() - it would otherwise also block the meeting's own Submit
 * button when no rule type is selected. The server-side check in the process script remains the
 * real validation for it either way.
 */
function meetingsManagerRenderAudienceSection($container, $pdo, $session, Form $form, array $definition): void
{
    $form->setFactory(DatabaseFormFactory::create($pdo));

    $meetingsManagerDefinitionID = $definition['meetingsManagerDefinitionID'];
    $gibbonSchoolYearID = $definition['gibbonSchoolYearID'];

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('meetingsManagerDefinitionID', $meetingsManagerDefinitionID);
    $form->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

    $ruleGateway = $container->get(MeetingAudienceRuleGateway::class);
    $rules = $ruleGateway->selectRulesByDefinition($meetingsManagerDefinitionID)->fetchAll();
    $audienceResolver = $container->get(AudienceResolver::class);

    $form->addRow()->addHeading(__('Audience'));

    if (empty($rules)) {
        $form->addRow()->addContent('<p><i>'.__('No audience rules have been added yet. No participants will be resolved until at least one inclusion rule is added.').'</i></p>');
    } else {
        $listHtml = '<table class="smallIntBorder w-full">';
        foreach ($rules as $rule) {
            $listHtml .= '<tr><td>'.htmlspecialchars($audienceResolver->describeRule($rule)).'</td><td class="w-16 text-right">';
            $listHtml .= '<a class="text-red-700" href="'.$session->get('absoluteURL').'/modules/Meetings Manager/meeting_manage_edit_audience_deleteProcess.php?meetingsManagerAudienceRuleID='.$rule['meetingsManagerAudienceRuleID'].'&meetingsManagerDefinitionID='.$meetingsManagerDefinitionID.'&gibbonSchoolYearID='.$gibbonSchoolYearID.'" onclick="return confirm(\''.__('Are you sure you want to remove this rule?').'\')">'.__('Remove').'</a>';
            $listHtml .= '</td></tr>';
        }
        $listHtml .= '</table>';

        $resolved = $audienceResolver->resolve((int) $gibbonSchoolYearID, $rules);
        $listHtml .= '<p>'.sprintf(__('Resolved Participants: %1$s'), count($resolved)).'</p>';

        $form->addRow()->addContent($listHtml);
    }

    $ruleTypes = [
        'AllTeachingStaff'      => __('All Teaching Staff'),
        'AllStaff'              => __('All Staff'),
        'YearGroup'             => __('Teachers of Selected Year Groups'),
        'Department'            => __('Staff in Selected Departments'),
        'DepartmentCoordinator' => __('Department Coordinators'),
        'Role'                  => __('Members of Selected Roles'),
        'Individual'            => __('Specific Staff'),
        'ExcludeIndividual'     => __('Exclude Individual'),
    ];

    $row = $form->addRow();
        $row->addLabel('type', __('Add Audience Rule'));
        $row->addSelect('type')->fromArray($ruleTypes)->placeholder();

    // Year Groups/Departments/Roles/Staff can each be multi-selected - picking 3 at once adds 3
    // rules in one step rather than requiring the form to be submitted 3 times.
    $form->toggleVisibilityByClass('ruleYearGroup')->onSelect('type')->when('YearGroup');
    $row = $form->addRow()->addClass('ruleYearGroup');
        $row->addLabel('gibbonYearGroupID', __('Year Groups'));
        $row->addSelectYearGroup('gibbonYearGroupID')->selectMultiple();

    $form->toggleVisibilityByClass('ruleDepartment')->onSelect('type')->when(['Department', 'DepartmentCoordinator']);
    $row = $form->addRow()->addClass('ruleDepartment');
        $row->addLabel('gibbonDepartmentID', __('Departments'));
        $row->addSelectDepartment('gibbonDepartmentID')->selectMultiple();

    // No DatabaseFormFactory helper scopes the Role picker to Staff-category roles (its own
    // createSelectRole() is a flat, all-categories list) - Student/Parent/Other roles are never
    // meaningful audience targets here, so this stays a direct query, same idiom as the Day-of-Week
    // dropdown on the Schedule section above.
    $form->toggleVisibilityByClass('ruleRole')->onSelect('type')->when('Role');
    $roleOptions = $pdo->select("SELECT gibbonRoleID, name FROM gibbonRole WHERE category='Staff' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR);
    $row = $form->addRow()->addClass('ruleRole');
        $row->addLabel('gibbonRoleID', __('Roles'));
        $row->addSelect('gibbonRoleID')->fromArray($roleOptions)->selectMultiple();

    $form->toggleVisibilityByClass('ruleIndividual')->onSelect('type')->when(['Individual', 'ExcludeIndividual']);
    $row = $form->addRow()->addClass('ruleIndividual');
        $row->addLabel('gibbonPersonID', __('Staff Member(s)'));
        $row->addSelectStaff('gibbonPersonID')->selectMultiple();

    // Built via the factory directly (not $row->addSubmit()) so this row's heading stays 'Audience'
    // instead of being forced to 'submit' - that magic override is what normally pulls a Submit
    // button into its own separate section, which is exactly what must NOT happen here.
    $addRuleAction = $session->get('absoluteURL').'/modules/'.$session->get('module').'/meeting_manage_edit_audience_addProcess.php';
    $addRuleButton = $form->getFactory()->createSubmit(__('Add Rule'))
        ->setAttribute('formaction', $addRuleAction)
        ->setAttribute('formnovalidate', true);

    $row = $form->addRow();
        $row->addElement($addRuleButton);
}

