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
along with this program.  If not, see <http:// www.gnu.org/licenses/>.
*/

// This file describes the module, including database tables

// Basic variables
$name        = 'Meetings Manager';            // The name of the module as it appears to users. Needs to be unique to installation. Also the name of the folder that holds the unit.
$description = 'Define recurring meetings and generate native Gibbon Calendar events with the correct participants.';            // Short text description
$entryURL    = "meeting_manage.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Other';            // The main menu area to place the module in
$version     = '0.7.00';            // Version number
$author      = 'Steve Gillott';            // Your name
$url         = '';            // Your URL

// Module tables
// Six module-owned tables. No core Gibbon tables are created or altered. No DB-level FOREIGN KEY
// constraints are used anywhere in Gibbon core (confirmed by inspection of gibbon.sql) - relationships
// here are logical (indexed, app-enforced) to match that convention exactly.

// Maps a school year to its "Meetings" gibbonCalendar - gibbonCalendar.gibbonSchoolYearID is NOT NULL,
// so calendar ownership must be resolved per year rather than via a single global setting.
$moduleTables[] = "CREATE TABLE `meetingsManagerCalendar` (
    `meetingsManagerCalendarID` int(4) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `gibbonCalendarID` int(6) UNSIGNED ZEROFILL NOT NULL,
    `timestampCreated` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerCalendarID`),
    UNIQUE KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`),
    KEY `gibbonCalendarID` (`gibbonCalendarID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// The user-configured Meeting Definition. Calendar and Event Type are resolved at generation time
// (via meetingsManagerCalendar and the gibbonCalendarEventTypeID setting), not stored here, so there
// is one source of truth for each.
$moduleTables[] = "CREATE TABLE `meetingsManagerDefinition` (
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `name` varchar(120) NOT NULL,
    `description` text,
    `locationType` enum('Internal','External') NOT NULL DEFAULT 'External',
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `locationDetail` varchar(255) DEFAULT NULL,
    `gibbonPersonIDOrganiser` int(10) UNSIGNED ZEROFILL NOT NULL,
    `scheduleType` enum('Single','SelectedDates','Weekly','TimetableCycle') NOT NULL,
    `gibbonDaysOfWeekID` int(2) UNSIGNED ZEROFILL DEFAULT NULL,
    `gibbonTTID` int(8) UNSIGNED ZEROFILL DEFAULT NULL,
    `gibbonTTDayID` int(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `rangeStart` date DEFAULT NULL,
    `rangeEnd` date DEFAULT NULL,
    `timeStart` time NOT NULL,
    `timeEnd` time NOT NULL,
    `active` enum('Y','N') NOT NULL DEFAULT 'Y',
    `gibbonPersonIDCreated` int(10) UNSIGNED ZEROFILL NOT NULL,
    `timestampCreated` timestamp NULL DEFAULT NULL,
    `timestampModified` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerDefinitionID`),
    KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`),
    KEY `gibbonPersonIDOrganiser` (`gibbonPersonIDOrganiser`),
    KEY `gibbonTTDayID` (`gibbonTTDayID`),
    KEY `gibbonSpaceID` (`gibbonSpaceID`),
    KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// One row per audience rule; a definition can combine several. Kept dynamic (not a frozen person
// list) so participants can be re-resolved later against current Gibbon staff/department data.
$moduleTables[] = "CREATE TABLE `meetingsManagerAudienceRule` (
    `meetingsManagerAudienceRuleID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `type` enum('AllTeachingStaff','AllStaff','YearGroup','Department','DepartmentCoordinator','Role','Individual','ExcludeIndividual') NOT NULL,
    `gibbonYearGroupID` int(3) UNSIGNED ZEROFILL DEFAULT NULL,
    `gibbonDepartmentID` int(4) UNSIGNED ZEROFILL DEFAULT NULL,
    `gibbonRoleID` int(3) UNSIGNED ZEROFILL DEFAULT NULL,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerAudienceRuleID`),
    KEY `meetingsManagerDefinitionID` (`meetingsManagerDefinitionID`),
    KEY `gibbonYearGroupID` (`gibbonYearGroupID`),
    KEY `gibbonDepartmentID` (`gibbonDepartmentID`),
    KEY `gibbonRoleID` (`gibbonRoleID`),
    KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// Pure user input: the dates picked in the wizard for Single (exactly one row) or SelectedDates
// (one or more rows). Never written to by generation - meetingsManagerOccurrence is the resolved
// output, this is the input.
$moduleTables[] = "CREATE TABLE `meetingsManagerSelectedDate` (
    `meetingsManagerSelectedDateID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `date` date NOT NULL,
    PRIMARY KEY (`meetingsManagerSelectedDateID`),
    UNIQUE KEY `meetingsManagerDefinitionID` (`meetingsManagerDefinitionID`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// Generated/resolved output only, for every scheduleType including SelectedDates. Can exist as
// 'Planned' before generation runs. gibbonCalendarEventID is nullable (unset until generated) and
// unique once set, which is what keeps regeneration idempotent and stops duplicate Calendar events.
$moduleTables[] = "CREATE TABLE `meetingsManagerOccurrence` (
    `meetingsManagerOccurrenceID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `plannedDate` date NOT NULL,
    `plannedTimeStart` time NOT NULL,
    `plannedTimeEnd` time NOT NULL,
    `gibbonCalendarEventID` int(12) UNSIGNED ZEROFILL DEFAULT NULL,
    `status` enum('Planned','Generated','Cancelled','Moved') NOT NULL DEFAULT 'Planned',
    `timestampCreated` timestamp NULL DEFAULT NULL,
    `timestampModified` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerOccurrenceID`),
    UNIQUE KEY `meetingsManagerDefinitionID` (`meetingsManagerDefinitionID`,`plannedDate`),
    UNIQUE KEY `gibbonCalendarEventID` (`gibbonCalendarEventID`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// At most one exception per occurrence. Authoritative input to reconciliation - regeneration reads
// exceptions, it never overwrites them.
$moduleTables[] = "CREATE TABLE `meetingsManagerException` (
    `meetingsManagerExceptionID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerOccurrenceID` int(12) UNSIGNED ZEROFILL NOT NULL,
    `type` enum('Cancel','Move','Retime') NOT NULL,
    `newDate` date DEFAULT NULL,
    `newTimeStart` time DEFAULT NULL,
    `newTimeEnd` time DEFAULT NULL,
    `note` text,
    `gibbonPersonIDCreated` int(10) UNSIGNED ZEROFILL NOT NULL,
    `timestampCreated` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerExceptionID`),
    UNIQUE KEY `meetingsManagerOccurrenceID` (`meetingsManagerOccurrenceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// A definition-level human override of a specific candidate date, checked by MeetingDateResolver
// after every other willGenerate rule. Distinct from meetingsManagerException, which cancels/moves/
// retimes an occurrence that has already been generated - this decides whether a date is generated
// at all in the first place, in either direction: 'Exclude' vetoes a date that would otherwise
// generate (e.g. an "Off Timetable" week that isn't a School Closure but shouldn't hold this
// meeting), 'Include' forces one that otherwise wouldn't (e.g. publishing anyway on a School
// Closure day). At most one row per (definition, date) - once the requested state matches what the
// resolver would produce naturally, the row is deleted rather than kept as a redundant override.
$moduleTables[] = "CREATE TABLE `meetingsManagerDateOverride` (
    `meetingsManagerDateOverrideID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `meetingsManagerDefinitionID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `date` date NOT NULL,
    `type` enum('Exclude','Include') NOT NULL DEFAULT 'Exclude',
    `gibbonPersonIDCreated` int(10) UNSIGNED ZEROFILL NOT NULL,
    `timestampCreated` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`meetingsManagerDateOverrideID`),
    UNIQUE KEY `meetingsManagerDefinitionID` (`meetingsManagerDefinitionID`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// Add gibbonSettings entries
// gibbonCalendarEventType is not school-year scoped (unlike gibbonCalendar), so a single global
// setting is correct here. Resolved to the "Meeting" event type on first Settings save; left blank
// until then.
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`gibbonSettingID`, `scope`, `name`, `nameDisplay`, `description`, `value`) VALUES (NULL, 'Meetings Manager', 'gibbonCalendarEventTypeID', 'Calendar Event Type', 'The gibbonCalendarEventType used for generated meeting events. Resolved automatically to \"Meeting\" (created if necessary) the first time Settings is saved.', '');";

