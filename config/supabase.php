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

// ---- Real table shape (confirmed directly against the live Supabase project) ----
// profiles(id uuid pk -> auth.users.id, full_name text, role user_role['admin'|'user'],
//          avatar_url text, phone text, created_at, updated_at)
// organizations(id uuid pk, name text unique, description text, logo_url text,
//               created_by uuid -> profiles.id, created_at, updated_at)
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
// event_registrations(id uuid pk, event_id -> events.id, user_id -> profiles.id,
//                      status registration_status['registered'|'waitlisted'|'cancelled'|'attended'],
//                      registered_at)
// notifications(id uuid pk, event_id -> events.id (nullable), user_id -> profiles.id (nullable),
//               title text, message text, status notification_status['pending'|'sent'|'failed'],
//               created_at, sent_at)
// stats(id uuid pk, label text, value text, sort_order int, created_at, updated_at)
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

<<<<<<< Updated upstream
// ---- Generic cURL helper ---------------------------------------------------
function supabase_http(string $method, string $url, array $headers, ?array $body = null): array
=======
/** True if $ip is in $range ("10.0.0.5" or "10.0.0.0/8"). */
function ip_in_range(string $ip, string $range): bool
{
    if (!str_contains($range, '/')) return $ip === $range;
    [$net, $bits] = explode('/', $range, 2);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($bits % 8 === 0) return true;
    $mask = chr((0xff << (8 - $bits % 8)) & 0xff);
    return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
}

if (($proxies = getenv('TRUSTED_PROXIES')) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    foreach (array_filter(array_map('trim', explode(',', $proxies))) as $range) {
        if (ip_in_range($remote, $range)) {
            // The load balancer appends the address it saw: the last entry is the visitor.
            $chain = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $client = end($chain);
            if (filter_var($client, FILTER_VALIDATE_IP)) $_SERVER['REMOTE_ADDR'] = $client;
            break;
        }
    }
}

if (($redisUrl = getenv('SESSION_REDIS_URL')) && session_status() === PHP_SESSION_NONE) {
    if (extension_loaded('redis')) {
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $redisUrl);
    } else {
        error_log('SESSION_REDIS_URL is set but the PHP redis extension is missing; sessions stay on this server.');
    }
}

/**
 * The Redis server shared by every app server (SESSION_REDIS_URL), or null
 * when there isn't one. (A Redis object; typed loosely because the redis
 * extension is only installed on the load-balanced servers, not on XAMPP.)
 */
function shared_redis(): ?object
{
    static $redis = false;
    if ($redis !== false) return $redis;
    $redis = null;
    $url = getenv('SESSION_REDIS_URL');
    if (!$url || !extension_loaded('redis')) return null;
    $parts = parse_url(str_replace('tcp://', 'redis://', $url));
    try {
        $r = new Redis();
        if ($r->connect($parts['host'] ?? 'redis', (int) ($parts['port'] ?? 6379), 1.5)) {
            if (!empty($parts['pass'])) $r->auth($parts['pass']);
            $redis = $r;
        }
    } catch (Throwable $e) {
        error_log('Redis unavailable: ' . $e->getMessage());
    }
    return $redis;
}

// ---- School identity --------------------------------------------------------
// Shown in the header, sidebar, footer, login pages and on every printout.
const SCHOOL_NAME = 'La Consolacion University Philippines';
// IMAGE SLOT: site logo (Campus Event Hub hand + magnifier) | file: public/assets/images/school-logo.png | square, 512×512
// Replace the file with a new logo (keep the file name). Transparent PNG works best.
const SCHOOL_LOGO = 'assets/images/school-logo.png';
/** Light (Orange) version for dark backgrounds: sidebar, phone top bar, footer, login cards. */
const SCHOOL_LOGO_LIGHT = 'assets/images/school-logo-light.png';

// ---- Site base URL ----------------------------------------------------------
// Web path of the public/ folder, e.g. '/campus-event-calendar-system/public'
// under XAMPP, or '' if public/ is the web root. Shared files (this config,
// includes/*.php) are loaded from pages in different subfolders, so they
// build links as BASE_URL . '/auth/login.php' instead of relative paths.
define('BASE_URL', getenv('APP_BASE_URL') ?: detect_base_url());

/** The site is opened over HTTPS (directly, or behind a host's proxy such as Render). */
function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Opened on this computer (XAMPP): PHP errors are shown on the page there, and only there. */
function is_local_request(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

// Every page and API answer: safety headers, and no error details (file paths,
// code) shown to visitors once the site is online — they go to the error log.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');                                      // don't advertise the PHP version
    header('X-Content-Type-Options: nosniff');                          // files are only what they say they are
    header('X-Frame-Options: SAMEORIGIN');                              // other sites can't frame the pages (clickjacking)
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');         // links out don't leak page addresses
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000');          // browsers always use HTTPS from then on
    }
    if (!is_local_request()) {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
    }
}

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

