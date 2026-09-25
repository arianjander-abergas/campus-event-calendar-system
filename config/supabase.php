<?php
/**
 * Supabase configuration + REST/Auth helpers.
 *
 * Data reads/writes go through PostgREST (supabase_request()).
 * Auth (signup/login/logout) goes through Supabase's GoTrue REST API
 * directly via cURL, since this app is server-rendered PHP rather than
 * a client-side JS app driving supabase-js for auth.
 *
 * Get/rotate these from: Supabase Dashboard -> Project Settings -> API
 */

// ---- Project credentials --------------------------------------------------
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://fgwaeugfkrljgbbgvaox.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc');

// Service role key should ONLY ever be used server-side (never sent to the browser).
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'YOUR-SUPABASE-SERVICE-ROLE-KEY');

// "Today" (events happening today, upcoming lists) is the Philippines' date.
date_default_timezone_set('Asia/Manila');

// ---- Site base URL ----------------------------------------------------------
// Web path of the public/ folder, e.g. '/campus-event-calendar-system/public'
// under XAMPP, or '' if public/ is the web root. Shared files (this config,
// includes/header.php) are loaded from pages in different subfolders, so they
// build links as BASE_URL . '/auth/login.php' instead of relative paths.
define('BASE_URL', getenv('APP_BASE_URL') ?: detect_base_url());

function detect_base_url(): string
{
    // realpath() on both sides so a junction/symlink (e.g. htdocs linked to
    // the GitHub folder) doesn't make the two paths look different.
    $publicDir = str_replace('\\', '/', (string) realpath(dirname(__DIR__) . '/public'));
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    // e.g. script '/.../public/admin/event-organizer.php' -> '/admin/event-organizer.php',
    // then strip that suffix off the URL path to get the base.
    if ($script !== '' && stripos($script, $publicDir) === 0) {
        $rel = substr($script, strlen($publicDir));
        if ($rel !== '' && substr($scriptName, -strlen($rel)) === $rel) {
            return substr($scriptName, 0, -strlen($rel));
        }
    }
    return '';
}

// ---- Real table shape (Thesis Supabase project) ----
// profiles(id uuid pk -> auth.users.id, full_name text, role user_role['admin'|'user'],
//          year_level int2 (1-5, null for faculty/admin), course text (e.g. 'BSIT'),
//          organization_id -> organizations.id, avatar_url text, phone text, created_at, updated_at)
// organizations(id uuid pk, name text unique, description text, logo_url text,
//               created_by uuid -> profiles.id, created_at, updated_at)
// organization_officers(id uuid pk, organization_id -> organizations.id, profile_id -> profiles.id (optional),
//                       full_name text, position text, photo_url text (officer-photos bucket),
//                       academic_year text (e.g. '2026-2027'), year_level int2, course text,
//                       sort_order int (President=1 ... PRO=7, Year Representatives=8+),
//                       is_active bool, created_at, updated_at)
// categories(id uuid pk, name text unique, description text,
//            created_by uuid -> profiles.id, created_at, updated_at)
// events(id uuid pk, title text, category_id -> categories.id, organization_id -> organizations.id,
//        event_type event_type['academic'|'seminar'|'workshop'|'sports'|'cultural'|'other'],
//        description text, start_date date, start_time time, end_date date, end_time time,
//        venue text, is_online bool, online_event_link text, address text, poster_url text,
//        registration_limit int, registration_deadline date, contact_email text, contact_number text,
//        enable_registration bool, is_published bool, send_notification bool,
//        status event_status['draft'|'published'|'cancelled'|'completed'],
//        created_by -> profiles.id, created_at, updated_at)
// event_programs(id uuid pk, event_id -> events.id, title text, description text, speaker text,
//                sort_order int, created_at, updated_at)   -- segments of an event (Schedule)
// event_registrations(id uuid pk, event_id -> events.id, user_id -> profiles.id,
//                      status registration_status['registered'|'waitlisted'|'cancelled'|'attended'|'no_show'],
//                      registered_at)  -- unique (event_id, user_id)
// notifications(id uuid pk, event_id -> events.id (nullable), user_id -> profiles.id (nullable),
//               title text, message text, status notification_status['pending'|'sent'|'failed'|'read'],
//               created_at, sent_at)
// stats(id uuid pk, label text, value text, sort_order int, created_at, updated_at)
// rpc event_registration_counts() -> (event_id, going): registration totals per published
//     event, without exposing who registered.
//
// IMPORTANT: role / status / event_type / registration_status / notification_status
// are real Postgres ENUM columns. Any INSERT/UPDATE using a value outside the
// listed set fails at the database level (PostgREST returns an error, and
// supabase_request() will hand back [] for that write). There is no
// `registrations` or `announcements` table — those were only ever a stale
// comment/leftover reference; the real tables are `event_registrations`
// and `notifications`.
//
// Required Supabase Auth setup (do this once in the dashboard):
// 1. Auth -> Providers -> Email: enable "Email" provider (password sign-in).
// 2. Auth -> Policies (or SQL editor), on `profiles`, add RLS policies:
//    - insert: with check (auth.uid() = id)
//    - select: using (true)  -- or auth.uid() = id if profiles should be private
//    - update: using (auth.uid() = id) with check (auth.uid() = id)
//    Without the insert policy, register.php's profile-creation step will fail.
// 3. If you want email confirmation OFF for easier testing, turn off
//    "Confirm email" under Auth -> Providers -> Email (re-enable for production).

