# Meetings Manager

Meetings Manager lets a small number of authorised staff define recurring school meetings once,
and have Gibbon generate the correct native Calendar events - with the right participants, on the
right dates - automatically. It reuses Gibbon's own Calendar, timetable, staff, department and
Space data rather than keeping a separate copy of any of it, so generated meetings appear on every
participant's Calendar and Timetable exactly like any other native Gibbon event.

Built for Gibbon `v31.0.00+`.

## Contents

- [What this module does](#what-this-module-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core concepts](#core-concepts)
- [Quick start](#quick-start)
- [Meeting status](#meeting-status)
- [Settings](#settings)
- [Creating a meeting](#creating-a-meeting)
- [Setting the audience](#setting-the-audience)
- [Preview](#preview)
- [Overriding a date](#overriding-a-date)
- [Generating and updating events](#generating-and-updating-events)
- [Managing individual occurrences](#managing-individual-occurrences)
- [Refreshing participants](#refreshing-participants)
- [Archiving and unarchiving a meeting series](#archiving-and-unarchiving-a-meeting-series)
- [Permissions](#permissions)
- [Troubleshooting](#troubleshooting)
- [What this module does not do](#what-this-module-does-not-do)
- [Support](#support)

## What this module does

- Lets you describe a meeting once - name, organiser, location, schedule, audience - as a **Meeting
  Definition**.
- Works out every real calendar date that meeting should occur on, using Gibbon's own school year,
  term, closure and timetable data - never by guessing or doing date arithmetic.
- Works out who should attend by re-checking Gibbon's actual staff, department, role and year group
  data every time, not by keeping a fixed list of names.
- Creates and keeps in sync the matching native Gibbon Calendar events, so meetings show up on
  every participant's Calendar and Timetable the normal way, at an Internal Space or an External
  location, exactly like any other Gibbon Calendar event.
- Lets you cancel, move, or retime a single occurrence without affecting the rest of the series.
- Lets you preview exactly what will happen - which dates, who's invited, what will change - before
  anything is written to the Calendar, and lets you deliberately override individual dates in
  either direction (force one in, or force one out).

## Requirements

- Gibbon `v31.0.00` or later.
- A Gibbon Admin account to install the module.
- The **Manage Meetings** permission (see [Permissions](#permissions)) to create and manage
  meetings.

## Installation

1. Copy the `Meetings Manager` folder into your Gibbon installation's `modules/` directory.
2. Log in as an Administrator and go to **Admin > System Admin > Manage Modules**.
3. Find **Meetings Manager** in the list and click **Install**.
4. Go to **Other > Meetings Manager > Meetings Manager Settings** and confirm the Calendar Event Type (see
   [Settings](#settings)) - it's configured automatically the first time you visit that page, so
   this step is just a sanity check, not a requirement.

No further setup is required. The first time you generate a meeting for an academic year, the
module automatically creates a "Meetings" calendar for that year if one doesn't already exist.

## Core concepts

Two things are worth understanding before you create your first meeting:

- **Meeting Definition** - what you configure: the meeting's name, organiser, location, schedule,
  and who should attend. This is what you see and edit in Meetings Manager.
- **Occurrence** - one actual, dated instance of that meeting, and the native Gibbon Calendar event
  that goes with it. You don't create these directly - they're generated from the Meeting
  Definition, and Meetings Manager keeps track of exactly which Calendar event belongs to which
  occurrence.

You configure the Definition once. Meetings Manager works out the Occurrences and keeps the
Calendar in sync with them every time you click **Update Generated Events**.

## Quick start

1. Go to **Other > Meetings Manager > Manage Meetings** and click **Add Meeting**.
2. Give it a name, confirm the organiser (defaults to you), choose a Location, and pick **Once** as
   the schedule type, with a date and time.
3. Save. You'll land back on the same page with an **Audience** panel now available.
4. Under **Add Audience Rule**, choose **Specific Staff**, pick one or two colleagues, and click
   **Add Rule**.
5. Click **Preview** (from the Manage Meetings list). Check the participant list and the single
   date look right.
6. Click **Create Meeting Series**.
7. Check your own Gibbon Calendar or Timetable - the meeting should now be there.

## Meeting status

The Manage Meetings list shows each meeting's status as a coloured pill:

| Status | Meaning |
|---|---|
| **Draft** | Saved, but never generated - nothing is on the Calendar yet |
| **Published** | Has been generated at least once - Calendar events exist |
| **Archived** | Archived (see [Archiving and unarchiving a meeting series](#archiving-and-unarchiving-a-meeting-series)) |

Once a meeting is Published, its list action changes from **Preview** to **Update** as a reminder
that generating again will update live Calendar events, not just create new ones for the first
time - both labels take you to the same Preview screen, where the actual Create/Update button
lives.

## Settings

**Other > Meetings Manager > Meetings Manager Settings** lets you choose which native Gibbon Calendar Event Type
generated meetings use. It defaults to "Meeting", which the module will create automatically if
your school doesn't already have one. You only need to visit this page if you want generated
meetings to use a different, already-configured event type instead.

## Creating a meeting

Go to **Other > Meetings Manager > Manage Meetings > Add Meeting**. You'll configure:

**Basic details** - Name (required) and Description (optional).

**Location** - choose **Internal** (the default) or **External**:

| Location Type | What you configure |
|---|---|
| **Internal** | A **Space** picked from Gibbon's own configured Spaces (required) - the same list used by core Calendar events |
| **External** | Free-text Location Detail, e.g. an address or an off-site venue name (optional) |

**Organiser** defaults to you; pick any other active staff member instead if needed.

**Schedule** - choose how the meeting repeats:

| Schedule type | What it's for | What you configure |
|---|---|---|
| **Once** | A single, one-off meeting | One date, start and end time |
| **Selected Dates** | An irregular set of dates, e.g. Heads of Department meetings that don't follow a fixed pattern | A start/end time now; the specific dates are added afterwards, on the Edit page |
| **Every Week** | An ordinary weekly meeting, e.g. "every Wednesday" | A day of the week, start/end time, and an optional date range (defaults to the full academic year) |
| **School Timetable Cycle** | A meeting tied to your school's actual timetable cycle, e.g. "every Week B Wednesday" | A Timetable Day (grouped by timetable - e.g. Middle & Upper School, Wednesday B), start/end time, and an optional date range |

**School Timetable Cycle is the one to use for A/B-week (or any multi-week-cycle) recurrence.**
It never guesses which week is "A" or "B" - it reads your school's actual **Tie Days to Dates**
configuration in Timetable Admin, so a holiday or timetable change is automatically reflected
without you doing anything. If you pick this option and see a warning that no dates are tied yet,
that's a Timetable Admin setup step, not a Meetings Manager problem - see
[Troubleshooting](#troubleshooting).

After saving, **Selected Dates** meetings let you add dates one at a time on the Edit page, and
**Once** meetings let you edit the single date directly on the same page.

## Setting the audience

Once a meeting has been saved (whether you've just created it or are editing an existing one), an
**Audience** panel appears on the same page - you never need a separate trip to a different screen.
Add one or more rules describing who should attend. You can combine as many as you like - the
final list is everyone matched by any rule, minus anyone excluded:

| Rule | Who it includes |
|---|---|
| **All Teaching Staff** | Every active teaching staff member |
| **All Staff** | Every active staff member, teaching or not |
| **Teachers of Selected Year Groups** | Teachers of courses belonging to the year group(s) you pick - select several at once |
| **Staff in Selected Departments** | Every member of the department(s) you pick - select several at once |
| **Department Coordinators** | The Coordinator of the department(s) you pick |
| **Members of Selected Roles** | Everyone holding the Gibbon Role(s) you pick, whether it's their primary role or an additional one - select several at once |
| **Specific Staff** | Named individual staff members you pick |
| **Exclude Individual** | Removes a specific person from the final list, even if another rule included them |

Picking several Year Groups, Departments, Roles, or Staff at once adds one rule per selection in a
single step - you don't need to repeat the form for each one.

Audience rules are re-checked every time you generate or refresh a meeting, so if someone joins or
leaves a department, the meeting's participant list can be brought up to date without editing the
rules at all - see [Refreshing participants](#refreshing-participants).

The organiser is always included as the meeting's Organiser, whether or not an audience rule also
matches them - you'll never see them listed twice.

## Preview

**Preview** (from the Manage Meetings list, or after saving a new meeting) shows you, before you
commit to anything:

- The meeting's details and a plain-language description of its schedule.
- The full resolved list of participants, and which rule brought each person in.
- Every candidate date, whether it will actually generate a meeting, and why not if it won't (a
  School Closure or a non-school day, for example).
- For an existing meeting, a summary of exactly what clicking **Update Generated Events** would do
  - how many events would be created, updated, or removed, and how the participant count would
  change (e.g. "Participants: 18 → 23") - all worked out before you click anything.

Warnings and closures are always shown, never hidden - a date that won't generate an event still
appears in the list, with the reason why. Off Timetable and Timing Change annotations (e.g. an exam
week or a shortened day) are shown too, but don't stop a date from generating on their own - they're
context for you to act on if needed.

A quick way to add or remove a participant is also available directly from Preview, without
needing to open Edit: use **Add Participant** below the participant list to include someone
specific, or select one or more people in the participant list and click **Remove** to take them
out. Both act the same as adding a Specific Staff or Exclude Individual audience rule from Edit -
they're just quicker to reach from here.

Nothing on this page writes to the Calendar except the explicit **Create Meeting Series** /
**Update Generated Events** button at the bottom.

## Overriding a date

Every candidate date on Preview has a checkbox, which always reflects what will actually happen for
that date and lets you deliberately override it in either direction:

- **Excluding a date that would normally generate** - untick the checkbox next to any date (e.g.
  one that clashes with a Parent-Teacher Conference week). The row is marked "Excluded: Manually
  Excluded" and will never generate a meeting until you tick it back on.
- **Including a date that would normally be excluded** - tick the checkbox next to a date that's
  shown as excluded (e.g. a School Closure or a non-school day). The row is marked "Manually
  Included", with the context making clear this is a deliberate override: *"This date would
  normally be excluded, but you have deliberately included it. (\<reason\>)"*.

In both cases, the choice is remembered for future previews and generations. Ticking a date back to
whatever it would naturally be (excluded or included) removes the override entirely, rather than
leaving a stale entry behind - so if circumstances later change (e.g. a date you excluded is
subsequently marked a School Closure anyway), the override doesn't silently keep pinning it to the
wrong state.

## Generating and updating events

Once you're happy with Preview, click **Create Meeting Series** (for a brand-new meeting) or
**Update Generated Events** (for one that's already been generated). This is the only action that
actually writes to the Calendar. Afterwards you'll see a summary of exactly what happened - events
created/updated/removed, participants resolved, and any dates excluded because the school was
closed or manually overridden.

Running this again at any time is always safe: unchanged meetings are left alone, and only what's
actually different gets updated. Past meetings are never rewritten, even if you later change the
Meeting Definition.

## Managing individual occurrences

Open **Occurrences** (from the Manage Meetings list) to see every generated meeting date and act
on individual ones:

- **Cancel Meeting** - the meeting stays visible on everyone's Calendar/Timetable, clearly marked
  Cancelled, rather than silently disappearing.
- **Move Meeting** - moves one occurrence to a different date.
- **Change Time** - changes the start/end time of one occurrence.
- **Restore Meeting** - undoes a cancellation, returning the meeting to its normal state.
- **Restore Original Schedule** - undoes a move or time change, returning the meeting to its
  originally planned date/time.

These exceptions survive future updates - regenerating the series won't accidentally un-cancel or
un-move something you've already changed.

This is different from excluding a date on Preview: excluding stops a date from ever being
generated in the first place, while Cancel/Move/Change Time act on a meeting that's already been
generated and is already on the Calendar.

## Refreshing participants

If staffing changes after a meeting series has already been generated - someone joins or leaves a
department, for example - use **Refresh Participants** (from the Manage Meetings list) to
re-resolve the audience rules and update participants on every future meeting in the series. Past
meetings are never touched, so historical attendance records stay accurate. This is a manual
action; nothing updates automatically.

## Archiving and unarchiving a meeting series

**Archive Meeting Series** removes future generated meetings from the Calendar but keeps
everything else: past meetings remain on the Calendar exactly as they were, and the Meeting
Definition itself - along with its full history - remains available under the **Archived** filter
on the Manage Meetings list, in case you need to refer back to it or reactivate the underlying
configuration later.

**Unarchive Meeting Series**, available from the Archived filter, brings a meeting series back to
Draft/Published status so it can be edited and generated again - no data is lost by archiving in
the first place, so this simply reverses the archive step.

## Permissions

| Action | What it allows | Default roles |
|---|---|---|
| **Manage Meetings_all** | Create, edit, preview, generate, archive/unarchive, and manage the audience of **any** meeting | Administrator |
| **Manage Meetings_my** | The same, but restricted to meetings where the current user is the Organiser - cannot see or act on meetings organised by anyone else | Teacher |
| **Manage Meetings Manager Settings** | Change the Calendar Event Type used for generated meetings | Administrator |

Meetings Manager is an authorised-staff-only tool - there is no general "view only" permission.
Like any Gibbon module, these can be granted to other roles from **Admin > User Admin > Roles &
Permissions** if your school wants specific staff (e.g. a Leadership Team or Office Manager role)
to be able to manage meetings without full Administrator access. A role can hold either grouped
permission, or both - if both, the unrestricted `_all` permission takes precedence.

## Troubleshooting

**"No timetable dates have been configured for this timetable within the selected range."**
This means Timetable Admin's **Tie Days to Dates** hasn't been run for the relevant period yet -
Meetings Manager deliberately never guesses at a cycle, so a School Timetable Cycle meeting can't
produce any dates until the ties exist. Go to **Timetable Admin > Tie Days to Dates**, tie the
dates for the relevant timetable, then reload Preview.

**"No dates tied to [day] were found... even though other days in this timetable are tied."**
The timetable itself is set up, but not for the specific day you selected - check you picked the
right one (e.g. Wednesday B rather than Wednesday A).

**A generated meeting is missing from the Calendar.**
If a generated event is deleted outside Meetings Manager, Preview and the Occurrences page will
show it as missing. Click **Update Generated Events** to repair it - Meetings Manager only ever
recreates events it can prove it owns, so this is always safe to run.

**I archived a series by mistake.**
Use **Unarchive Meeting Series** from the Archived filter on the Manage Meetings list - nothing is
permanently deleted by archiving, so this fully reverses it.

**A meeting won't save, and I picked Internal as the Location Type.**
A Space is required for Internal meetings - pick one from the Space dropdown before saving. If you
don't need a specific room, switch Location Type to External instead.

## What this module does not do

To keep the module focused and predictable, the following are intentionally out of scope:

- Google Calendar or Microsoft Calendar sync
- Email invitations or notifications
- Attendance tracking, minutes, or agendas
- Room/facility booking or conflict resolution
- Automatic, unattended participant refresh (it's always a deliberate, manual action)
- Meeting templates

## Support

Author: Steve Gillott. For issues or questions, please open an issue in this repository.