// ---- Database shape (Thesis Supabase project) --------------------------------
// profiles(id -> auth.users.id, full_name, role user_role['admin'|'moderator'|'user'],
//          account_type['student'|'faculty'], department_id -> departments,
//          organization_id -> organizations, year_level int2 (1-5, the student's HIGHEST year level),
//          course, student_type['regular'|'irregular'], irregular_year_levels int2[] (every year
//          level an irregular student has subjects in), avatar_url, phone, created_at, updated_at)
//          Created automatically by the handle_new_user() trigger on sign-up; only admins
//          can change `role` (protect_profile_role() trigger), moderators can't change their
//          organization, and the student/faculty choice is fixed once made.
//          is_super_admin: the protected Super Admin (Arian Abergas), set only in the SQL Editor. Changing
//          your own saved details needs a Super Admin's approval (profile_change_requests); filling in
//          empty ones doesn't (guard_profile_changes() trigger, and the same on school_ids).
// school_ids(user_id -> profiles, school_id unique e.g. '23-3170-870', verified_at, verified_by)
//          Kept apart from profiles (which everyone can read): visible to the student, admins,
//          and moderators whose events the student joined.
// organizations(id, name unique, description, logo_url, department_id -> departments,
//          created_by, created_at, updated_at)
// departments(id, name unique, code unique e.g. 'IT', description, created_at, updated_at)
//          Can't be deleted while an organization uses it (ON DELETE RESTRICT).
// organization_officers(id, organization_id, profile_id (optional), full_name, position, photo_url,
//          academic_year e.g. '2026-2027', year_level, course, sort_order, is_active, ...)
// categories(id, name unique, description, created_by, created_at, updated_at)
// events(id, title, category_id, organization_id, event_type['academic'|'seminar'|'workshop'|
//        'sports'|'cultural'|'other'], description, start_date, start_time, end_date, end_time,
//        venue, is_online, online_event_link, address, poster_url, registration_limit,
//        registration_deadline, contact_email, contact_number, enable_registration,
//        is_published (false only for drafts), send_notification,
//        status['draft'|'published'|'cancelled'|'completed'], created_by, created_at, updated_at,
//        audience['students'|'faculty'|'all'], venue_type['on_campus'|'off_campus'|'online'],
//        year_levels int2[] (student year levels that may register; empty = all),
//        category_other text (the event type typed in when the category is "Other"),
//        approval_status[null|'needed'|'pending'|'approved'|'rejected'], approval_note,
//        approval_requested_at, approval_reviewed_by, approval_reviewed_at)
//        Admins' (moderator role) new events need a Super Admin's approval: publishing
//        one keeps it a draft with approval_status 'pending' (trg_events_approval).
//        event_type is no longer picked on the form: it follows the category's name.
//        Categories/organizations can't be deleted while an event uses them (ON DELETE RESTRICT).
//        trg_events_venue_type keeps venue_type = 'online' exactly when is_online is true.
//        trg_events_schedule_rules refuses the same title on the same date (DUPLICATE_EVENT) and
//        two events at one venue at the same time (VENUE_CONFLICT).
//        complete_ended_events() marks published events 'completed' once they end.
// event_programs(id, event_id, title, description, speaker, sort_order, ...)  -- event Schedule
// event_registrations(id, event_id, user_id, status['registered'|'waitlisted'|'cancelled'|
//        'attended'|'no_show'], registered_at, attended_at) unique (event_id, user_id)
//        check_event_registration() trigger enforces: published, registration enabled,
//        before the deadline, not ended, not full. Students can only cancel/re-register.
//        attended_at is stamped by a trigger when an admin marks someone Attended.
// academic_terms(id, school_year e.g. '2026-2027', semester['1st'|'2nd'|'summer'],
//        start_date, end_date) -- the school calendar; terms can't overlap.
// evaluation_questions(id, section, question, question_type['rating'|'text'], is_required,
//        sort_order, is_active) -- the ONE evaluation form used for every event
// event_evaluations(id, event_id, user_id, submitted_at) unique (event_id, user_id)
// event_evaluation_answers(evaluation_id, question_id, rating 1-5, answer_text)
//        Written only by submit_event_evaluation(), which requires status = 'attended'.
// event_report (view): one row per non-draft event with category, organization,
//        department, school_year, semester and registration / attendance / evaluation totals.
// notifications(id, event_id, user_id, title, message, status['pending'|'sent'|'failed'|'read'],
//        created_at, sent_at, email_pending, email_attempts, email_retry_at, emailed_at, email_error)
//        -- new ones are also emailed by the site (see EMAIL below)
// allowed_email_exceptions(email, note) -- non-school addresses that may still have accounts
//        (SQL Editor only). enforce_school_email() trigger on auth.users: new accounts and
//        changed addresses must be @email.lcup.edu.ph or on this list.
// events.required_for['everyone'|'members'|'department'|null] -- required attendance
// profile_change_requests(id, user_id, changes jsonb {column: new value, school_id}, status['pending'|
//        'approved'|'rejected'|'withdrawn'], review_note, reviewed_by, reviewed_at) -- one pending per
//        account; decided only by review_profile_change(), which applies approved changes.
// user_presence(user_id, last_seen_at) -- Active Users heartbeat (only via functions)
// Storage buckets (public read, admin upload): event-posters, org-logos, officer-photos.
// Functions: site_stats(), event_registration_counts(), touch_presence(), clear_presence(),
//        notify_event(event_id, audience 'open'|'open_others'|'required'|'registered', title, message),
//        email_allowed(email), event_open_to(event, profile), event_required_for(event, profile),
//        my_required_events(), pending_notification_emails(limit), mark_notification_emails(ids, error),
//        is_admin(),
//        is_moderator(), can_manage_event(org id), can_manage_event_id(event id),
//        school_period(date) -> (school_year, semester),
//        submit_event_evaluation(event_id, answers jsonb), school_id_taken(text),
//        complete_ended_events(), is_super_admin(), review_profile_change(request, approve, note).
// Roles: admin = everything. moderator = the events of their own organization (create, edit,
//        publish, cancel — not delete), their schedules, registrations, attendance, printouts,
//        evaluation results and attendance imports, plus their organization's officers.
//        user = student or faculty. The super admin is an admin who also approves the other
//        admins' new events and everyone's profile edits.

// =============================================================================
// HTTP LAYER
// =============================================================================

/**
 * Raw cURL request. $body: array/object -> JSON, string -> sent as-is (file uploads).
 * @return array{ok:bool, status:int, data:mixed, raw:string}
 */
function supabase_http(string $method, string $url, array $headers, $body = null, int $timeout = 20): array
>>>>>>> Stashed changes
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

<<<<<<< Updated upstream
    // Any failure — network-level (status 0) OR an API-level error response
    // (missing table, bad query, RLS block, invalid enum value, etc.) — must
    // NEVER hand the raw error object back to callers expecting an array of
    // rows.
=======
/** Same as supabase_request(), but runs as the logged-in user (their RLS rights). */
function supabase_user_request(string $table, string $query = '', string $method = 'GET', ?array $body = null): array
{
    return supabase_request($table, $query, $method, $body, session_access_token());
}

/**
 * A read that uses the columns added by migration
 * 20260930120000_solis_suggestions.sql. If the database doesn't have them yet
 * (the migration hasn't been run), $fallbackQuery — the same read without
 * them — is used instead, so lists and roles keep working until it is.
 */
function supabase_user_request_compat(string $table, string $query, string $fallbackQuery): array
{
    $rows = supabase_user_request($table, $query);
    if (!$rows && supabase_last_error() !== '' && !supabase_session_expired()) {
        $GLOBALS['schema_outdated'] = true;
        $rows = supabase_user_request($table, $fallbackQuery);
    }
    return $rows;
}

/** True if a read this page load found the database without the new columns (migration not run). */
function schema_outdated(): bool
{
    return !empty($GLOBALS['schema_outdated']) || (($_SESSION['user']['schema_ready'] ?? true) === false);
}

/**
 * Call a database function. $asUser = run it with the logged-in user's token.
 * @return array{ok:bool, data:mixed}
 */
function supabase_rpc(string $function, array $args = [], bool $asUser = false): array
{
    $GLOBALS['supabase_last_error'] = '';
    $result = supabase_call('POST', '/rest/v1/rpc/' . $function, $args ?: new stdClass(), $asUser ? session_access_token() : null);
    return ['ok' => $result['ok'], 'data' => $result['data']];
}

/** Message of the last failed Supabase request ('' if it succeeded). */
function supabase_last_error(): string
{
    return (string) ($GLOBALS['supabase_last_error'] ?? '');
}

/** True if a request this page load failed because the login couldn't be renewed. */
function supabase_session_expired(): bool
{
    return !empty($GLOBALS['supabase_session_expired']);
}

/**
 * Turn the last error into something a user can read. The database's own
 * rule errors look like "EVENT_FULL: This event is full." — the part after
 * the code is shown as-is; anything else falls back to $fallback.
 */
function friendly_error(string $fallback): string
{
    if (supabase_session_expired()) {
        return 'Your login session has expired. Please log out and log in again.';
    }
    $error = supabase_last_error();
    if (preg_match('/^[A-Z_]+: (.+)$/', $error, $m)) {
        return $m[1];
    }
    if (stripos($error, 'Only an admin') === 0 || stripos($error, 'Only admins') === 0) {
        return $error;
    }
    if (str_contains($error, 'school_ids_school_id_key')) {
        return 'That School ID is already registered to another account.';
    }
    if (str_contains($error, 'duplicate key')) {
        return 'That name is already taken.';
    }
    if (str_contains($error, 'schema cache') || str_contains($error, 'does not exist')) {
        return 'The database is missing an update this page needs. An admin can apply it from Admin → Database update.';
    }
    if (str_contains($error, 'row-level security')) {
        return "You don't have permission to do that. (If you're a moderator, the database may need its update — ask an admin.)";
    }
    return $fallback;
}