// Action rows
// One array per action. Meetings Manager is an authorised-staff-only tool - there is no
// general-staff viewing action. Management is split into a grouped _all/_my pair (mirroring core's
// Behaviour module convention exactly): _all is unrestricted (Admin by default), _my is restricted
// to meetings where the current person is gibbonPersonIDOrganiser (Teacher by default). Both share
// the identical URLList - isActionAccessible() only answers "can this role enter this page at all",
// while moduleFunctions.php's meetingsManagerCanManage()/meetingsManagerScope() (built on core's
// getHighestGroupedAction(), which orders by precedence) answer "which definitions may they touch".
$actionRows[] = [
    'name'                      => 'Manage Meetings_all',
    'precedence'                => '1',
    'category'                  => 'Manage Meetings',
    'description'               => 'Create, edit, and archive any meeting definition, and generate their native Calendar events.',
    'URLList'                   => 'meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_unarchiveProcess.php,meeting_manage_preview.php,meeting_manage_preview_excludeDateProcess.php,meeting_manage_preview_includeDateProcess.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php',
    'entryURL'                  => 'meeting_manage.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Manage Meetings_my',
    'precedence'                => '0',
    'category'                  => 'Manage Meetings',
    'description'               => 'Create, edit, and archive meeting definitions the user organises, and generate their native Calendar events. Cannot see or act on meetings organised by anyone else.',
    'URLList'                   => 'meeting_manage.php,meeting_manage_add.php,meeting_manage_addProcess.php,meeting_manage_edit.php,meeting_manage_editProcess.php,meeting_manage_edit_date_addProcess.php,meeting_manage_edit_date_deleteProcess.php,meeting_manage_edit_audience_addProcess.php,meeting_manage_edit_audience_deleteProcess.php,meeting_manage_archiveProcess.php,meeting_manage_unarchiveProcess.php,meeting_manage_preview.php,meeting_manage_preview_excludeDateProcess.php,meeting_manage_preview_includeDateProcess.php,meeting_manage_generateProcess.php,meeting_manage_refreshParticipantsProcess.php,meeting_manage_occurrences.php,meeting_manage_occurrence_exception.php,meeting_manage_occurrence_exceptionProcess.php,meeting_manage_occurrence_exception_deleteProcess.php',
    'entryURL'                  => 'meeting_manage.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'N',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];
$actionRows[] = [
    'name'                      => 'Manage Meetings Manager Settings',
    'precedence'                => '0',
    'category'                  => 'Settings',
    'description'               => 'Configure the native Calendar event type used for generated meetings.',
    'URLList'                   => 'settings.php,settingsProcess.php',
    'entryURL'                  => 'settings.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// No hooks - Meetings Manager doesn't hook into any core dashboard/profile extension point.
