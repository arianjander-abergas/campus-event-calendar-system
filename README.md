# Campus Event Hub

A campus event platform for La Consolacion University Philippines. Students browse and search events, register (with capacity and deadline rules), get notified, and see each organization's officers. Admins create and publish events, build each event's schedule, track registrations and attendance, and manage categories, organizations, officers and user roles.

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
config/supabase.php     All Supabase access + helpers (auth, events, registrations, admin, uploads)
includes/               Shared page parts: header/footer (public pages), nav + topbar (dashboard pages), event-card
public/                 Everything the browser can reach
  index.php             Home (live stats, upcoming events, About)
  events.php            Search / filters / "View All" / My Events (?mine=1)
  organizations.php     Organizations with officers and upcoming events
  event-details.php     One event: register/cancel, schedule, other events
  enduser/              Student pages: dashboard.php, profile.php
  admin/                Admin pages: events, schedule (event-programs), registrations,
                        categories & orgs, officers, users
  auth/                 login, register, forgot-password, callback (Google + reset links), logout
  api/                  Small JSON endpoints: presence (Active Users), notifications (mark read), stats
  assets/css            style.css (shared) + admin.css, auth.css, event-details.css
  assets/js             main.js, sidebar.js, notifications.js, presence.js, auth.js
  assets/images         login/ and event-details/ hold numbered placeholder images (see the IMAGE SLOT
                        comments in the PHP/CSS) — replace a file, keep its name
```

## Supabase

Everything is in the **Thesis** project. The anon key in `config/supabase.php` is public by design: what a request may do is decided by the database's Row Level Security (RLS) rules, and pages that act for a user send **that user's** login token.

**Tables:** `profiles`, `organizations`, `organization_officers`, `categories`, `events`, `event_programs`, `event_registrations`, `notifications`, `user_presence`. The full column list is at the top of `config/supabase.php`.

**Rules enforced by the database** (so they hold no matter how a request is made):

| Rule | Where |
|---|---|
| Only admins can change anyone's `role` | `protect_profile_role()` trigger on `profiles` |
| A profile is created automatically on sign-up (with year level, course, organization) | `handle_new_user()` trigger on `auth.users` |
| Registration only when the event is published, registration is enabled, before the deadline, not ended, and not full | `check_event_registration()` trigger on `event_registrations` |
| Students can only cancel / re-register; only admins mark attended / no-show | same trigger |
| Categories / organizations can't be deleted while an event uses them | foreign keys `ON DELETE RESTRICT` |
| Only admins create/edit events, programs, categories, organizations, officers | RLS policies using `is_admin()` |

**Functions:** `site_stats()` (home-page counters), `event_registration_counts()` (the "N going" numbers, without revealing who), `touch_presence()` / `clear_presence()` (Active Users), `notify_event()` (admins send notifications to everyone or to an event's registrants).

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
- **Security basics:** login redirects only go to paths on this site; the session cookie is `HttpOnly` + `SameSite=Lax` (blocks cross-site form posts); every value printed on a page is escaped.