// =============================================================================
// GENERAL HELPERS
// =============================================================================

/**
 * URL for an image stored in the database: full URLs (Supabase Storage,
 * https://...) are used as-is; relative paths like "assets/x.jpg" are made
 * relative to the site root so they work from every folder.
 */
function asset_url(?string $path, string $fallback = ''): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return $fallback;
    }
    if (preg_match('~^(https?:)?//|^data:|^/~i', $path)) {
        return $path;
    }
    return BASE_URL . '/' . preg_replace('~^(\./|\.\./)+~', '', $path);
}

/**
 * A URL made safe to put inside style="background-image:url('...')" —
 * quotes, parentheses and spaces are percent-encoded so they can't end the
 * url(...) early.
 */
function css_url(string $url): string
{
    return htmlspecialchars(str_replace(["'", '"', '(', ')', ' ', '\\'], ['%27', '%22', '%28', '%29', '%20', '%5C'], $url));
}

/**
 * URL of the school logo (header, sidebar, footer, printouts). Adds the file's
 * last-modified time (?v=...) so browsers fetch the new logo as soon as the
 * file is replaced, instead of showing an old cached copy.
 */
function school_logo_url(bool $light = false): string
{
    $path = $light ? SCHOOL_LOGO_LIGHT : SCHOOL_LOGO;
    $url = asset_url($path);
    $file = __DIR__ . '/../public/' . $path;
    return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
}

/** Placeholder shown on event cards when an event has no poster. */
function event_poster_url(?string $posterUrl): string
{
    return asset_url($posterUrl, BASE_URL . '/assets/images/event-placeholder.svg');
}

/**
 * File type of the UI icons in assets/images/icons/. They're SVG
 * placeholders for now; if the real icons are PNGs, save them with the same
 * names (home.png, bell.png, ...) and change this to 'png'.
 */
const ICON_EXT = 'svg';

/**
 * A small UI icon: <img> of assets/images/icons/<name>.svg. Every icon on the
 * site goes through here, so replacing one file (e.g. icons/bell.svg) changes
 * that icon everywhere it's used. The list of icons and where each appears
 * is in assets/images/icons/README.md.
 */
function icon(string $name, int $size = 18, string $class = ''): string
{
    // ?v=<last change> so browsers load a replaced icon right away instead of an old cached copy.
    static $versions = [];
    $file = $name . '.' . ICON_EXT;
    if (!isset($versions[$file])) {
        $path = __DIR__ . '/../public/assets/images/icons/' . $file;
        $versions[$file] = is_file($path) ? '?v=' . filemtime($path) : '';
    }
    return '<img src="' . htmlspecialchars(BASE_URL . '/assets/images/icons/' . $file . $versions[$file]) . '"'
        . ' alt="" class="ui-icon' . ($class !== '' ? ' ' . htmlspecialchars($class) : '') . '"'
        . ' width="' . $size . '" height="' . $size . '" data-icon="' . htmlspecialchars($name) . '">';
}

/** "Juan Dela Cruz" -> "JD" (avatar bubbles). */
function initials(string $name): string
{
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $part) {
        $out .= mb_substr($part, 0, 1);
    }
    return mb_strtoupper($out) ?: 'U';
}