// ---- Generic cURL helper ---------------------------------------------------
function supabase_http(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'status' => 0, 'data' => ['error' => $err]];
    }

    $decoded = json_decode($response, true);
    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => is_array($decoded) ? $decoded : ['raw' => $response],
    ];
}

/**
 * Minimal PostgREST request helper (hardened: never leaks a raw API-error
 * object back to callers that expect an array of rows).
 *
 * @param string $table          Table name, e.g. "events"
 * @param string $query          Raw PostgREST query string, e.g. "select=*,categories(name)&order=start_date.asc"
 * @param string $method         GET | POST | PATCH | DELETE
 * @param array|null $body       Payload for POST/PATCH
 * @param bool $useServiceKey    Use the service role key instead of anon key (server-only actions)
 * @param string|null $userToken If set, sent as the Authorization bearer instead of the anon/service
 *                                key, so requests run AS that user and RLS policies apply to them.
 * @return array Decoded JSON response (array of rows), or [] on any failure.
 */
function supabase_request(string $table, string $query = '', string $method = 'GET', ?array $body = null, bool $useServiceKey = false, ?string $userToken = null): array
{
    // Default to embedding the categories relationship whenever events is
    // queried without an explicit select, and restrict to published events
    // so draft/cancelled events don't leak onto public-facing pages. Pages
    // that need every status (e.g. a future admin dashboard) should pass
    // their own $query explicitly instead of relying on this default.
    if ($table === 'events' && $query === '' && $method === 'GET') {
        $query = 'select=*,categories(name),organizations(name,logo_url)&status=eq.published&order=start_date.asc';
    }

    $authKey = $useServiceKey ? SUPABASE_SERVICE_KEY : SUPABASE_ANON_KEY;
    $bearer = $userToken ?: $authKey;

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . ($query ? '?' . $query : '');

    $result = supabase_http($method, $url, [
        'apikey: ' . $authKey,
        'Authorization: Bearer ' . $bearer,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ], $body);

    // The Supabase access token expires after 1 hour, but the PHP login
    // session lasts longer. If the logged-in user's token has expired
    // (HTTP 401), swap the refresh token for a new one and retry once.
    static $retrying = false;
    if (!$result['ok'] && $result['status'] === 401 && !$retrying
        && $userToken !== null && $userToken === ($_SESSION['access_token'] ?? null)
        && supabase_refresh_session()) {
        $retrying = true;
        try {
            return supabase_request($table, $query, $method, $body, $useServiceKey, $_SESSION['access_token']);
        } finally {
            $retrying = false;
        }
    }
    if (!$result['ok'] && $result['status'] === 401 && $userToken !== null) {
        $GLOBALS['supabase_session_expired'] = true; // see supabase_session_expired()
    }

    // Any failure — network-level (status 0) OR an API-level error response
    // (missing table, bad query, RLS block, invalid enum value, etc.) — must
    // NEVER hand the raw error object back to callers expecting an array of
    // rows.
    if (!$result['ok']) {
        error_log(sprintf(
            '[supabase_request] %s %s failed (HTTP %d): %s',
            $method,
            $table,
            $result['status'],
            json_encode($result['data'])
        ));

        // No local sample data: reads AND writes return an empty array instead
        // of the raw error object, so callers using empty()/foreach() stay
        // safe and pages show an empty state (e.g. "No events yet").
        return [];
    }

    // Defensive guard: even a 2xx response should be an array of rows.
    if (!is_array($result['data'])) {
        error_log("[supabase_request] $method $table returned non-array data, coercing to []");
        return [];
    }

    return $result['data'];
}

