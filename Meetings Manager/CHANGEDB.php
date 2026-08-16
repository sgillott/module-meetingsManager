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

// v0.5.00
$count++;
$sql[$count][0] = "0.5.00";
$sql[$count][1] = "ALTER TABLE meetingsManagerDefinition
    ADD COLUMN locationType enum('Internal','External') NOT NULL DEFAULT 'External' AFTER description,
    ADD COLUMN gibbonSpaceID int(10) UNSIGNED ZEROFILL DEFAULT NULL AFTER locationType,
    CHANGE COLUMN location locationDetail varchar(255) DEFAULT NULL,
    ADD KEY gibbonSpaceID (gibbonSpaceID)
;end
UPDATE gibbonAction SET name='Manage Meetings_all', precedence=1, category='Manage Meetings', description='Create, edit, and archive any meeting definition, and generate their native Calendar events.', URLList='meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_unarchiveProcess.php,meeting_manage_preview.php,meeting_manage_preview_excludeDateProcess.php,meeting_manage_preview_includeDateProcess.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php' WHERE name='Manage Meetings' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category, description, URLList, entryURL, entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport, categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther) VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager'), 'Manage Meetings_my', 0, 'Manage Meetings', 'Create, edit, and archive meeting definitions the user organises, and generate their native Calendar events. Cannot see or act on meetings organised by anyone else.', 'meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_unarchiveProcess.php,meeting_manage_preview.php,meeting_manage_preview_excludeDateProcess.php,meeting_manage_preview_includeDateProcess.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php', 'meeting_manage.php', 'Y', 'Y', 'N', 'Y', 'N', 'N', 'N', 'Y', 'N', 'N', 'N')
;end
INSERT INTO gibbonPermission (permissionID, gibbonRoleID, gibbonActionID) VALUES (NULL, '002', (SELECT gibbonActionID FROM gibbonAction WHERE name='Manage Meetings_my' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')))
;end
-- Unarchive: no schema change needed - archiveDefinition() already deletes future occurrences/events
-- entirely and leaves past ones untouched, so flipping active back to Y is the whole operation.
-- Location: locationType/gibbonSpaceID mirror core Calendar's own Internal/External model exactly.
-- The DEFAULT 'External' on locationType is deliberate - every existing definition's location was
-- always written as External free text, so the schema default preserves that meaning for existing
-- rows. 'Internal' is only the default for brand-new meetings, applied in the Add form itself.
-- Permissions: splits the single admin-only 'Manage Meetings' action into a precedence-ordered
-- _all/_my pair, mirroring core's Behaviour module convention exactly. Renaming the existing action
-- in place (rather than deleting and recreating) preserves every existing gibbonPermission grant on
-- it untouched. Manage Meetings_my is auto-granted to Teacher (role 002), matching Behaviour's own
-- installed default.";

// v0.6.00
$count++;
$sql[$count][0] = "0.6.00";
$sql[$count][1] = "ALTER TABLE meetingsManagerExcludedDate
    ADD COLUMN type enum('Exclude','Include') NOT NULL DEFAULT 'Exclude' AFTER date
;end
RENAME TABLE meetingsManagerExcludedDate TO meetingsManagerDateOverride
;end
ALTER TABLE meetingsManagerDateOverride
    CHANGE COLUMN meetingsManagerExcludedDateID meetingsManagerDateOverrideID int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT
;end
ALTER TABLE meetingsManagerAudienceRule
    MODIFY COLUMN type enum('AllTeachingStaff','AllStaff','YearGroup','Department','DepartmentCoordinator','Role','Individual','ExcludeIndividual') NOT NULL,
    ADD COLUMN gibbonRoleID int(3) UNSIGNED ZEROFILL DEFAULT NULL AFTER gibbonDepartmentID,
    ADD KEY gibbonRoleID (gibbonRoleID)
;end
-- Date override: generalizes the previous Exclude-only table into a proper two-way override (see
-- MeetingDateResolver::annotate() and the two meeting_manage_preview_*DateProcess.php scripts) - a
-- human can now force a naturally-excluded date (School Closure, Not a School Day) to publish
-- anyway, not just veto a date that would otherwise generate. Existing rows all become type='Exclude',
-- which is exactly what they already meant under the old single-purpose table.
-- Audience: adds 'AllStaff' (broader than 'AllTeachingStaff') and 'Role' (members of a given
-- Gibbon Role, primary or secondary) as new audience rule types, alongside the existing ones.";

// v0.7.00
$count++;
$sql[$count][0] = "0.7.00";
$sql[$count][1] = "UPDATE gibbonAction SET category='Settings' WHERE name='Manage Meetings Manager Settings' AND category='Admin' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Meetings Manager')
;end
-- Core's sidebar sub-menu (ModuleGateway::selectModuleActionsByRole()) sorts a module's action
-- categories alphabetically with no configurable override - 'Admin' sorted before 'Manage Meetings'
-- purely on the letter A. Renaming this action's category to 'Settings' (a name already used the
-- same way by several core modules) is the only lever available from inside the module to make
-- 'Manage Meetings' appear first, as requested.";
