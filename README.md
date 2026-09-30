# Campus Event Hub

A campus event platform for La Consolacion University Philippines. Students browse every event on one **Event Calendar** (click a date to see all of its events), register (with capacity, deadline, audience and year-level rules), get notified, see each organization's officers, and evaluate the events they attended. **Moderators** run their own organization's events. Admins create and publish events (categorized by audience and venue type), build each event's schedule, track registrations and attendance, print attendance sheets, read evaluation results, see event counts per week / month / semester / school year on the Admin Dashboard and Reports, manage categories, departments, organizations, officers, the school calendar and user roles, and **import Excel files** to verify students' School IDs and attendance against the system.

**Stack:** plain PHP 8 + HTML/CSS/JS (no framework), served by XAMPP. All data, logins and images live in **Supabase** (project "Thesis").

## Run it locally

1. Install XAMPP and start **Apache** (MySQL isn't used).
2. Make the project reachable from `htdocs`, either by copying the folder or (better) by linking it so edits show up immediately. Run in an **admin** Command Prompt:
   ```
   mklink /J C:\xampp\htdocs\campus-event-calendar-system C:\path\to\campus-event-calendar-system
   ```
3. Open <http://localhost/campus-event-calendar-system/public/index.php>.

The site works from any folder name; links are built from `BASE_URL`, which is detected automatically (`config/supabase.php`).

## Folder structure

```
config/supabase.php     All Supabase access + helpers (auth, events, registrations, admin, uploads,
                        departments, school calendar, reports, evaluations)
includes/               Shared page parts: nav (the sidebar on EVERY page) + topbar, footer, event-card,
                        account-fields.php (student/faculty fields + irregular pop-up of sign-up and My Profile),
                        spreadsheet.php (reads .xlsx / .csv for Import & Verify), charts.php, evaluation-fields.php
public/                 Everything the browser can reach
  index.php             Home (live stats, upcoming events, About)
  events.php            Event Calendar: month view, one filter bar, click a date for all its events;
                        My Events (?mine=1)
  organizations.php     Organizations with department, officers and upcoming events
  event-details.php     One event: register/cancel, schedule, "Evaluate this event", other events
  enduser/              Student pages: dashboard.php, profile.php, evaluate.php
  admin/                Admin pages:
    dashboard.php         Admin Dashboard: events this week / month / semester / school year
    reports.php           Reports: filters, per month / week / semester / department / organization, print, CSV
    event-organizer.php   Create / edit / publish events (audience, year levels, venue type) — admins and moderators
    import.php            Import & Verify: Excel/CSV student list vs. accounts, attendance sheet vs. registrations
    event-programs.php    An event's schedule
    event-registrations.php  Registrations + attendance (one by one or ticked rows), CSV
    attendance-print.php  Printable attendance sheet (for signing) / attendance report (A4)
    event-evaluations.php Evaluation results of one event (print, CSV, remind attendees)
    evaluation-form.php   The one evaluation form used for every event
    admin-taxonomy.php    Categories, departments, organizations
    users.php             Users & Roles: roles (student/faculty, moderator, admin), School IDs, verification
    organization-officers.php, school-calendar.php
  auth/                 login, register, forgot-password, callback (Google + reset links), logout
  api/                  Small JSON endpoints: presence (Active Users), notifications (mark read), stats,
                        schedule-check (other events on the chosen dates / clashes, for the event form)
  assets/css            style.css (shared) + admin.css, auth.css, calendar.css, event-details.css, print.css
  assets/js             main.js, sidebar.js, calendar.js, account.js, forms.js, notifications.js,
                        presence.js, auth.js, charts.js
  assets/images         school-logo.png, login/ and event-details/ hold numbered placeholder images (see
                        the IMAGE SLOT comments in the PHP/CSS) — replace a file, keep its name
supabase/migrations/    SQL of database changes made from this repo, oldest first. Run any that aren't applied
                        yet in Supabase → SQL Editor (each can be run again safely).
```

### Replace the placeholder school logo

`public/assets/images/school-logo.png` is a grey "SCHOOL LOGO" placeholder. Replace it with the real LCUP logo (square PNG, transparent background works best, keep the file name). It appears in the header, sidebar, footer, login pages, reports and every printout. Organization logos are uploaded per organization in **Admin → Categories & Orgs → Organizations**.

## Supabase

Everything is in the **Thesis** project. The anon key in `config/supabase.php` is public by design: what a request may do is decided by the database's Row Level Security (RLS) rules, and pages that act for a user send **that user's** login token.

**Tables:** `profiles`, `school_ids`, `departments`, `organizations`, `organization_officers`, `categories`, `events`, `event_programs`, `event_registrations`, `notifications`, `user_presence`, `academic_terms` (school calendar), `evaluation_questions`, `event_evaluations`, `event_evaluation_answers`. **View:** `event_report` (one row per event with its school year, semester, department and registration / attendance / evaluation totals). The full column list is at the top of `config/supabase.php`.

**Rules enforced by the database** (so they hold no matter how a request is made):

| Rule | Where |
|---|---|
| Only admins can change anyone's `role`; moderators can't change their organization; the student / faculty choice is fixed once made | `protect_profile_role()` trigger on `profiles` |
| A profile is created automatically on sign-up (student / faculty, department, organization, year levels, course; the School ID goes to `school_ids`) | `handle_new_user()` trigger on `auth.users` |
| School IDs are unique and private: the student, admins, and moderators whose events the student joined can see one; students can fix theirs until an admin verifies it | `school_ids` unique constraint + RLS |
| Registration only when the event is published, registration is enabled, before the deadline, not ended, and not full | `check_event_registration()` trigger on `event_registrations` |
| Registration only for people the event is for: students-only / faculty-only audience, and the event's year levels (irregular students: any year level they have subjects in) | same trigger |
| Students can only cancel / re-register; only admins and the event's moderators mark attended / no-show | same trigger |
| No duplicate events (same title on the same date) and no double-booked venues (same venue, overlapping date and time; online events excluded) | `check_event_schedule()` trigger on `events` |
| Published events become `completed` once they end | `complete_ended_events()` (the site calls it every few minutes) |
| Categories / organizations can't be deleted while an event uses them; departments while an organization uses them | foreign keys `ON DELETE RESTRICT` |
| Only admins manage categories, departments, organizations, the school calendar and the evaluation form, and delete events | RLS policies using `is_admin()` |
| Moderators create / edit / publish / cancel only their own organization's events, and manage those events' schedules, registrations, notifications and their organization's officers | RLS policies using `is_moderator()`, `can_manage_event()` |
| An event's `venue_type` is `online` exactly when `is_online` is true | `sync_event_venue_type()` trigger on `events` |
| Marking someone Attended records the time (`attended_at`) | `set_attended_at()` trigger on `event_registrations` |
| Only people marked Attended can evaluate an event, once, with every required question answered | `submit_event_evaluation()` (the only way to save an evaluation) |
| Evaluation answers can't be edited; a question that has answers can't be deleted (only hidden) | no update policies; foreign key `ON DELETE RESTRICT` |
| School-calendar terms can't overlap; one row per semester per school year | exclusion + unique constraints on `academic_terms` |

**Functions:** `site_stats()` (home-page counters), `event_registration_counts()` (the "N going" numbers, without revealing who), `touch_presence()` / `clear_presence()` (Active Users), `notify_event()` (admins and the event's moderators send notifications to everyone or to an event's registrants), `school_period(date)` (school year + semester of a date), `submit_event_evaluation()` (saves an attendee's evaluation), `is_moderator()` / `can_manage_event()` (role checks used by the rules), `school_id_taken()` (sign-up check), `complete_ended_events()`.

**Storage buckets** (public read, admin upload): `event-posters`, `org-logos`, `officer-photos`.

### Making the first admin

Roles can only be changed by an admin, so the very first one is set in the Supabase **SQL Editor**:

```sql
update public.profiles set role = 'admin' where full_name = 'Your Name';
```

After that, admins promote others (to moderator or admin) from **Admin → Users & Roles**.

### Supabase dashboard settings

| Setting | Where | Why |
|---|---|---|
| Add `http://localhost/campus-event-calendar-system/public/auth/callback.php` (and your real site URL later) | Authentication → URL Configuration → Redirect URLs | Password-reset and Google links must be allowed to come back to the site |
| Turn on Google, with a Google OAuth client ID/secret | Authentication → Sign In / Providers → Google | Until then the Google button shows "not switched on yet" |
| Custom SMTP | Authentication → Emails → SMTP Settings | Supabase's built-in mailer only emails the project's team members, so students won't get reset emails without it |
| Leaked password protection (recommended) | Authentication → Settings | Blocks passwords known from data breaches |
| "Confirm email" | Authentication → Sign In / Providers → Email | Currently **off** (sign-up logs in immediately); turn on for production |

## How key features work

- **Login sessions:** Supabase issues a 1-hour token; `session_access_token()` renews it automatically with the refresh token, so users stay logged in.
- **Active Users:** logged-in pages ping `api/presence.php` every 30 s; closing the tab or logging out removes the user right away; anyone silent for 2 minutes drops off.
- **Notifications:** publishing a new event with "Send Notification to Users" ticked notifies everyone; saving changes to a published event notifies its registrants; cancelling an event always notifies its registrants. Opening the bell marks them read.
- **Security basics:** login redirects only go to paths on this site; the session cookie is `HttpOnly` + `SameSite=Lax` (blocks cross-site form posts); every value printed on a page is escaped; CSV cells that start with `=`, `+`, `-` or `@` are neutralized so spreadsheets don't run them.
- **Event categories:** every event has an **audience** (Students, Faculty, or Students & Faculty) and a **venue type** (On-campus, Off-campus, or Online/Webinar), and can be limited to some **year levels** (none ticked = every year level). Online events need the webinar link; on- and off-campus events need a venue. They show on event cards and pages and can be filtered on the Event Calendar (filtering "For students" also shows events for students & faculty).
- **Event Calendar (one view):** `events.php` is a month calendar with a single filter bar (keyword, category, organization, audience, venue). Clicking a date lists every event on it beside the calendar (no reload); ‹ › change month. Searching jumps to the month of the next match. The dashboard's small calendar lists a clicked day's events too and links to the full calendar. The old dashboard Filters box and the Events page's extra filters (type, when, sort) were removed.
- **Same sidebar everywhere:** home, calendar, organizations, student, moderator and admin pages all use the same sidebar and top bar (`includes/nav.php`, `includes/topbar.php`); visitors who aren't logged in see Log in / Sign up there. Only the login / sign-up screens and printouts are full-screen.
- **Accounts:** sign-up asks *student or faculty*, **department** and **organization** (required; the organization list shows the chosen department's organizations). Students also give their **School ID** (unique; letters, digits, dashes, e.g. 2023-00123), **course**, **highest year level**, and **regular or irregular**. Choosing *Irregular* opens a pop-up to tick every year level they have subjects in, with the registration rules for irregular students. Accounts missing any of this (older accounts, Google sign-ins) are sent to My Profile to complete it before anything else. Admins are exempt.
- **Roles:** *Student / Faculty* (browse, register, evaluate), *Moderator* (their organization's events: create, edit, publish, cancel, schedules, registrations, attendance, printouts, evaluation results, attendance imports, officers), *Admin* (everything, including deleting events, reports, the evaluation form, categories / departments / organizations, the school calendar, users and School IDs). Admins change roles on **Users & Roles**, which also shows a table of what each role can do. A moderator needs an organization.
- **No duplicates:** saving an event with the same title on the same date as another, or at a venue already booked at that time, is refused with the other event's name. While filling in the form, the other events on the chosen dates are listed under the date fields. School-calendar terms can't overlap either.
- **Forms start clean:** a published / saved event goes back to a blank form. After an error, the form shows what was typed once; refreshing, or leaving and coming back, clears it (`remember_form()` / `recall_form()`, `assets/js/forms.js`).
- **Completed automatically:** events are marked **Completed** once they end (a student's participation shows **Completed** once they attended and evaluated). *Mark Completed* can still close an event early.
- **Import & Verify (Excel):** upload an .xlsx or .csv file (first sheet; a header row naming the columns — templates are downloadable). *Student list → accounts* (admins) compares the registrar's list with the accounts by School ID (or name): matched, details that differ, no account yet, accounts not on the list, duplicates; matched accounts can be marked **Verified** in one click. *Attendance sheet → registrations* (admins and the event's moderators) marks everyone registered who's on the sheet as Attended, lists walk-ins, and can mark registered people who aren't on the sheet as No-show. Results download as CSV.
- **Departments:** each organization belongs to a department (Admin → Categories & Orgs → Departments), so reports can be filtered by department; accounts belong to a department too, and the Organizations page is grouped by department.
- **School calendar:** an event counts in the school year and semester its **start date** falls in. Admin → School Calendar sets each term's dates; any date outside every term follows the default calendar (1st semester Aug–Dec, 2nd semester Jan–May, summer Jun–Jul).
- **Admin Dashboard and Reports:** counts of events this week (Mon–Sun), this month, this semester and this school year, split by venue type and audience. Reports filter by school year, semester, department, organization, audience and venue type and break the numbers down per month, week, semester, department and organization, with the full event list. Cancelled events are counted separately and left out of every other number. *Registered* = everyone who signed up and didn't cancel; *attendance rate* = attended ÷ registered, for events that have ended. Reports print on A4 (with the school header and signature lines) and download as CSV.
- **Attendance:** on an event's Registrations page, mark people Attended / No-show one by one or tick several rows. **Print attendance** gives an A4 sheet: an *attendance sheet* to sign at the event (registered people + blank rows for walk-ins) or an *attendance report* afterwards (attendees with their time in, plus totals).
- **Event evaluation:** one standard form is used for every event (Admin → Evaluation Form; replace the default questions with the school's official form). When someone is marked Attended they get a notification, and the event page and their dashboard show **Evaluate this event**. Ratings use 5 Excellent · 4 Very Satisfactory · 3 Satisfactory · 2 Fair · 1 Poor; results show each statement's mean and verbal interpretation (4.21–5.00 Excellent, 3.41–4.20 Very Satisfactory, 2.61–3.40 Satisfactory, 1.81–2.60 Fair, 1.00–1.80 Poor) and the written answers, without names. Results print and download as CSV; **Remind attendees** notifies anyone who hasn't answered yet.