// =============================================================================
// EVENT DATA HELPERS — everything comes from the Thesis Supabase project.
// There is no local sample data: if Supabase is unreachable or a table is
// empty, these return [] and the pages show an empty state instead.
// =============================================================================

/** Columns used for event cards/lists: category + organization joined in. */
const EVENT_CARD_SELECT = 'select=*,categories(name),organizations(name,logo_url)';

/** Published events happening today (start_date <= today <= end_date), earliest start time first. */
function get_events_today(int $limit = 5): array
{
    $today = date('Y-m-d');
    return supabase_request('events', EVENT_CARD_SELECT
        . '&status=eq.published&start_date=lte.' . $today . '&end_date=gte.' . $today
        . '&order=start_time.asc.nullslast&limit=' . $limit);
}

/**
 * Published events that haven't ended yet, soonest first — so anything
 * happening today always comes before later dates. $excludeId skips one
 * event (e.g. the one currently being viewed).
 */
function get_upcoming_events(int $limit = 3, ?string $excludeId = null): array
{
    $query = EVENT_CARD_SELECT . '&status=eq.published&end_date=gte.' . date('Y-m-d')
        . '&order=start_date.asc,start_time.asc.nullslast&limit=' . $limit;
    if ($excludeId) {
        $query .= '&id=neq.' . urlencode($excludeId);
    }
    return supabase_request('events', $query);
}

/** Programs (segments) of one event, in running order — the event details Schedule. */
function get_event_programs(string $eventId): array
{
    return supabase_request('event_programs', 'select=*&event_id=eq.' . urlencode($eventId) . '&order=sort_order.asc');
}

/**
 * Registration totals per event: ['<event uuid>' => 12, ...]. Uses the
 * event_registration_counts() database function, which returns counts only
 * (users can't read other people's registrations directly because of RLS).
 */
function get_registration_counts(): array
{
    static $counts = null; // one request per page load
    if ($counts === null) {
        $counts = [];
        foreach (supabase_request('rpc/event_registration_counts') as $row) {
            $counts[$row['event_id']] = (int) $row['going'];
        }
    }
    return $counts;
}

/** Upcoming published events with the most registrations ("Trending"); ties keep date order. */
function get_trending_events(int $limit = 3): array
{
    $events = supabase_request('events', EVENT_CARD_SELECT
        . '&status=eq.published&end_date=gte.' . date('Y-m-d') . '&order=start_date.asc');
    $counts = get_registration_counts();
    foreach ($events as &$ev) {
        $ev['going'] = $counts[$ev['id']] ?? 0;
    }
    unset($ev);
    usort($events, fn($a, $b) => $b['going'] <=> $a['going']);
    return array_slice($events, 0, $limit);
}

/** The logged-in user's registrations (not cancelled), newest first, with the event joined in. */
function get_my_registrations(int $limit = 5): array
{
    $user = current_user();
    if (!$user) return [];
    $rows = supabase_request(
        'event_registrations',
        'select=status,registered_at,events(id,title,start_date,end_date,start_time,venue,poster_url,status)'
            . '&user_id=eq.' . urlencode($user['id']) . '&status=neq.cancelled&order=registered_at.desc&limit=' . $limit,
        'GET', null, false, $_SESSION['access_token'] ?? null
    );
    return array_values(array_filter($rows, fn($r) => !empty($r['events'])));
}

// =============================================================================
// SITE STATS & ACTIVE USERS
// =============================================================================

/**
 * Live landing-page counters from the site_stats() database function:
 * published events, organizations, active users, registrations.
 * @return array<array{key:string,label:string,value:string}> [] if Supabase is unreachable
 */
