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

use Gibbon\Tables\DataTable;
use Gibbon\Services\Format;
use Gibbon\Module\MeetingsManager\Domain\MeetingDefinitionGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingAudienceRuleGateway;
use Gibbon\Module\MeetingsManager\Domain\MeetingOccurrenceGateway;
use Gibbon\Module\MeetingsManager\AudienceSummary;

require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Meetings Manager/meeting_manage.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $page->breadcrumbs->add(__('Manage Meetings'));

    $gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? $session->get('gibbonSchoolYearID');
    $show = ($_GET['show'] ?? 'active') === 'archived' ? 'archived' : 'active';
    $active = $show === 'archived' ? 'N' : 'Y';

    if (empty($gibbonSchoolYearID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $page->navigator->addSchoolYearNavigation($gibbonSchoolYearID);

    $definitionGateway = $container->get(MeetingDefinitionGateway::class);
    $definitions = $definitionGateway->selectDefinitionsBySchoolYear($gibbonSchoolYearID, $active);

    $table = DataTable::create('meetings');

    $table->addHeaderAction('add', __('Add Meeting'))
        ->setURL('/modules/Meetings Manager/meeting_manage_add.php')
        ->addParam('gibbonSchoolYearID', $gibbonSchoolYearID)
        ->displayLabel();

    if ($show === 'active') {
        $table->addHeaderAction('archived', __('View Archived'))
            ->setURL('/modules/Meetings Manager/meeting_manage.php')
            ->addParam('gibbonSchoolYearID', $gibbonSchoolYearID)
            ->addParam('show', 'archived')
            ->displayLabel();
    } else {
        $table->addHeaderAction('active', __('View Active'))
            ->setURL('/modules/Meetings Manager/meeting_manage.php')
            ->addParam('gibbonSchoolYearID', $gibbonSchoolYearID)
            ->displayLabel();
    }

    $table->modifyRows(function ($row, $tableRow) {
        if ($row['active'] == 'N') $tableRow->addClass('error');
        return $tableRow;
    });

    $table->addColumn('name', __('Name'));
    $table->addColumn('schedule', __('Schedule'))->format(function ($row) {
        return meetingsManagerScheduleSummary($row);
    });
    $table->addColumn('organiserName', __('Organiser'));
    $table->addColumn('audienceRuleCount', __('Audience'))->format(function ($row) use ($container) {
        $ruleGateway = $container->get(MeetingAudienceRuleGateway::class);
        $rules = $ruleGateway->selectRulesByDefinition($row['meetingsManagerDefinitionID'])->fetchAll();
        $audienceSummary = $container->get(AudienceSummary::class);
        return $audienceSummary->summarize($rules);
    });
    $table->addColumn('active', __('Status'))->format(function ($row) use ($container) {
        if ($row['active'] != 'Y') {
            return Format::tag(__('Archived'), 'dull');
        }
        $occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
        $isPublished = $occurrenceGateway->countByDefinition($row['meetingsManagerDefinitionID']) > 0;
        return $isPublished ? Format::tag(__('Published'), 'success') : Format::tag(__('Draft'), 'warning');
    });

    $table->addActionColumn()
        ->addParam('meetingsManagerDefinitionID')
        ->addParam('gibbonSchoolYearID')
        ->format(function ($row, $actions) use ($container) {
            $occurrenceGateway = $container->get(MeetingOccurrenceGateway::class);
            $isPublished = $row['active'] == 'Y' && $occurrenceGateway->countByDefinition($row['meetingsManagerDefinitionID']) > 0;

            $actions->addAction('preview', $isPublished ? __('Update') : __('Preview'))
                ->setURL('/modules/Meetings Manager/meeting_manage_preview.php');

            $actions->addAction('edit', __('Edit'))
                ->setURL('/modules/Meetings Manager/meeting_manage_edit.php');

            if ($row['active'] == 'Y') {
                $actions->addAction('occurrences', __('Occurrences'))
                    ->setIcon('calendar')
                    ->setURL('/modules/Meetings Manager/meeting_manage_occurrences.php');

                $actions->addAction('refresh', __('Refresh Participants'))
                    ->setIcon('users')
                    ->setURL('/modules/Meetings Manager/meeting_manage_refreshParticipantsProcess.php')->directLink()
                    ->addConfirmation(__('Re-resolve the audience and update participants on every future generated event for this meeting? Past events are never touched.'));

                $actions->addAction('archive', __('Archive Meeting Series'))
                    ->setIcon('archive')
                    ->setURL('/modules/Meetings Manager/meeting_manage_archiveProcess.php')
                    ->directLink()
                    ->addConfirmation(__('Archive this meeting series? Future generated meetings will be removed from the calendar. Past meetings are not affected. The meeting and its history remain available under Archived Meetings.'));
            }
        });

    echo $table->render($definitions->toDataSet());
}