/** '2026-09-20' -> 'Sep 20, 2026'; empty -> 'TBA'. */
function format_event_date(?string $date): string
{
    if (!$date) return 'TBA';
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

/** A moment (timestamptz) in school time: 'Oct 17, 2026 · 10:30 AM'; empty -> '—'. */
function format_timestamp(?string $timestamp): string
{
    $ts = $timestamp ? strtotime($timestamp) : false;
    return $ts ? date('M j, Y · g:i A', $ts) : '—';
}

/** '13:30:00' -> '1:30 PM'; empty -> ''. */
function format_event_time(?string $time): string
{
    if (!$time) return '';
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}

/** "Sep 20, 2026 | 1:00 PM – 3:00 PM" (multi-day: "Sep 20 – Sep 22, 2026"). */
function event_when(array $ev): string
{
    $time = event_time_range($ev);
    return event_date_range($ev) . ($time !== '' ? ' | ' . $time : '');
}

/** "Sep 20, 2026"; multi-day: "Sep 1 – Dec 31, 2026" or "Dec 30, 2026 – Jan 2, 2027". */
function event_date_range(array $ev): string
{
    $start = $ev['start_date'] ?? null;
    $end = $ev['end_date'] ?? null;
    $s = $start ? strtotime($start) : false;
    $e = $end ? strtotime($end) : false;
    if (!$s || !$e || $end === $start) return format_event_date($start);
    return (date('Y', $s) === date('Y', $e) ? date('M j', $s) : date('M j, Y', $s)) . ' – ' . date('M j, Y', $e);
}

/** "1:00 PM – 3:00 PM"; just "1:00 PM" when there's no end time (or it's the same); '' without a time. */
function event_time_range(array $ev): string
{
    $start = format_event_time($ev['start_time'] ?? null);
    $end = format_event_time($ev['end_time'] ?? null);
    return $start !== '' && $end !== '' && $end !== $start ? $start . ' – ' . $end : $start;
}

/** Runs a week or longer — the Event Calendar lists it once as "Ongoing" instead of on every day. */
function is_long_event(array $ev): bool
{
    $s = strtotime($ev['start_date'] ?? '');
    $e = strtotime($ev['end_date'] ?? '');
    return $s && $e && ($e - $s) / 86400 >= 7;
}

/**
 * Where an event is right now (Philippine time): 'upcoming' (hasn't
 * started), 'ongoing' (started, not ended yet) or 'done' (ended, completed
 * or cancelled). No start time = from the start of the start date; no end
 * time = until the end of the end date.
 */
function event_phase(array $ev): string
{
    if (in_array($ev['status'] ?? '', ['completed', 'cancelled'], true)) return 'done';
    $startDate = (string) ($ev['start_date'] ?? '');
    $endDate = (string) (($ev['end_date'] ?? '') ?: $startDate);
    $start = strtotime($startDate . ' ' . (!empty($ev['start_time']) ? $ev['start_time'] : '00:00:00'));
    $end = !empty($ev['end_time']) ? strtotime($endDate . ' ' . $ev['end_time']) : strtotime($endDate . ' +1 day');
    $now = time();
    if ($end && $now >= $end) return 'done';
    if ($start && $now >= $start) return 'ongoing';
    return 'upcoming';
}

/** Venue, or "Online" for online events. */
function event_place(array $ev): string
{
    return trim((string) ($ev['venue'] ?? '')) !== '' ? $ev['venue'] : (!empty($ev['is_online']) ? 'Online' : 'TBA');
}

/** "On-campus · For Students" — the two categories every event has. */
function event_categories_line(array $ev): string
{
    $parts = [];
    if (!empty($ev['venue_type'])) $parts[] = label_of(VENUE_TYPES, $ev['venue_type'], '');
    if (!empty($ev['audience']) || !empty($ev['audiences'])) $parts[] = 'For ' . event_audience_label($ev);
    return implode(' · ', array_filter($parts));
}

/** Shorten text to $width characters with an ellipsis. */
function excerpt(?string $text, int $width = 120): string
{
    return mb_strimwidth(trim((string) $text), 0, $width, '…');
}

/** One-time messages shown on the next page ("Event created.", errors...). */
function flash(string $type, string $message): void
{
    session_start_once();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    session_start_once();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** HTML for all pending flash messages (types: success | error | info). */
/**
 * This version's database updates (supabase/migrations), oldest first: file =>
 * [what it switches on, a quick check — table + query, or 'rpc' + function name + its arguments].
 * A table check with 'rows' at the end is for data-only updates: needed until the query finds a row.
 */
const DB_UPDATES = [
    '20260930120000_solis_suggestions.sql' => ['Admins, School IDs, year levels and the duplicate checks', 'profiles', 'select=account_type&limit=1'],
    '20261015120000_manage_events_update.sql' => ['The "Other" type of event and multi-organization events', 'events', 'select=category_other&limit=1'],
    '20261017120000_super_admin_approvals.sql' => ['Approvals of new events and profile edits', 'profiles', 'select=is_super_admin&limit=1'],
    '20261018090000_three_role_levels.sql' => ['Admin / Super Admin roles and deleting accounts', 'rpc', 'delete_account', ['p_user' => '00000000-0000-0000-0000-000000000000']],
    '20261018120000_approval_notes.sql' => ['Super Admins\' notes on waiting profile edits', 'rpc', 'note_profile_change', ['p_request' => '00000000-0000-0000-0000-000000000000', 'p_note' => '']],
    '20261018150000_audiences_and_evaluation_form.sql' => ['Students / Faculty / Outsiders audiences, and Admins editing the evaluation form', 'events', 'select=audiences&limit=1'],
    '20261022120000_evaluation_form_parts.sql' => ['The school\'s evaluation form (Parts I–IV)', 'evaluation_questions', 'select=id&is_active=eq.true&section=eq.Part%20I%3A%20Activity%20Experience&limit=1', 'rows'],
    '20261103120000_school_email_and_notifications.sql' => ['School-email-only accounts, required attendance, and notifications by email to the people an event is open to', 'events', 'select=required_for&limit=1'],
    '20261105120000_private_profiles.sql' => ['Private profiles: only the person and Admins can read an account\'s details (names, phone numbers…)', 'rpc', 'profiles_are_private', []],
    '20261106120000_registration_notices.sql' => ['"You\'re registered" notices in the bell and by email', 'rpc', 'my_notification_emailed', ['p_id' => '00000000-0000-0000-0000-000000000000']],
    '20261108120000_organization_codes.sql' => ['Short codes for organizations, shown like the departments\' ("Full Name (CODE)")','organizations', 'select=code&limit=1'],
    '20261109120000_event_levels.sql' => ['Event levels (Institutional / Departmental / Organizational) and their colours on the Event Calendar', 'events', 'select=event_level&limit=1'],
];

/**
 * The database updates that haven't been run yet (file => what it switches on).
 * Checked at most every 5 minutes per session ($fresh: now).
 */
function pending_db_updates(bool $fresh = false): array
{
    session_start_once();
    $cache = $_SESSION['db_updates_pending'] ?? null;
    if (!$fresh && is_array($cache) && $cache['at'] > time() - 300) return $cache['pending'];
    $lastError = $GLOBALS['supabase_last_error'] ?? '';
    $pending = [];
    foreach (DB_UPDATES as $file => $update) {
        [$label, $table, $check, $args] = $update + [3 => []];
        if ($table === 'rpc') {
            // Called without a login: "permission denied" = the function is there.
            supabase_rpc($check, $args);
            $missing = str_contains(supabase_last_error(), 'Could not find the function');
        } else {
            $rows = supabase_request($table, $check);
            $missing = supabase_last_error() !== '' || ($args === 'rows' && !$rows);
        }
        if ($missing) $pending[$file] = $label;
    }
    $GLOBALS['supabase_last_error'] = $lastError;
    $_SESSION['db_updates_pending'] = ['pending' => $pending, 'at' => time()];
    return $pending;
}

function render_flashes(): string
{
    $html = '';
    // Tell Super Admins when the database is missing an update this version needs.
    if (is_admin_user() && ($pending = pending_db_updates())) {
        $html .= '<div class="flash flash-info db-banner" role="status"><span><strong>Database update needed.</strong> '
            . htmlspecialchars(count($pending) === 1 ? reset($pending) . ' turn on once it\'s run.' : count($pending) . ' updates haven\'t been run yet: ' . implode('; ', $pending) . '.') . '</span> '
            . '<a href="' . htmlspecialchars(BASE_URL . '/admin/database-update.php') . '" class="btn btn-primary">Run the update</a></div>';
    }
    foreach (take_flashes() as $f) {
        $type = in_array($f['type'], ['success', 'error', 'info'], true) ? $f['type'] : 'info';
        $html .= '<div class="flash flash-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
            . htmlspecialchars($f['message']) . '</div>';
    }
    return $html;
}

/**
 * Only allow redirects to paths on this site (blocks "?redirect=https://evil.site"
 * and "//evil.site"). Anything else falls back to $default.
 */
function safe_redirect_target(?string $target, string $default): string
{
    $target = trim((string) $target);
    if ($target === '' || !preg_match('~^/(?![/\\\\])~', $target) || preg_match('~[\x00-\x1f]~', $target)) {
        return $default;
    }
    return $target;
}

/** Absolute URL of a page on this site (for links Supabase sends by email / OAuth). */
function app_url(string $path = ''): string
{
    $origin = getenv('APP_ORIGIN') ?: ((is_https_request() ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return rtrim($origin, '/') . BASE_URL . '/' . ltrim($path, '/');
}

/** A ?query value as a trimmed string ('' if missing or not a plain string, e.g. "?id[]=x"). */
function get_param(string $key, string $default = ''): string
{
    return is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : $default;
}

/** A form (POST) value as a trimmed string ('' if missing or not a plain string). */
function post_param(string $key, string $default = ''): string
{
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;
}

function is_uuid(?string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value);
}

const EVENT_TYPES = ['academic' => 'Academic', 'seminar' => 'Seminar', 'workshop' => 'Workshop', 'sports' => 'Sports', 'cultural' => 'Cultural', 'other' => 'Other'];

/** Who an event is for (events.audience — the older single choice, kept for the reports). */
const EVENT_AUDIENCES = ['students' => 'Students', 'faculty' => 'Faculty', 'all' => 'Students & Faculty'];

/**
 * Who an event is for, any of these (events.audiences — migration
 * 20261018150000_audiences_and_evaluation_form.sql). Outsiders: people from
 * outside the school.
 */
const AUDIENCE_GROUPS = ['students' => 'Students', 'faculty' => 'Faculty', 'outsiders' => 'Outsiders'];

/** An event's audiences: events.audiences, or (older events) what events.audience says. @return string[] */
function event_audiences(array $ev): array
{
    $groups = array_values(array_intersect(array_keys(AUDIENCE_GROUPS), (array) ($ev['audiences'] ?? [])));
    if ($groups) return $groups;
    return match ($ev['audience'] ?? 'students') {
        'all' => ['students', 'faculty'],
        'faculty' => ['faculty'],
        default => ['students'],
    };
}

/** "Students, Faculty & Outsiders". */
function event_audience_label(array $ev): string
{
    $labels = array_map(fn($g) => AUDIENCE_GROUPS[$g], event_audiences($ev));
    $last = array_pop($labels);
    return ($labels ? implode(', ', $labels) . ' & ' : '') . $last;
}

/** The older single value for these audiences: students | faculty | all (Outsiders only counts as all). */
function audience_value(array $groups): string
{
    $students = in_array('students', $groups, true);
    $faculty = in_array('faculty', $groups, true);
    return $students && !$faculty ? 'students' : ($faculty && !$students ? 'faculty' : 'all');
}

/** True once events.audiences exists (the database update has been run). */
function audiences_ready(): bool
{
    return !isset(pending_db_updates()['20261018150000_audiences_and_evaluation_form.sql']);
}

/** Where an event happens (events.venue_type). Online = webinar. */
const VENUE_TYPES = ['on_campus' => 'On-campus', 'off_campus' => 'Off-campus', 'online' => 'Online (Webinar)'];

/**
 * How wide an event is (events.event_level): the whole university, one
 * department, or one organization. Each has its colour on the Event Calendar.
 */
const EVENT_LEVELS = [
    'institutional' => 'Institutional',
    'departmental' => 'Departmental',
    'organizational' => 'Organizational',
];
const EVENT_LEVEL_HINTS = [
    'institutional' => 'University-wide, for the whole LCUP community',
    'departmental' => 'For one department (e.g. CITE)',
    'organizational' => 'Held by an organization for its members and guests',
];

/** An event's level; events saved before levels existed count as organizational. */
function event_level(array $ev): string
{
    $level = (string) ($ev['event_level'] ?? '');
    return isset(EVENT_LEVELS[$level]) ? $level : 'organizational';
}

/** Semesters of a school year (academic_terms.semester). */
const SEMESTERS = ['1st' => '1st Semester', '2nd' => '2nd Semester', 'summer' => 'Summer'];

/** Label for a stored value, e.g. label_of(VENUE_TYPES, 'on_campus') -> 'On-campus'. */
function label_of(array $labels, ?string $key, string $default = '—'): string
{
    return $labels[(string) $key] ?? $default;
}

/** True for the catch-all "Other" category (its events say what they are in events.category_other). */
function is_other_category(?string $name): bool
{
    return strcasecmp(trim((string) $name), 'Other') === 0;
}

/** An event's category for display — the typed-in event type when the category is "Other". */
function event_category_label(array $ev, string $default = 'Event'): string
{
    $name = $ev['categories']['name'] ?? label_of(EVENT_TYPES, $ev['event_type'] ?? null, $default);
    $custom = trim((string) ($ev['category_other'] ?? ''));
    return $custom !== '' && is_other_category($name) ? $custom : $name;
}

/** Kinds of account (profiles.account_type) and of student (profiles.student_type). */
const ACCOUNT_TYPES = ['student' => 'Student', 'faculty' => 'Faculty'];
const STUDENT_TYPES = ['regular' => 'Regular', 'irregular' => 'Irregular'];

/** Student year levels (profiles.year_level = the HIGHEST one; events.year_levels). */
const YEAR_LEVELS = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => '5th Year'];

/**
 * Roles (profiles.role) and how they're shown: 'user' as Student or Faculty
 * (see role_display()), 'moderator' as Admin (their organization's events),
 * 'admin' as Super Admin (everything, and approvals).
 */
const ROLES = ['user' => 'Student / Faculty', 'moderator' => 'Admin', 'admin' => 'Super Admin'];

/** 3 -> '3rd' */
function ordinal(int $n): string
{
    return $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
}

/** 3 -> '3rd Year'; null -> ''. */
function year_label($level): string
{
    return YEAR_LEVELS[(int) $level] ?? '';
}

/**
 * Year levels as text: [1,2] -> '1st & 2nd year', [2,3,4] -> '2nd, 3rd & 4th year';
 * [] -> '' (every year level).
 */
function year_levels_label(?array $levels): string
{
    $levels = array_values(array_unique(array_map('intval', $levels ?? [])));
    sort($levels);
    if (!$levels || $levels === array_keys(YEAR_LEVELS)) return '';
    $words = array_map('ordinal', $levels);
    $last = array_pop($words);
    return ($words ? implode(', ', $words) . ' & ' : '') . $last . ' year';
}

/**
 * Year levels from a form: '2' / ['1','3'] -> [1, 3] (only 1-5, sorted, no repeats).
 * @return int[]
 */
function parse_year_levels($value): array
{
    $levels = [];
    foreach ((array) $value as $v) {
        if (is_string($v) || is_int($v)) {
            $n = (int) $v;
            if (isset(YEAR_LEVELS[$n]) && (string) $n === trim((string) $v)) $levels[$n] = $n;
        }
    }
    ksort($levels);
    return array_values($levels);
}

/**
 * How the student's year level is shown: '3rd Year' or
 * '3rd Year · Irregular (2nd & 3rd year subjects)'.
 */
function student_year_line(array $profile): string
{
    $line = year_label($profile['year_level'] ?? null);
    if ($line !== '' && ($profile['student_type'] ?? '') === 'irregular') {
        $subjects = year_levels_label($profile['irregular_year_levels'] ?? []);
        $line .= ' · Irregular' . ($subjects !== '' ? ' (' . $subjects . ' subjects)' : '');
    }
    return $line;
}

// ---- School ID ---------------------------------------------------------------
// The registrar's format: 2 digits, 4 digits, 3 digits — 23-3170-870. Spaces
// are dropped, and the dashes are added when only the 9 digits are typed
// (233170870 -> 23-3170-870). IDs saved before this format was required stay
// valid until they're changed (validate_account_fields()).
const SCHOOL_ID_EXAMPLE = '23-3170-870'; // also checked in the browser by assets/js/account.js

function normalize_school_id(?string $value): string
{
    $id = strtoupper(preg_replace('/\s+/', '', (string) $value));
    return preg_match('/^\d{9}$/', $id) ? substr($id, 0, 2) . '-' . substr($id, 2, 4) . '-' . substr($id, 6) : $id;
}

function valid_school_id(string $normalized): bool
{
    return (bool) preg_match('/^\d{2}-\d{4}-\d{3}$/', $normalized);
}

/** Whether another account already uses this school ID (the database answers without saying whose). */
function school_id_taken(string $normalized): bool
{
    $result = supabase_rpc('school_id_taken', ['p_school_id' => $normalized]);
    return $result['ok'] && $result['data'] === true;
}

// ---- Forms: keep typed values across ONE redirect ---------------------------
// A form with errors is redirected back to itself (so refreshing the page
// doesn't post it again) and shows what was typed once. Refreshing or coming
// back later shows a clean form.

function remember_form(string $key, array $values, array $errors): void
{
    session_start_once();
    $_SESSION['form_state'][$key] = ['values' => $values, 'errors' => $errors];
}

/** @return array{values: array, errors: array}|null (and forgets it) */
function recall_form(string $key): ?array
{
    session_start_once();
    $state = $_SESSION['form_state'][$key] ?? null;
    unset($_SESSION['form_state'][$key]);
    return is_array($state) ? $state : null;
}

// ---- Forms: save once -------------------------------------------------------
// A form that adds something carries a one-time token (form_once_field()).
// After it's saved, form_once_saved() remembers the token with what was
// sent; if the very same form arrives again (a double click got through),
// form_once_repeat() says so and the page skips saving it a second time.

function form_once_field(): string
{
    return '<input type="hidden" name="form_once" value="' . bin2hex(random_bytes(12)) . '">';
}

/** The token and what was sent with it (minus the token). */
function form_once_key(): ?string
{
    $token = $_POST['form_once'] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{24}$/', $token)) return null;
    $sent = $_POST;
    unset($sent['form_once']);
    return $token . ':' . md5(serialize($sent));
}

function form_once_repeat(): bool
{
    session_start_once();
    $key = form_once_key();
    return $key !== null && in_array($key, $_SESSION['form_once'] ?? [], true);
}

function form_once_saved(): void
{
    session_start_once();
    if (($key = form_once_key()) === null) return;
    $_SESSION['form_once'] = array_slice(array_merge($_SESSION['form_once'] ?? [], [$key]), -30);
}

// =============================================================================
// SESSION & AUTH
// =============================================================================

function session_start_once(): void
{
    // While the assistant waits for Claude's answer it lets go of the session
    // (assistant_handle() in includes/assistant.php), so the user's other pages
    // aren't held up; it stays closed until the assistant reopens it to save.
    if (session_status() !== PHP_SESSION_ACTIVE && empty($GLOBALS['session_detached'])) {
        // HttpOnly: scripts can't read the login cookie. SameSite=Lax: the
        // browser won't send it with form posts from other websites, so
        // another site can't trigger admin actions (CSRF).
        // Secure (on HTTPS): the cookie is never sent over plain http.
        // Strict mode: a session ID the server didn't create is refused.
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https_request()]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
}

/** Seconds-since-epoch expiry of a JWT, or null if it can't be read. */
function jwt_expiry(string $jwt): ?int
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
    return isset($payload['exp']) ? (int) $payload['exp'] : null;
}

/**
 * The logged-in user's Supabase access token, renewed first if it expires
 * within a minute (tokens last 1 hour; the PHP login lasts longer).
 */
function session_access_token(): ?string
{
    session_start_once();
    $token = $_SESSION['access_token'] ?? null;
    if ($token && ($exp = jwt_expiry($token)) !== null && $exp - time() < 60) {
        supabase_refresh_session();
        // Renewed: the new token. Network trouble: the old one (still valid a
        // little longer). Login ended: none.
        $token = $_SESSION['access_token'] ?? null;
    }
    return $token;
}

/**
 * Get a new access token using the refresh token saved at login.
 * @return bool false if there's no refresh token or Supabase rejected it.
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
        // Supabase no longer knows this login (logged out on another device,
        // expired, or the account's sessions were ended): end it here too, so
        // the next page asks to log in instead of loading every page empty.
        // (Network trouble — no status, or 5xx — keeps the login for a retry.)
        if (in_array($result['status'], [400, 401, 403], true)) {
            $_SESSION = [];
            if (!headers_sent()) session_regenerate_id(true);
            $GLOBALS['supabase_session_expired'] = true;
            flash('info', 'Your login has ended (you may have logged out on another device). Please log in again.');
        }
        return false;
    }
    $_SESSION['access_token'] = $result['data']['access_token'];
    $_SESSION['refresh_token'] = $result['data']['refresh_token'] ?? $refreshToken;
    return true;
}

/** Message from a GoTrue (Supabase Auth) error response. */
function auth_error_message(array $result, string $fallback): string
{
    $data = is_array($result['data']) ? $result['data'] : [];
    $msg = $data['error_description'] ?? $data['msg'] ?? $data['message'] ?? $data['error'] ?? $fallback;
    if (!is_string($msg)) return $fallback;
    // The database refused the new account (enforce_school_email()).
    if (str_contains($msg, 'Database error saving new user') || str_contains($msg, 'SCHOOL_EMAIL_ONLY')) {
        return school_email_rule_message();
    }
    if (stripos($msg, 'already registered') !== false) {
        return 'An account with that email already exists. Log in, or use "Forgot Password?" if you don\'t remember the password.';
    }
    if (($data['error_code'] ?? '') === 'over_email_send_rate_limit' || stripos($msg, 'rate limit') !== false) {
        return 'Too many emails were sent just now. Please wait a few minutes and try again.';
    }
    // Supabase couldn't send the email: its built-in sender only emails the
    // project's own team until custom SMTP is set, or the SMTP settings are wrong.
    if (($data['error_code'] ?? '') === 'email_address_not_authorized' || str_contains($msg, 'cannot be used as it is not authorized')
        || stripos($msg, 'error sending') !== false) {
        error_log('[auth] ' . $msg . ' — check Supabase → Authentication → Emails → SMTP Settings');
        return 'The email couldn\'t be sent right now. Please try again later, and if it keeps happening, tell the site\'s admin (the site\'s email sender isn\'t set up yet).';
    }
    return $msg;
}

// ---- School email accounts -------------------------------------------------
// New accounts (sign-up and Google) must use an LCUP school email; the
// database refuses any other address (enforce_school_email(), migration
// 20261103120000). Existing accounts with another address can't log in any
// more, except Super Admins and the addresses in allowed_email_exceptions
// (added in the Supabase SQL Editor).
const SCHOOL_EMAIL_DOMAIN = 'email.lcup.edu.ph';

/** 'juan.delacruz@email.lcup.edu.ph' -> true; gmail.com, subdomains and look-alikes -> false. */
function is_school_email(?string $email): bool
{
    $email = strtolower(trim((string) $email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && (bool) preg_match('/^[a-z0-9._%+\'-]+@' . preg_quote(SCHOOL_EMAIL_DOMAIN, '/') . '$/', $email);
}

function school_email_rule_message(): string
{
    return 'Only LCUP school emails (ending in @' . SCHOOL_EMAIL_DOMAIN . ') can be used. Other addresses, like a personal @gmail.com, aren\'t allowed.';
}

/** A school email, or an exception added by a Super Admin in the SQL Editor (the database's email_allowed()). */
function email_allowed(?string $email): bool
{
    if (is_school_email($email)) return true;
    if (trim((string) $email) === '') return false;
    $lastError = $GLOBALS['supabase_last_error'] ?? '';
    $result = supabase_rpc('email_allowed', ['p_email' => strtolower(trim((string) $email))]);
    $GLOBALS['supabase_last_error'] = $lastError;
    return $result['ok'] && $result['data'] === true;
}

/** Who may sign in: school emails and exceptions — and Super Admins with any address. */
function account_may_sign_in(?string $email, ?string $role): bool
{
    return $role === 'admin' || email_allowed($email);
}

/** Message for an existing account that isn't allowed to sign in any more. */
function blocked_account_message(): string
{
    return 'This account can\'t log in: only LCUP school emails (@' . SCHOOL_EMAIL_DOMAIN . ') are allowed now. Create a new account with your school email, or ask a Super Admin for help.';
}

/** Where the emailed links (confirming a new account, resetting a password) come back to. */
function auth_callback_url(string $type = ''): string
{
    return app_url('auth/callback.php' . ($type !== '' ? '?type=' . rawurlencode($type) : ''));
}

/**
 * Send the "confirm your email" message again (Supabase sends it; the link
 * comes back to auth/callback.php). Same answer whether or not the address
 * has an account.
 */
function supabase_auth_resend_confirmation(string $email): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/resend?redirect_to=' . rawurlencode(auth_callback_url('signup')), [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], ['type' => 'signup', 'email' => strtolower(trim($email))]);
>>>>>>> Stashed changes
    if (!$result['ok']) {
        error_log(sprintf(
            '[supabase_request] %s %s failed (HTTP %d): %s',
            $method,
            $table,
            $result['status'],
            json_encode($result['data'])
        ));

        // GET requests: fall back to mock/demo data so the page still renders.
        if ($method === 'GET') {
            return supabase_mock_data($table);
        }

        // Writes (POST/PATCH/DELETE): return empty array instead of the raw
        // error object, so callers using empty()/foreach() stay safe.
        return [];
    }

    // Defensive guard: even a 2xx response should be an array of rows.
    if (!is_array($result['data'])) {
        error_log("[supabase_request] $method $table returned non-array data, coercing to []");
        return [];
    }

    return $result['data'];
}

/**
 * Sample data so the frontend renders sensibly if Supabase is unreachable.
 */
function supabase_mock_data(string $table): array
{
    $mocks = [
        'events' => [
            ['id' => 1, 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'categories' => ['name' => 'Seminar'], 'poster_url' => 'assets/event-pace.jpg', 'start_date' => 'TBA', 'venue' => 'AVR 1, CITE Building'],
            ['id' => 2, 'title' => 'OSH Training or SIES-ACpEs', 'categories' => ['name' => 'Seminar'], 'poster_url' => 'assets/event-osh.jpg', 'start_date' => 'TBA', 'venue' => 'AVR 1, CITE Building'],
            ['id' => 3, 'title' => 'GEN Z Night 2026', 'categories' => ['name' => 'Event'], 'poster_url' => 'assets/event-genz.jpg', 'start_date' => 'TBA', 'venue' => 'AVR 1, CITE Building'],
            ['id' => 4, 'title' => 'JPSSITE Talk: Misinformation', 'categories' => ['name' => 'Seminar'], 'poster_url' => 'assets/event-talk.jpg', 'start_date' => 'TBA', 'venue' => 'AVR 1, CITE Building'],
        ],
        'notifications' => [
            ['id' => 1, 'title' => 'New scholarship opportunity available!', 'message' => '', 'created_at' => 'TBA'],
            ['id' => 2, 'title' => 'Class suspended on Month 00 0000 (Day)', 'message' => '', 'created_at' => 'TBA'],
            ['id' => 3, 'title' => 'Submission of your requirements!', 'message' => '', 'created_at' => 'TBA'],
        ],
        'stats' => [
            ['label' => 'Events', 'value' => '000+'],
            ['label' => 'Organizations', 'value' => '0+'],
            ['label' => 'Active Users', 'value' => '0.0k+'],
            ['label' => 'Registrations', 'value' => '00k+'],
        ],
        // No rows by default — pages still render fine (just show an empty
        // state) when Supabase is unreachable.
        'event_registrations' => [],
        'categories' => [],
        'organizations' => [],
    ];

    return $mocks[$table] ?? [];
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

/** Invalidate the Supabase-side refresh token and clear the local session. */
function supabase_auth_signout(): void
{
    session_start_once();
    $accessToken = $_SESSION['access_token'] ?? null;
    if ($accessToken) {
        // scope=local: only this browser's login ends. (Supabase's default ends
        // the account's logins on every device, which broke the pages of anyone
        // else logged into the same account.)
        supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/logout?scope=local', [
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
<<<<<<< Updated upstream
    return $role === 'admin' ? 'Admin' : 'Student';
=======
    $b = htmlspecialchars(BASE_URL);
    return '<link rel="manifest" href="' . $b . '/manifest.json">' . "\n"
        . '<meta name="theme-color" content="#213e60">' . "\n"
        . '<link rel="icon" type="image/png" sizes="192x192" href="' . $b . '/assets/images/app/icon-192.png">' . "\n"
        . '<link rel="apple-touch-icon" href="' . $b . '/assets/images/app/apple-touch-icon.png">' . "\n"
        . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-title" content="Event Hub">' . "\n"
        . '<script src="' . $b . '/assets/js/app.js?v=' . (int) @filemtime(__DIR__ . '/../public/assets/js/app.js') . '" defer></script>';
}

const SITE_NAME = 'Campus Event Hub';
const SITE_DESCRIPTION = 'Campus Event Hub is the official event calendar of La Consolacion University Philippines (LCUP): '
    . 'browse upcoming seminars, workshops, sports and cultural events, register online, and get notified about campus activities.';

/**
 * Tags that let search engines (Google) and link previews (Messenger,
 * Facebook) show a public page properly: description, canonical address,
 * Open Graph / Twitter card and, on the home page, the site's structured
 * data. Set APP_ORIGIN (e.g. https://eventhub.lcup.edu.ph) on the live
 * server so every address uses the real domain.
 * $path: the page, relative to the site root ("index.php", "events.php").
 */
function seo_head_tags(string $title, string $description, string $path, bool $home = false): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    $url = app_url($path);
    $image = app_url('assets/images/lcup-campus.jpg');
    $tags = [
        '<meta name="description" content="' . $e($description) . '">',
        '<meta name="robots" content="index, follow">',
        '<link rel="canonical" href="' . $e($url) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="' . SITE_NAME . '">',
        '<meta property="og:title" content="' . $e($title) . '">',
        '<meta property="og:description" content="' . $e($description) . '">',
        '<meta property="og:url" content="' . $e($url) . '">',
        '<meta property="og:image" content="' . $e($image) . '">',
        '<meta property="og:locale" content="en_PH">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . $e($title) . '">',
        '<meta name="twitter:description" content="' . $e($description) . '">',
        '<meta name="twitter:image" content="' . $e($image) . '">',
    ];
    if ($home) {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => SITE_NAME,
            'alternateName' => 'LCUP Campus Event Hub',
            'url' => app_url(''),
            'description' => $description,
            'publisher' => [
                '@type' => 'CollegeOrUniversity',
                'name' => 'La Consolacion University Philippines',
                'logo' => app_url('assets/images/school-logo.png'),
            ],
        ];
        $tags[] = '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
    }
    return implode("\n", $tags);
}

/** ' data-theme="dark"' for <html>, so the page is drawn in the chosen look straight away ('' for light). */
function theme_attr(): string
{
    $theme = current_theme();
    return $theme === 'light' ? '' : ' data-theme="' . $theme . '"';
}

/**
 * The logged-in user (id, email, full_name, role, account_type,
 * department_id, organization_id, year_level, course, student_type,
 * irregular_year_levels, school_id, school_id_verified), or null. The role
 * and organization are checked once per page load (check_session_role()),
 * so a role change takes effect on the next click — a demoted admin loses
 * access straight away. The rest of the profile is re-read every 5 minutes.
 */
function current_user(): ?array
{
    static $checked = false;
    session_start_once();
    if (empty($_SESSION['user'])) return null;
    if (!$checked) {
        $checked = true;
        if (($_SESSION['user_checked_at'] ?? 0) < time() - 300) {
            reload_session_profile();
        } else {
            check_session_role();
        }
        // An account that may no longer sign in (not a school email) is logged out.
        if (($_SESSION['user']['sign_in_allowed'] ?? true) === false) {
            supabase_auth_signout();
            session_start_once();
            flash('error', blocked_account_message());
            return null;
        }
    }
    // null if the check above found the login had ended on Supabase's side.
    if (empty($_SESSION['user']['id']) || empty($_SESSION['access_token'])) {
        unset($_SESSION['user']);
        return null;
    }
    return $_SESSION['user'];
}

/**
 * One small read per page load: the account's current role and
 * organization. If a Super Admin changed either, the whole profile is
 * re-read; if the account was deleted, it loses every staff right.
 */
function check_session_role(): void
{
    if (empty($_SESSION['user']['id']) || empty($_SESSION['access_token'])) return;
    $rows = supabase_request('profiles', 'select=role,organization_id&id=eq.' . urlencode($_SESSION['user']['id']), 'GET', null, session_access_token());
    if (empty($_SESSION['user']['id'])) return; // the login ended while renewing it (supabase_refresh_session)
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        // No row and no error: the account is gone (a failed read changes nothing).
        if (supabase_last_error() === '') $_SESSION['user']['role'] = 'user';
        return;
    }
    if (($row['role'] ?? null) !== ($_SESSION['user']['role'] ?? null)
        || ($row['organization_id'] ?? null) !== ($_SESSION['user']['organization_id'] ?? null)) {
        reload_session_profile();
        unset($_SESSION['approvals_waiting']); // the sidebar count depends on the role
    }
}

function is_admin_user(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/**
 * A Super Admin (the 'admin' role): everything, including approving Admins'
 * new events and everyone's profile edits. (profiles.is_super_admin marks
 * the protected one — Arian Abergas — who can't be demoted or deleted.)
 */
function is_super_admin_user(?array $user = null): bool
{
    $user = $user ?? current_user();
    return ($user['role'] ?? '') === 'admin';
}

function is_moderator_user(): bool
{
    return (current_user()['role'] ?? '') === 'moderator';
}

/** Admins and moderators (the people who run events). */
function is_staff_user(): bool
{
    return is_admin_user() || is_moderator_user();
}

/**
 * 'admin' -> 'Super Admin', 'moderator' -> 'Admin', 'user' -> 'Student' or
 * 'Faculty' (the database keeps the enum value).
 */
function role_display(string $role, ?string $accountType = null): string
{
    return match ($role) {
        'admin' => 'Super Admin',
        'moderator' => 'Admin',
        default => $accountType === 'faculty' ? 'Faculty' : 'Student',
    };
}

/**
 * What still has to be filled in before an account can use the site.
 * Everyone except admins needs an account type, department and
 * organization; students also need their school ID, course, highest year
 * level and whether they're regular or irregular (irregular: their year
 * levels). Accounts from Google sign-in or from before these fields existed
 * are sent to My Profile to complete them.
 * @return string[] labels of the missing fields ([] = complete)
 */
function profile_missing_fields(array $user): array
{
    if (($user['role'] ?? '') === 'admin') return [];
    // Before the database has the new columns, nobody can fill them in: don't ask.
    if (($user['schema_ready'] ?? true) === false) return [];
    $missing = [];
    if (!isset(ACCOUNT_TYPES[$user['account_type'] ?? ''])) $missing[] = 'Student or faculty';
    if (empty($user['department_id'])) $missing[] = 'Department';
    if (empty($user['organization_id'])) $missing[] = 'Organization';
    if (($user['account_type'] ?? '') === 'student') {
        if (empty($user['school_id'])) $missing[] = 'School ID';
        if (empty($user['course'])) $missing[] = 'Course';
        if (empty($user['year_level'])) $missing[] = 'Highest year level';
        if (!isset(STUDENT_TYPES[$user['student_type'] ?? ''])) $missing[] = 'Regular or irregular';
        elseif ($user['student_type'] === 'irregular' && empty($user['irregular_year_levels'])) $missing[] = 'Year levels of your subjects';
    }
    return $missing;
}

/**
 * Send logged-out visitors to the login page, then back here afterwards.
 * Accounts with missing required details go to My Profile first
 * (profile_missing_fields()).
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . BASE_URL . '/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? BASE_URL . '/enduser/dashboard.php'));
        exit;
    }
    if (profile_missing_fields($user) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'edit-profile.php') {
        // The profile form lists what's missing.
        header('Location: ' . BASE_URL . '/enduser/edit-profile.php?complete=1');
        exit;
    }
    return $user;
}

/** Admins only; everyone else goes to the dashboard (the database enforces the same via is_admin()). */
function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        flash('error', 'That page is for Super Admins only.');
        header('Location: ' . BASE_URL . '/enduser/dashboard.php');
        exit;
    }
    return $user;
}