function get_site_stats(): array
{
    $row = supabase_request('rpc/site_stats')[0] ?? null;
    if (!$row) return [];
    $labels = ['events' => 'Events', 'organizations' => 'Organizations', 'active_users' => 'Active Users', 'registrations' => 'Registrations'];
    $stats = [];
    foreach ($labels as $key => $label) {
        $stats[] = ['key' => $key, 'label' => $label, 'value' => format_stat_count($row[$key] ?? 0)];
    }
    return $stats;
}

/** 950 -> '950', 1234 -> '1.2k', 3400000 -> '3.4M' (same rule as formatStatCount() in main.js). */
function format_stat_count($n): string
{
    $n = (int) $n;
    if ($n < 1000) return (string) $n;
    if ($n < 1000000) return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k';
    return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.') . 'M';
}

/**
 * Active Users heartbeat for the logged-in user. Pages call this through
 * api/presence.php (see assets/js/presence.js): 'ping' every ~30s while a
 * page is open, 'leave' on logout / closing the tab. A user counts as
 * active while they've pinged in the last 2 minutes.
 */
function presence_ping(): void
{
    if (current_user()) {
        supabase_request('rpc/touch_presence', '', 'POST', null, false, $_SESSION['access_token'] ?? null);
    }
}

function presence_clear(): void
{
    if (current_user()) {
        supabase_request('rpc/clear_presence', '', 'POST', null, false, $_SESSION['access_token'] ?? null);
    }
}

/** <script> tags for the Active Users heartbeat — output only for logged-in users. */
function presence_script_tag(): string
{
    if (!current_user()) return '';
    return '<script>window.PRESENCE_URL = ' . json_encode(BASE_URL . '/api/presence.php') . ';</script>' . "\n"
        . '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/presence.js') . '"></script>';
}

/** '2026-09-20' -> 'Sep 20, 2026'; empty -> 'TBA'. */
function format_event_date(?string $date): string
{
    if (!$date) return 'TBA';
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

/** '13:30:00' -> '1:30 PM'; empty -> ''. */
function format_event_time(?string $time): string
{
    if (!$time) return '';
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}

// =============================================================================
// AUTH
// =============================================================================

/**
 * Register a new account via Supabase Auth (GoTrue), then create the
 * matching profiles row using the brand-new user's own access token so
 * RLS's "auth.uid() = id" insert policy is satisfied.
 *
 * @return array{ok:bool, message:string, needsEmailConfirmation?:bool}
 */
function supabase_auth_signup(string $email, string $password, string $fullName): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/signup', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], [
        'email' => $email,
        'password' => $password,
        'data' => ['full_name' => $fullName],
    ]);

    if (!$result['ok']) {
        $msg = $result['data']['error_description'] ?? $result['data']['msg'] ?? $result['data']['error'] ?? 'Sign up failed.';
        return ['ok' => false, 'message' => $msg];
    }

    $data = $result['data'];
    $user = $data['user'] ?? null;
    $accessToken = $data['access_token'] ?? null;

    if (!$user) {
        return ['ok' => false, 'message' => 'Unexpected response from auth server.'];
    }

    // Email confirmation is required by default in Supabase, so there may be
    // no access_token yet (user has to click the emailed link first).
    if (!$accessToken) {
        return ['ok' => true, 'message' => 'Account created. Please check your email to confirm before logging in.', 'needsEmailConfirmation' => true];
    }

    create_profile($user['id'], $fullName, $accessToken);
    start_user_session($user, $fullName, $data['refresh_token'] ?? null, $accessToken);

    return ['ok' => true, 'message' => 'Account created.'];
}

/**
 * Log in with email + password via Supabase Auth, store the session,
 * and make sure a profiles row exists (covers accounts created before
 * this flow existed, or where the signup-time insert failed).
 */
