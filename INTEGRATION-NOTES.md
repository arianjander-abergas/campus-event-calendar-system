# Event Details — integrated into your real project

This replaces the earlier standalone mock-up with a version wired directly
into the files you uploaded (real `config/supabase.php`, real `css/style.css`
grid system, real auth via `require_login()` / `current_user()`).

## New file
- **`event-details.php`** — reuses the exact same `.dashboard-shell` layout
  as `dashboard.php` (sidebar / topbar / main / right-rail), so it looks and
  behaves consistently: same sidebar nav (now with "Event" highlighted + an
  "Event Details" sub-link), same topbar user-chip/avatar/logout, same
  `.event-card` / `.rail-card` classes. The right rail is reused to hold the
  Morning/Afternoon schedule instead of announcements.
  Open it as `event-details.php?id=<event uuid>`.

## Changed files
- **`config/supabase.php`** — added three functions, following the exact
  pattern your `create_profile()` / `ensure_profile_exists()` already use
  (call `supabase_request()` with the logged-in user's own access token so
  RLS applies as that user):
  - `register_for_event($eventId)` — checks for a duplicate registration,
    then inserts into `event_registrations`.
  - `is_registered($eventId)` — powers the "Registered ✓" disabled state.
  - `get_registration_count($eventId)` — shown next to the seats line.
  Also added empty-array mock fallbacks for `event_registrations` and
  `notifications` so the page still renders if Supabase is unreachable.
- **`css/style.css`** — appended one new section at the end
  (`EVENT DETAILS PAGE`) with the hero card, programs grid, schedule list,
  breadcrumb, and notification dropdown styles. Nothing existing was
  removed or renamed.
- **`js/main.js`** — `wireEventCardClicks()` now actually navigates:
  clicking any `.event-card` with a `data-event-id` sends you to
  `event-details.php?id=...`, instead of just logging to the console.
- **`dashboard.php`** / **`index.php`** — one-line change each: their event
  card loops now output `data-event-id="<?= $ev['id'] ?>"` so the click
  above has something to navigate with.
- **`assets/`** — added placeholder images matching your existing naming
  convention (flat files, real `.jpg`s, not SVGs) so nothing breaks if your
  environment is strict about content-type: `campus-hero.jpg`,
  `event-pace.jpg`, `event-osh.jpg`, `event-genz.jpg`, `event-talk.jpg`,
  `event-liveband.jpg`, `event-tawag.jpg` (covers everything already
  referenced by `index.php`/`dashboard.php`/`supabase_mock_data()`), plus
  `event-hero-1/2/3.jpg` (gallery), `program-seminar/contest/social.jpg`,
  `org-jpssite.jpg`, `avatar-placeholder.jpg`, and `logo-mark.svg`.

## Why Register is a form POST, not a JS insert
Your `js/supabase-client.js` already has `db.registerForEvent()`, but your
auth session lives in PHP (`$_SESSION['access_token']`, set by
`supabase_auth_signin()`), not in the browser's `supabase-js` client — that
client has no signed-in session. If `event_registrations` has an RLS policy
like `auth.uid() = user_id` (recommended, see below), an anonymous
client-side insert would fail. So the Register button submits a normal PHP
form back to `event-details.php`, which calls `register_for_event()`
server-side using the same token-authenticated pattern your profile-creation
code already relies on.

## One-time Supabase setup (same idea as `AUTH_SETUP.md`'s `profiles` policies)

```sql
create policy "Users can register themselves"
  on event_registrations for insert
  with check (auth.uid() = user_id);

create policy "Users can view their own registrations"
  on event_registrations for select
  using (auth.uid() = user_id);
```

## Two things flagged, not changed
1. **Table naming mismatch already in your codebase**: `config/supabase.php`'s
   schema comment says `registrations` / `announcements`, but
   `js/supabase-client.js` (and the ER diagram this was built from) use
   `event_registrations` / `notifications`. The new PHP functions use
   `event_registrations` to match the diagram. `dashboard.php`'s existing
   "Announcements" panel is left untouched on `announcements` — worth
   confirming which of these tables actually exists in your Supabase project.
2. **Programs / Schedule** still aren't backed by real tables (same gap as
   before) — rendered from placeholder arrays at the top of
   `event-details.php`, clearly commented, matching the shape you'd need for
   `event_programs` / `event_schedule_items` tables if you add them later.
