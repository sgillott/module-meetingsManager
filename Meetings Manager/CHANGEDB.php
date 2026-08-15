<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v0.1.00
$sql[$count][0] = "0.1.00";
$sql[$count][1] = "-- First installable version. All six tables are created via manifest.php's \$moduleTables on initial install, so there is nothing to migrate here yet.";

// v0.2.00
$count++;
$sql[$count][0] = "0.2.00";
$sql[$count][1] = "UPDATE gibbonAction SET URLList='meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_preview.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php' WHERE name='Manage Meetings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
UPDATE gibbonAction SET URLList='settings.php,settingsProcess.php' WHERE name='Manage Meetings Manager Settings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
-- Phase 3's original manifest.php only ever listed each action's entry page in URLList
-- (e.g. 'meeting_manage.php'), so isActionAccessible() - which does a LIKE match against
-- gibbonAction.URLList - rejected every Phase 4/5 sub-page (add, edit, preview, process scripts,
-- exceptions, etc.) even for Admin. This migration brings an already-installed 0.1.00 module's
-- gibbonAction rows in line with what a fresh install now gets directly from manifest.php.";

// v0.3.00
$count++;
$sql[$count][0] = "0.3.00";
$sql[$count][1] = "DELETE FROM gibbonPermission WHERE gibbonActionID IN (SELECT gibbonActionID FROM gibbonAction WHERE name='View Meetings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager'))
;end
DELETE FROM gibbonAction WHERE name='View Meetings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
UPDATE gibbonModule SET entryURL='meeting_manage.php' WHERE name='Meetings Manager'
;end
-- Meetings Manager is an authorised-staff-only tool: this module removes the View Meetings action
-- and its page entirely. Manage Meetings (Admin-only by default) is now the module's sole entry
-- point, so gibbonModule.entryURL is updated to match.";

// v0.4.00
$count++;
$sql[$count][0] = "0.4.00";
$sql[$count][1] = "CREATE TABLE `meetingsManagerExcludedDate` (
    `meetingsManagerExcludedDateID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `date` date NOT NULL,
    `gibbonPersonIDCreated` int(10) UNSIGNED ZEROFILL NOT NULL,
    `timestampCreated` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerExcludedDateID`),
    UNIQUE KEY `meetingsManagerDefinitionID` (`meetingsManagerDefinitionID`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
;end
UPDATE gibbonAction SET URLList='meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_preview.php,meeting_manage_preview_excludeDateProcess.php,meeting_manage_preview_includeDateProcess.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php' WHERE name='Manage Meetings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
-- Adds meetingsManagerExcludedDate, a definition-level veto MeetingDateResolver checks before every
-- other willGenerate rule - lets a candidate date be excluded from Preview, independent of whether
-- it was ever generated. Also registers the two new Preview-page process scripts that manage it.";