function supabase_auth_signin(string $email, string $password): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/token?grant_type=password', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], [
        'email' => $email,
        'password' => $password,
    ]);

    if (!$result['ok']) {
        $msg = $result['data']['error_description'] ?? $result['data']['msg'] ?? 'Invalid email or password.';
        return ['ok' => false, 'message' => $msg];
    }

    $data = $result['data'];
    $user = $data['user'] ?? null;
    $accessToken = $data['access_token'] ?? null;

    if (!$user || !$accessToken) {
        return ['ok' => false, 'message' => 'Unexpected response from auth server.'];
    }

    $fullName = $user['user_metadata']['full_name'] ?? explode('@', $email)[0];

    ensure_profile_exists($user['id'], $fullName, $accessToken);
    start_user_session($user, $fullName, $data['refresh_token'] ?? null, $accessToken);

    return ['ok' => true, 'message' => 'Logged in.'];
}

/**
 * Get a new access token using the refresh token saved at login, and store
 * both in the session. Called automatically by supabase_request() when the
 * access token has expired.
 *
 * @return bool false if there's no refresh token or Supabase rejected it
 *              (e.g. the user was signed out elsewhere) — log in again then.
 */
function supabase_refresh_session(): bool
{
    session_start_once();
    $refreshToken = $_SESSION['refresh_token'] ?? null;
    if (!$refreshToken) {
        return false;
    }

    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/token?grant_type=refresh_token', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], ['refresh_token' => $refreshToken]);

    if (!$result['ok'] || empty($result['data']['access_token'])) {
        error_log('[supabase_refresh_session] refresh failed (HTTP ' . $result['status'] . ')');
        return false;
    }

    $_SESSION['access_token'] = $result['data']['access_token'];
    $_SESSION['refresh_token'] = $result['data']['refresh_token'] ?? $refreshToken;
    return true;
}

/** True if a request this page load failed because the login couldn't be renewed. */
function supabase_session_expired(): bool
{
    return !empty($GLOBALS['supabase_session_expired']);
}

/** Invalidate the Supabase-side refresh token and clear the local session. */
function supabase_auth_signout(): void
{
    session_start_once();
    presence_clear(); // Active Users -1 right away
    $accessToken = $_SESSION['access_token'] ?? null;
    if ($accessToken) {
        supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/logout', [
            'apikey: ' . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . $accessToken,
        ]);
    }
    $_SESSION = [];
    session_destroy();
}

/**
 * Insert the profiles row for a freshly-created user.
 * NOTE: `role` is a real Postgres enum (`admin` | `user`) — it must be one
 * of those two literal values, never a display label like "Student".
 */
function create_profile(string $userId, string $fullName, string $accessToken): void
{
    supabase_request('profiles', '', 'POST', [
        'id' => $userId,
        'full_name' => $fullName,
        'role' => 'user',
    ], false, $accessToken);
}

/** Create the profile only if it doesn't already exist (used on login). */
function ensure_profile_exists(string $userId, string $fullName, string $accessToken): void
{
    $existing = supabase_request('profiles', 'select=id&id=eq.' . urlencode($userId), 'GET', null, false, $accessToken);
    if (empty($existing)) {
        create_profile($userId, $fullName, $accessToken);
    }
}

/**
 * Turn the stored enum role ('admin' | 'user') into the label the UI shows.
 * Keep the DB value a valid enum; keep the display word human-friendly.
 */
function role_display(string $role): string
{
    return $role === 'admin' ? 'Admin' : 'Student';
}

// =============================================================================
// EVENT REGISTRATION  (event_registrations table)
// =============================================================================
// These run the insert/select AS the logged-in user (their own access token,
// same pattern as create_profile()/ensure_profile_exists() above) so RLS can
// enforce "auth.uid() = user_id" the same way it does on `profiles`. If you
// haven't added that policy on event_registrations yet:
//
//   create policy "Users can register themselves"
//     on event_registrations for insert
//     with check (auth.uid() = user_id);
//
//   create policy "Users can view their own registrations"
//     on event_registrations for select
//     using (auth.uid() = user_id);

/**
 * Register the current session's user for an event.
 * NOTE: `status` is a real Postgres enum ('registered' | 'waitlisted' |
 * 'cancelled' | 'attended') — 'pending' is NOT a valid value and will make
 * this insert fail every time.
 *
 * @return array{ok:bool, message:string}
 */
