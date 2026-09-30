# Campus Event Hub

A campus event platform for La Consolacion University Philippines. Students browse and search events, register (with capacity and deadline rules), get notified, see each organization's officers, and evaluate the events they attended. Admins create and publish events (categorized by audience and venue type), build each event's schedule, track registrations and attendance, print attendance sheets, read evaluation results, see event counts per week / month / semester / school year on the Admin Dashboard and Reports, and manage categories, departments, organizations, officers, the school calendar and user roles.

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
includes/               Shared page parts: header/footer (public pages), nav + topbar (dashboard pages),
                        event-card, charts.php (dashboard/report charts), evaluation-fields.php (the form)
public/                 Everything the browser can reach
  index.php             Home (live stats, upcoming events, About)
  events.php            Search / filters / "View All" / My Events (?mine=1)
  organizations.php     Organizations with department, officers and upcoming events
  event-details.php     One event: register/cancel, schedule, "Evaluate this event", other events
  enduser/              Student pages: dashboard.php, profile.php, evaluate.php
  admin/                Admin pages:
    dashboard.php         Admin Dashboard: events this week / month / semester / school year
    reports.php           Reports: filters, per month / week / semester / department / organization, print, CSV
    event-organizer.php   Create / edit / publish events (audience + venue type)
    event-programs.php    An event's schedule
    event-registrations.php  Registrations + attendance (one by one or ticked rows), CSV
    attendance-print.php  Printable attendance sheet (for signing) / attendance report (A4)
    event-evaluations.php Evaluation results of one event (print, CSV, remind attendees)
    evaluation-form.php   The one evaluation form used for every event
    admin-taxonomy.php    Categories, departments, organizations
    organization-officers.php, users.php, school-calendar.php
  auth/                 login, register, forgot-password, callback (Google + reset links), logout
  api/                  Small JSON endpoints: presence (Active Users), notifications (mark read), stats
  assets/css            style.css (shared) + admin.css, auth.css, event-details.css, print.css
  assets/js             main.js, sidebar.js, notifications.js, presence.js, auth.js, charts.js
  assets/images         school-logo.png, login/ and event-details/ hold numbered placeholder images (see
                        the IMAGE SLOT comments in the PHP/CSS) — replace a file, keep its name
supabase/migrations/    SQL of database changes made from this repo (already applied to "Thesis")
```

### Replace the placeholder school logo

`public/assets/images/school-logo.png` is a grey "SCHOOL LOGO" placeholder. Replace it with the real LCUP logo (square PNG, transparent background works best, keep the file name). It appears in the header, sidebar, footer, login pages, reports and every printout. Organization logos are uploaded per organization in **Admin → Categories & Orgs → Organizations**.

## Supabase

Everything is in the **Thesis** project. The anon key in `config/supabase.php` is public by design: what a request may do is decided by the database's Row Level Security (RLS) rules, and pages that act for a user send **that user's** login token.

**Tables:** `profiles`, `departments`, `organizations`, `organization_officers`, `categories`, `events`, `event_programs`, `event_registrations`, `notifications`, `user_presence`, `academic_terms` (school calendar), `evaluation_questions`, `event_evaluations`, `event_evaluation_answers`. **View:** `event_report` (one row per event with its school year, semester, department and registration / attendance / evaluation totals). The full column list is at the top of `config/supabase.php`.

**Rules enforced by the database** (so they hold no matter how a request is made):

| Rule | Where |
|---|---|
| Only admins can change anyone's `role` | `protect_profile_role()` trigger on `profiles` |
| A profile is created automatically on sign-up (with year level, course, organization) | `handle_new_user()` trigger on `auth.users` |
| Registration only when the event is published, registration is enabled, before the deadline, not ended, and not full | `check_event_registration()` trigger on `event_registrations` |
| Students can only cancel / re-register; only admins mark attended / no-show | same trigger |
| Categories / organizations can't be deleted while an event uses them; departments while an organization uses them | foreign keys `ON DELETE RESTRICT` |
| Only admins create/edit events, programs, categories, departments, organizations, officers, the school calendar and the evaluation form | RLS policies using `is_admin()` |
| An event's `venue_type` is `online` exactly when `is_online` is true | `sync_event_venue_type()` trigger on `events` |
| Marking someone Attended records the time (`attended_at`) | `set_attended_at()` trigger on `event_registrations` |
| Only people marked Attended can evaluate an event, once, with every required question answered | `submit_event_evaluation()` (the only way to save an evaluation) |
| Evaluation answers can't be edited; a question that has answers can't be deleted (only hidden) | no update policies; foreign key `ON DELETE RESTRICT` |
| School-calendar terms can't overlap; one row per semester per school year | exclusion + unique constraints on `academic_terms` |

**Functions:** `site_stats()` (home-page counters), `event_registration_counts()` (the "N going" numbers, without revealing who), `touch_presence()` / `clear_presence()` (Active Users), `notify_event()` (admins send notifications to everyone or to an event's registrants), `school_period(date)` (school year + semester of a date), `submit_event_evaluation()` (saves an attendee's evaluation).

**Storage buckets** (public read, admin upload): `event-posters`, `org-logos`, `officer-photos`.

### Making the first admin

Roles can only be changed by an admin, so the very first one is set in the Supabase **SQL Editor**:

```sql
update public.profiles set role = 'admin' where full_name = 'Your Name';
```

After that, admins promote others from **Admin → Users**.

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
- **Event categories:** every event has an **audience** (Students, Faculty, or Students & Faculty) and a **venue type** (On-campus, Off-campus, or Online/Webinar). Online events need the webinar link; on- and off-campus events need a venue. Both show on event cards and pages and can be filtered on the Events page (filtering "For students" also shows events for students & faculty).
- **Departments:** each organization belongs to a department (Admin → Categories & Orgs → Departments), so reports can be filtered by department.
- **School calendar:** an event counts in the school year and semester its **start date** falls in. Admin → School Calendar sets each term's dates; any date outside every term follows the default calendar (1st semester Aug–Dec, 2nd semester Jan–May, summer Jun–Jul).
- **Admin Dashboard and Reports:** counts of events this week (Mon–Sun), this month, this semester and this school year, split by venue type and audience. Reports filter by school year, semester, department, organization, audience and venue type and break the numbers down per month, week, semester, department and organization, with the full event list. Cancelled events are counted separately and left out of every other number. *Registered* = everyone who signed up and didn't cancel; *attendance rate* = attended ÷ registered, for events that have ended. Reports print on A4 (with the school header and signature lines) and download as CSV.
- **Attendance:** on an event's Registrations page, mark people Attended / No-show one by one or tick several rows. **Print attendance** gives an A4 sheet: an *attendance sheet* to sign at the event (registered people + blank rows for walk-ins) or an *attendance report* afterwards (attendees with their time in, plus totals).
- **Event evaluation:** one standard form is used for every event (Admin → Evaluation Form; replace the default questions with the school's official form). When someone is marked Attended they get a notification, and the event page and their dashboard show **Evaluate this event**. Ratings use 5 Excellent · 4 Very Satisfactory · 3 Satisfactory · 2 Fair · 1 Poor; results show each statement's mean and verbal interpretation (4.21–5.00 Excellent, 3.41–4.20 Very Satisfactory, 2.61–3.40 Satisfactory, 1.81–2.60 Fair, 1.00–1.80 Poor) and the written answers, without names. Results print and download as CSV; **Remind attendees** notifies anyone who hasn't answered yet.