/** Admins and moderators (moderators need an organization, which is their scope). */
function require_staff(): array
{
    $user = require_login();
    $role = $user['role'] ?? 'user';
    if ($role !== 'admin' && ($role !== 'moderator' || empty($user['organization_id']))) {
        flash('error', $role === 'moderator' ? 'Your Admin account has no organization yet. Ask a Super Admin to set it.' : 'That page is for Admins and Super Admins only.');
        header('Location: ' . BASE_URL . '/enduser/dashboard.php');
        exit;
    }
    return $user;
}

/**
 * Whether the user may manage this event: admins every event, moderators
 * the events of their own organization (the database checks the same with
 * can_manage_event()).
 */
function can_manage_event(?array $event, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$event || !$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    return ($user['role'] ?? '') === 'moderator'
        && !empty($user['organization_id'])
        && ($event['organization_id'] ?? null) === $user['organization_id'];
}

/**
 * For the pages of one event (schedule, registrations, printouts,
 * evaluations): the event, if the logged-in admin / moderator may manage it.
 * Anyone else is sent back to Manage Events.
 * @return array{0: array, 1: array} [$user, $event]
 */
function require_event_manager(string $eventId): array
{
    $user = require_staff();
    $event = is_uuid($eventId) ? get_event_admin($eventId) : null;
    if (!$event || !can_manage_event($event, $user)) {
        flash('error', $event ? 'Admins can only manage their own organization\'s events.' : "That event doesn't exist anymore.");
        header('Location: ' . BASE_URL . '/admin/event-organizer.php');
        exit;
    }
    return [$user, $event];
>>>>>>> Stashed changes
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
    $rows = supabase_request('event_registrations', 'select=id&event_id=eq.' . urlencode($eventId));
    return count($rows);
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
        $dest = urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
        header('Location: login.php?redirect=' . $dest);
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
        header('Location: dashboard.php');
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
        return ['ok' => false, 'message' => 'Could not create the event. Please check the required fields and try again.'];
    }
    return ['ok' => true, 'message' => 'Event created.', 'id' => $result[0]['id'] ?? null];
}

/** Update an existing event. @return array{ok:bool, message:string} */
function update_event_admin(string $eventId, array $fields): array
{
    $token = $_SESSION['access_token'] ?? null;
    $result = supabase_request('events', 'id=eq.' . urlencode($eventId), 'PATCH', $fields, false, $token);
    if (empty($result)) {
        return ['ok' => false, 'message' => 'Could not save changes. Please try again.'];
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