function register_for_event(string $eventId): array
{
    $user = current_user();
    if (!$user) {
        return ['ok' => false, 'message' => 'Please log in to register for this event.'];
    }
    $token = $_SESSION['access_token'] ?? null;

    $existing = supabase_request(
        'event_registrations',
        'select=id&event_id=eq.' . urlencode($eventId) . '&user_id=eq.' . urlencode($user['id']),
        'GET', null, false, $token
    );
    if (!empty($existing)) {
        return ['ok' => false, 'message' => "You're already registered for this event."];
    }

    $result = supabase_request('event_registrations', '', 'POST', [
        'event_id' => $eventId,
        'user_id' => $user['id'],
        'status' => 'registered',
        'registered_at' => date('c'),
    ], false, $token);

    if (empty($result)) {
        return ['ok' => false, 'message' => 'Something went wrong registering you. Please try again.'];
    }
    return ['ok' => true, 'message' => "You're registered! We'll notify you with any updates."];
}

/** Whether the current logged-in user is already registered for an event. */
function is_registered(string $eventId): bool
{
    $user = current_user();
    if (!$user) return false;
    $rows = supabase_request(
        'event_registrations',
        'select=id&event_id=eq.' . urlencode($eventId) . '&user_id=eq.' . urlencode($user['id']),
        'GET', null, false, $_SESSION['access_token'] ?? null
    );
    return !empty($rows);
}

/** Total registration count for an event (any status), for a "N going" style line. */
function get_registration_count(string $eventId): int
{
    return get_registration_counts()[$eventId] ?? 0;
}

function session_start_once(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function start_user_session(array $authUser, string $fullName, ?string $refreshToken, string $accessToken): void
{
    session_start_once();
    session_regenerate_id(true);

    // Pull the role from profiles so it reflects reality (defaults to 'user').
    $profile = supabase_request('profiles', 'select=full_name,role&id=eq.' . urlencode($authUser['id']), 'GET', null, false, $accessToken);
    $role = $profile[0]['role'] ?? 'user';
    $profileName = $profile[0]['full_name'] ?? $fullName;

    $_SESSION['access_token'] = $accessToken;
    $_SESSION['refresh_token'] = $refreshToken;
    $_SESSION['user'] = [
        'id' => $authUser['id'],
        'email' => $authUser['email'],
        'full_name' => $profileName,
        'role' => $role, // raw enum value ('admin' | 'user') — use role_display() when showing this in the UI
        'avatar_url' => null,
    ];
}

/**
 * Returns the logged-in user's profile array, or null if nobody is logged in.
 * Pages that require auth should call require_login() instead of checking
 * this directly.
 */
function current_user(): ?array
{
    session_start_once();
    return $_SESSION['user'] ?? null;
}

/** Redirect to the login page (preserving where the user was headed) if not logged in. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $dest = urlencode($_SERVER['REQUEST_URI'] ?? BASE_URL . '/enduser/dashboard.php');
        header('Location: ' . BASE_URL . '/auth/login.php?redirect=' . $dest);
        exit;
    }
    return $user;
}

/**
 * Require an admin session. Non-admins (including logged-out visitors)
 * get bounced to the dashboard. Relies on `profiles.role` (enum: 'admin' |
 * 'user') — the same value Supabase's `is_admin()` RLS helper checks, so
 * what this function allows through and what the database allows through
 * always agree.
 */
function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        header('Location: ' . BASE_URL . '/enduser/dashboard.php');
        exit;
    }
    return $user;
}

// =============================================================================
// ADMIN — EVENT MANAGEMENT (event-organizer.php)
// =============================================================================
// All writes run AS the logged-in admin (their own access token), same
// pattern as everything else in this file. This is what actually lets the
// write through: Supabase's RLS policies on `events` require is_admin() to
// be true for insert/update/delete — an admin's own token satisfies that,
// the anon key alone never will.

/** All events, newest first, for the admin "manage events" table. Every status, not just published. */
function get_all_events_admin(): array
{
    $token = $_SESSION['access_token'] ?? null;
    return supabase_request(
        'events',
        'select=*,categories(name),organizations(name)&order=created_at.desc',
        'GET', null, false, $token
    );
}

/** Single event by id (any status), for pre-filling the edit form. */
function get_event_admin(string $eventId): ?array
{
    $token = $_SESSION['access_token'] ?? null;
    $rows = supabase_request(
        'events',
        'select=*&id=eq.' . urlencode($eventId) . '&limit=1',
        'GET', null, false, $token
    );
    return $rows[0] ?? null;
}

/**
 * Create a new event.
 * @param array $fields Column => value pairs (already validated/typed by the caller).
 * @return array{ok:bool, message:string, id?:string}
 */
function create_event_admin(array $fields): array
{
    $user = current_user();
    $token = $_SESSION['access_token'] ?? null;
    $fields['created_by'] = $user['id'] ?? null;

    $result = supabase_request('events', '', 'POST', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => supabase_session_expired()
            ? 'Your login session has expired. Please log out and log in again.'
            : 'Could not create the event. Please check the required fields and try again.'];
    }
    return ['ok' => true, 'message' => 'Event created.', 'id' => $result[0]['id'] ?? null];
}

/** Update an existing event. @return array{ok:bool, message:string} */
function update_event_admin(string $eventId, array $fields): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('events', 'id=eq.' . urlencode($eventId), 'PATCH', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => supabase_session_expired()
            ? 'Your login session has expired. Please log out and log in again.'
            : 'Could not save changes. Please try again.'];
    }
    return ['ok' => true, 'message' => 'Event updated.'];
}

/** Delete an event. @return array{ok:bool, message:string} */
function delete_event_admin(string $eventId): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('events', 'id=eq.' . urlencode($eventId), 'DELETE', null, false, $token);
    // A successful DELETE with Prefer: return=representation returns the
    // deleted row(s) — empty here means nothing matched, which we still
    // treat as success (idempotent), not an error.
    return ['ok' => true, 'message' => 'Event deleted.'];
}

/**
 * Change ONLY an event's status (cancel / mark completed / revert to draft /
 * publish), without touching the rest of its fields. Kept separate from
 * update_event_admin() so the "Cancel" / "Mark Completed" row-actions in
 * event-organizer.php don't need to resubmit the whole edit form.
 *
 * `status` is a real Postgres enum — only these four values are valid.
 * `is_published` is kept in sync since the public-facing queries in
 * supabase_request() filter on `status=eq.published`, not on is_published,
 * but is_published is still denormalized onto the row for anything else
 * that reads it directly.
 *
 * @return array{ok:bool, message:string}
 */
function set_event_status_admin(string $eventId, string $status): array
{
    $allowed = ['draft', 'published', 'cancelled', 'completed'];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }

    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('events', 'id=eq.' . urlencode($eventId), 'PATCH', [
        'status' => $status,
        'is_published' => $status === 'published',
    ], false, $token);

    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not update the event status. Please try again.'];
    }

    $labels = ['draft' => 'moved back to draft', 'published' => 'published', 'cancelled' => 'cancelled', 'completed' => 'marked completed'];
    return ['ok' => true, 'message' => 'Event ' . $labels[$status] . '.'];
}

/** Categories for the admin form's dropdown (name only, alphabetical). */
function get_categories(): array
{
    return supabase_request('categories', 'select=id,name&order=name.asc');
}

/** Organizations for the admin form's dropdown (name only, alphabetical). */
function get_organizations(): array
{
    return supabase_request('organizations', 'select=id,name&order=name.asc');
}

// =============================================================================
// ADMIN — CATEGORIES & ORGANIZATIONS MANAGEMENT (admin-taxonomy.php)
// =============================================================================
// Same pattern as event management above: every write runs AS the logged-in
// admin's own access token so RLS's is_admin() check on `categories` /
// `organizations` is satisfied. Add these policies once in the Supabase SQL
// editor if they aren't already there (mirrors the `profiles` ones in
// AUTH_SETUP.md):
//
//   create policy "Admins manage categories"
//     on categories for all
//     using (is_admin()) with check (is_admin());
//
//   create policy "Categories are viewable by everyone"
//     on categories for select using (true);
//
//   create policy "Admins manage organizations"
//     on organizations for all
//     using (is_admin()) with check (is_admin());
//
//   create policy "Organizations are viewable by everyone"
//     on organizations for select using (true);
//
// If `is_admin()` isn't already defined as a Postgres function in your
// project (require_admin() above implies it should exist for the events
// table's own admin policies), it typically looks like:
//
//   create function is_admin() returns boolean as $$
//     select role = 'admin' from profiles where id = auth.uid();
//   $$ language sql security definer;

/** All categories, newest first, with a live count of events using each — for the admin table. */
function get_all_categories_admin(): array
{
    $token = $_SESSION['access_token'] ?? null;
    return supabase_request(
        'categories',
        'select=*&order=name.asc',
        'GET', null, false, $token
    );
}

/** Single category by id, for pre-filling the edit form. */
function get_category_admin(string $categoryId): ?array
{
    $token = $_SESSION['access_token'] ?? null;
    $rows = supabase_request(
        'categories',
        'select=*&id=eq.' . urlencode($categoryId) . '&limit=1',
        'GET', null, false, $token
    );
    return $rows[0] ?? null;
}

/** Create a category. @return array{ok:bool, message:string, id?:string} */
function create_category_admin(array $fields): array
{
    $user = current_user();
    $token = $_SESSION['access_token'] ?? null;
    $fields['created_by'] = $user['id'] ?? null;

    $result = supabase_request('categories', '', 'POST', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not create the category. The name may already be taken.'];
    }
    return ['ok' => true, 'message' => 'Category created.', 'id' => $result[0]['id'] ?? null];
}

/** Update a category. @return array{ok:bool, message:string} */
function update_category_admin(string $categoryId, array $fields): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('categories', 'id=eq.' . urlencode($categoryId), 'PATCH', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not save changes. The name may already be taken.'];
    }
    return ['ok' => true, 'message' => 'Category updated.'];
}

/**
 * Delete a category. NOTE: `events.category_id` has no ON DELETE behavior
 * documented here — if it's a plain foreign key (no CASCADE/SET NULL),
 * Postgres will refuse to delete a category that's still referenced by any
 * event, and this will come back ok:false. That's treated as an expected
 * validation failure, not a bug: retire the category from event-organizer.php's
 * dropdown first, or reassign those events, before deleting it.
 * @return array{ok:bool, message:string}
 */
function delete_category_admin(string $categoryId): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('categories', 'id=eq.' . urlencode($categoryId), 'DELETE', null, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => "Could not delete this category — it's probably still used by one or more events."];
    }
    return ['ok' => true, 'message' => 'Category deleted.'];
}

/** All organizations, alphabetical, for the admin table. */
function get_all_organizations_admin(): array
{
    $token = $_SESSION['access_token'] ?? null;
    return supabase_request(
        'organizations',
        'select=*&order=name.asc',
        'GET', null, false, $token
    );
}

/** Single organization by id, for pre-filling the edit form. */
function get_organization_admin(string $organizationId): ?array
{
    $token = $_SESSION['access_token'] ?? null;
    $rows = supabase_request(
        'organizations',
        'select=*&id=eq.' . urlencode($organizationId) . '&limit=1',
        'GET', null, false, $token
    );
    return $rows[0] ?? null;
}

/** Create an organization. @return array{ok:bool, message:string, id?:string} */
function create_organization_admin(array $fields): array
{
    $user = current_user();
    $token = $_SESSION['access_token'] ?? null;
    $fields['created_by'] = $user['id'] ?? null;

    $result = supabase_request('organizations', '', 'POST', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not create the organization. The name may already be taken.'];
    }
    return ['ok' => true, 'message' => 'Organization created.', 'id' => $result[0]['id'] ?? null];
}

/** Update an organization. @return array{ok:bool, message:string} */
function update_organization_admin(string $organizationId, array $fields): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('organizations', 'id=eq.' . urlencode($organizationId), 'PATCH', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not save changes. The name may already be taken.'];
    }
    return ['ok' => true, 'message' => 'Organization updated.'];
}

/**
 * Delete an organization. Same FK caveat as delete_category_admin() — events
 * still referencing it via organization_id will block the delete unless the
 * column has ON DELETE CASCADE/SET NULL.
 * @return array{ok:bool, message:string}
 */
function delete_organization_admin(string $organizationId): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('organizations', 'id=eq.' . urlencode($organizationId), 'DELETE', null, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => "Could not delete this organization — it's probably still used by one or more events."];
    }
    return ['ok' => true, 'message' => 'Organization deleted.'];
}
