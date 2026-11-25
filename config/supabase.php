<?php
/**
 * Campus Event Hub — Supabase configuration + every data helper the pages use.
 *
 * All data lives in the "Thesis" Supabase project:
 *  - Table reads/writes go through PostgREST (supabase_request()).
 *  - Database functions go through supabase_rpc().
 *  - Image uploads go through Supabase Storage (upload_public_image()).
 *  - Sign up / log in / password reset go through Supabase Auth (GoTrue).
 *
 * Requests that must run AS the logged-in user (so the database's RLS rules
 * apply to them) use supabase_user_request(), which sends their access token.
 *
 * Get/rotate the keys from: Supabase Dashboard -> Project Settings -> API
 */

// ---- Project credentials --------------------------------------------------
// The anon key is public by design (it's safe in browsers); what a request
// may do is decided by the database's Row Level Security rules.
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://fgwaeugfkrljgbbgvaox.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc');

// "Today" (events happening today, deadlines, upcoming lists) is the Philippines' date.
date_default_timezone_set('Asia/Manila');

// ---- Running behind a load balancer (deploy/load-balancer/) -----------------
// Off unless these environment variables are set, so XAMPP works as before.
//   TRUSTED_PROXIES    the load balancer's addresses (IPs or CIDR ranges,
//                      comma-separated): requests from them carry the
//                      visitor's real IP in X-Forwarded-For.
//   SESSION_REDIS_URL  e.g. tcp://redis:6379 — logins are kept in Redis,
//                      shared by every app server, instead of in one
//                      server's files (otherwise a click that lands on
//                      another server would log the user out).

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

/** The Redis server shared by every app server (SESSION_REDIS_URL), or null when there isn't one. */
function shared_redis(): ?Redis
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
// IMAGE SLOT: site logo (Campus Event Hub hand + magnifier with the calendar "H") | file: public/assets/images/school-logo.png | square, 512×512
// Replace the file with a new logo (keep the file name). Transparent PNG works best.
// Both files hold the orange logo; the app icons and link preview (public/assets/images/app/)
// are the same logo on navy #0e2a5e.
/** Printouts (reports, attendance sheets, evaluation results) and the search-engine logo. */
const SCHOOL_LOGO = 'assets/images/school-logo.png';
/** Dark backgrounds: sidebar, phone top bar, footer, login cards. */
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
{
    // One connection for the whole page: the first call opens it (DNS lookup,
    // TCP and the HTTPS handshake — three or four trips to Supabase's servers in
    // Seoul); every later call on the page reuses it (keep-alive), so it costs
    // one trip. curl_reset() clears the previous call's options but keeps the
    // open connection.
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
    } else {
        curl_reset($ch);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_ENCODING => '', // accept compressed (gzip) answers: less to download
    ]);
    // Also trust the certificates Windows trusts: antivirus HTTPS scanning (e.g. AVG
    // Web Shield) re-signs Supabase's certificate with its own root, which only
    // Windows knows — without this every request fails and pages show no data.
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $started = microtime(true);
    $response = curl_exec($ch);
    $GLOBALS['supabase_http_calls'][] = microtime(true) - $started; // seconds per call (speed checks)
    $err = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // (No curl_close: the connection stays open for the page's next call.)

    if ($err) {
        return ['ok' => false, 'status' => 0, 'data' => ['message' => $err], 'raw' => ''];
    }
    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'data' => json_decode((string) $response, true),
        'raw' => (string) $response,
    ];
}

/**
 * One Supabase API call ($path like '/rest/v1/events?select=*').
 * - Sends $userToken (if given) so the request runs AS that user under RLS.
 * - If the logged-in user's token turns out to be expired (HTTP 401), renews
 *   it once with the refresh token and retries.
 * - On failure, remembers the error message for supabase_last_error().
 */
function supabase_call(string $method, string $path, $body = null, ?string $userToken = null, array $extraHeaders = []): array
{
    $headers = array_merge([
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . ($userToken ?: SUPABASE_ANON_KEY),
    ], $extraHeaders);
    if (is_array($body) || is_object($body)) {
        $headers[] = 'Content-Type: application/json';
    }

    $result = supabase_http($method, rtrim(SUPABASE_URL, '/') . $path, $headers, $body);

    static $retrying = false;
    if (!$result['ok'] && $result['status'] === 401 && !$retrying
        && $userToken !== null && $userToken === ($_SESSION['access_token'] ?? null)
        && supabase_refresh_session()) {
        $retrying = true;
        try {
            return supabase_call($method, $path, $body, $_SESSION['access_token'], $extraHeaders);
        } finally {
            $retrying = false;
        }
    }

    if (!$result['ok']) {
        if ($result['status'] === 401 && $userToken !== null) {
            $GLOBALS['supabase_session_expired'] = true;
        }
        $data = is_array($result['data']) ? $result['data'] : [];
        $GLOBALS['supabase_last_error'] = (string) ($data['message'] ?? $data['error_description'] ?? $data['msg']
            ?? $data['error'] ?? ('HTTP ' . $result['status']));
        error_log(sprintf('[supabase] %s %s failed (HTTP %d): %s', $method, strtok($path, '?'), $result['status'], $result['raw'] ?: json_encode($result['data'])));
    }
    return $result;
}

/**
 * Table request through PostgREST. Returns the rows, or [] on any failure
 * (the reason is available from supabase_last_error()).
 *
 * @param string $query Raw PostgREST query string, e.g. "select=*&order=start_date.asc"
 */
function supabase_request(string $table, string $query = '', string $method = 'GET', ?array $body = null, ?string $userToken = null): array
{
    $GLOBALS['supabase_last_error'] = '';

    // Bare `events` reads default to published events with their category/organization.
    if ($table === 'events' && $query === '' && $method === 'GET') {
        $query = EVENT_CARD_SELECT . '&status=eq.published&order=start_date.asc';
    }

    $result = supabase_call($method, '/rest/v1/' . $table . ($query !== '' ? '?' . $query : ''), $body, $userToken, [
        'Prefer: return=representation',
    ]);
    return ($result['ok'] && is_array($result['data'])) ? $result['data'] : [];
}

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
    '20261112120000_security_hardening.sql' => ['Security: registrations start as "registered" (only event admins mark attendance), and image uploads are limited to 5 MB', 'rpc', 'registrations_are_guarded', []],
    '20261120120000_venues.sql' => ['The venues list (On-campus places and Online platforms) for the event form\'s Venue dropdown', 'venues', 'select=id&limit=1'],
    '20261122120000_approval_notices.sql' => ['"Your event was approved / not approved" notices to the event\'s author, in the bell and by email', 'rpc', 'approval_notices_ready', []],
    '20261125130000_name_parts.sql' => ['Names in three parts (Last name, First name, Middle name) on sign-up and profiles', 'rpc', 'profile_names_ready', []],
    '20261125140000_more_organizations.sql' => ['More than one organization per account (Other organizations on sign-up, profiles and Users)', 'rpc', 'profile_other_organizations_ready', []],];

/**
 * The database updates that haven't been run yet (file => what it switches on).
 * One check per update (15+ requests to Supabase), so the answer is shared by
 * every visitor on this server (a file in the temp folder) instead of being
 * worked out again for each visitor: re-checked every 5 minutes while
 * something is missing, every hour once everything is in place. A new update
 * comes with a new version of the site, whose list of updates differs, so it
 * is checked straight away. ($fresh: check now — Database update page.)
 */
function pending_db_updates(bool $fresh = false): array
{
    static $memo = null; // the same page asks several times
    if (!$fresh && $memo !== null) return $memo;
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ceh-db-updates-'
        . md5(SUPABASE_URL . '|' . implode(',', array_keys(DB_UPDATES))) . '.json';
    if (!$fresh && is_file($cacheFile)) {
        $cache = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cache) && isset($cache['at']) && is_array($cache['pending'] ?? null)
            && $cache['at'] > time() - ($cache['pending'] ? 300 : 3600)) {
            return $memo = $cache['pending'];
        }
    }
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
    @file_put_contents($cacheFile, json_encode(['pending' => $pending, 'at' => time()]), LOCK_EX);
    return $memo = $pending;
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

/** The venue types with a list to choose from (the venues table); off-campus places are typed in on the event form. */
const VENUE_LISTS = ['on_campus' => 'On-campus', 'online' => 'Online'];

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

/**
 * The hours an event may start and end in (Create / Edit Event): 4:00 AM to
 * 10:00 PM. Times stay optional; these limits apply to the ones given.
 */
const EVENT_HOURS = ['04:00', '22:00'];
const EVENT_HOURS_LABEL = '4:00 AM and 10:00 PM';

/** Whether a time ("13:30" or "13:30:00") is within EVENT_HOURS. */
function event_time_allowed(string $time): bool
{
    $hm = substr($time, 0, 5);
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hm) && $hm >= EVENT_HOURS[0] && $hm <= EVENT_HOURS[1];
}

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
        $token = $_SESSION['access_token'] ?? $token;
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
        return 'The email couldn\'t be sent right now. Please try again later, and if it keeps happening, tell the site\'s admin (the site\'s email sender isn\'t working).';
    }
    return $msg;
}

// ---- Passwords ----------------------------------------------------------------
// Shortest password for a new account or a password change (sign-up, reset
// link, profile). Keep it the same as Supabase → Authentication → Sign In /
// Providers → Email → "Minimum password length", which also guards sign-ups
// made straight to Supabase. Older, shorter passwords still log in.
const PASSWORD_MIN_LENGTH = 8;

// ---- Login throttling (password guessing) ------------------------------------
// Every login goes from this server to Supabase, so Supabase's own limit sees
// this server's address, not the guesser's. So the login page counts wrong
// passwords itself, before asking Supabase:
//   - per email: LOGIN_MAX_FAILS_EMAIL wrong passwords in LOGIN_WINDOW_SECONDS
//     lock that account;
//   - per IP address: LOGIN_MAX_FAILS_IP (one guesser trying many accounts;
//     higher, since a computer lab shares one address).
// Each lock is longer than the one before (LOGIN_LOCK_STEPS): 1 minute, then
// 15 minutes, then 1 hour for the third and every later one. Only the locks
// of the last LOGIN_STRIKE_RESET seconds (24 hours) count: each lock drops
// off 24 hours after it happened, so being locked twice on one day doesn't
// make the next day's first lock an hour long — it's back to 1 minute. A
// successful login starts the steps over at once. A lock always ends on its
// own, so nobody can lock another person out for good ("Forgot Password?"
// works during a lock too).
const LOGIN_WINDOW_SECONDS = 900; // wrong passwords counted over 15 minutes
const LOGIN_MAX_FAILS_EMAIL = 5;
const LOGIN_MAX_FAILS_IP = 30;
const LOGIN_LOCK_STEPS = [60, 900, 3600]; // 1st lock 1 min, 2nd 15 min, 3rd+ 1 hour
const LOGIN_STRIKE_RESET = 86400;         // locks older than 24 hours no longer count

/**
 * Read, change and save one counter's state, under a lock:
 *   ['fails' => [unix times of recent wrong passwords],
 *    'locks' => [unix times of the locks in the last 24 hours],
 *    'locked_until' => unix time]
 * $change gets the state (old wrong passwords and old locks already dropped)
 * and returns the new one, or null to delete it. Kept in Redis when the site
 * runs on several servers (so it holds whichever server answers), else in
 * this server's temp folder. If it can't be stored, nobody is locked out.
 */
function login_state(string $key, callable $change): array
{
    $id = md5(SUPABASE_URL . '|' . $key);
    $now = time();
    $tidy = function ($s) use ($now): array {
        $s = is_array($s) ? $s : [];
        $s['fails'] = array_values(array_filter((array) ($s['fails'] ?? []), fn($t) => is_int($t) && $t > $now - LOGIN_WINDOW_SECONDS));
        $s['locks'] = array_values(array_filter((array) ($s['locks'] ?? []), fn($t) => is_int($t) && $t > $now - LOGIN_STRIKE_RESET));
        $s['locked_until'] = (int) ($s['locked_until'] ?? 0);
        unset($s['strikes'], $s['last_lock']); // the older format
        return $s;
    };
    $keep = LOGIN_STRIKE_RESET + max(LOGIN_LOCK_STEPS);

    if ($redis = shared_redis()) {
        try {
            $k = 'ceh:login:' . $id;
            $new = $change($tidy(json_decode((string) $redis->get($k), true)));
            if ($new === null) $redis->del($k);
            else $redis->setex($k, $keep, json_encode($new));
            return $new ?? $tidy([]);
        } catch (Throwable $e) {
            // Redis hiccup: fall back to this server's own count.
        }
    }
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ceh-login-' . $id . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) return $tidy([]);
    flock($fp, LOCK_EX);
    $new = $change($tidy(json_decode((string) stream_get_contents($fp), true)));
    ftruncate($fp, 0);
    rewind($fp);
    if ($new !== null) fwrite($fp, json_encode($new));
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($new === null) @unlink($file);
    return $new ?? $tidy([]);
}

/** The counters a login attempt for $email is checked against: [key, max wrong passwords]. */
function login_throttle_keys(string $email): array
{
    return [
        ['email:' . strtolower(trim($email)), LOGIN_MAX_FAILS_EMAIL],
        ['ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), LOGIN_MAX_FAILS_IP],
    ];
}

/** Seconds until $email may be tried again from this address (0 = it may now). */
function login_locked_for(string $email): int
{
    $until = 0;
    foreach (login_throttle_keys($email) as [$key]) {
        $until = max($until, login_state($key, fn($s) => $s)['locked_until']);
    }
    return max(0, $until - time());
}

/** Count a wrong password for $email (and this address); enough of them lock it. */
function login_record_failure(string $email): void
{
    foreach (login_throttle_keys($email) as [$key, $max]) {
        login_state($key, function (array $s) use ($max): array {
            $now = time();
            $s['fails'][] = $now;
            if (count($s['fails']) >= $max) {
                $s['locks'][] = $now; // this lock, with the others of the last 24 hours
                $steps = LOGIN_LOCK_STEPS;
                $s['locked_until'] = $now + $steps[min(count($s['locks']), count($steps)) - 1];
                $s['fails'] = []; // after the lock, another full set is needed for the next one
            }
            return $s;
        });
    }
}

/** A successful login: forget the email's wrong passwords and locks. */
function login_clear_failures(string $email): void
{
    login_state(login_throttle_keys($email)[0][0], fn($s) => null);
}

/** "Too many failed logins…" for a lock of $seconds. */
function login_locked_message(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / 60));
    $wait = $minutes >= 60 && $minutes % 60 === 0
        ? ($minutes / 60) . ' hour' . ($minutes === 60 ? '' : 's')
        : $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    return 'Too many failed login attempts. Please wait ' . $wait
        . ' and try again, or use "Forgot Password?" to set a new password.';
}

/**
 * Eye button that shows / hides the password field with this id. Goes inside
 * a <div class="password-wrap"> next to the field; assets/js/auth.js does the
 * switching (same button as the login page's).
 */
function password_toggle_button(string $inputId): string
{
    $img = BASE_URL . '/assets/images/login/';
    return '<button type="button" class="login-field-toggle" data-toggle-password="' . htmlspecialchars($inputId) . '" aria-label="Show password">'
        . '<img class="icon-hidden" src="' . $img . 'icon-eye-off.svg" alt="" width="22" height="22">'
        . '<img class="icon-visible" src="' . $img . 'icon-eye.svg" alt="" width="22" height="22" hidden>'
        . '</button>';
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
    if (!$result['ok']) {
        return ['ok' => false, 'message' => auth_error_message($result, 'Could not send the email. Please try again in a few minutes.')];
    }
    return ['ok' => true, 'message' => 'If that address has an account waiting to be confirmed, a new confirmation link is on its way. Check your inbox and spam folder.'];
}

/**
 * Create an account. The profile row (account type, department,
 * organization, year level, course, regular/irregular) and the school ID
 * are created by the database's handle_new_user() trigger from the
 * metadata sent here.
 *
 * @param array $profile the 'fields' of validate_account_fields() + 'school_id'
 * @return array{ok:bool, message:string, needsEmailConfirmation?:bool}
 */
function supabase_auth_signup(string $email, string $password, string $fullName, array $profile = []): array
{
    $email = strtolower(trim($email));
    if (!email_allowed($email)) {
        return ['ok' => false, 'message' => school_email_rule_message()];
    }
    // With "Confirm email" on, Supabase emails a link that comes back to auth/callback.php.
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/signup?redirect_to=' . rawurlencode(auth_callback_url('signup')), [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], [
        'email' => $email,
        'password' => $password,
        'data' => array_filter([
            'full_name' => $fullName,
            // The name in parts (the database's handle_new_user() saves them once the name_parts update is run).
            'last_name' => $profile['last_name'] ?? null,
            'first_name' => $profile['first_name'] ?? null,
            'middle_name' => $profile['middle_name'] ?? null,
            'account_type' => $profile['account_type'] ?? null,
            'department_id' => $profile['department_id'] ?? null,
            'organization_id' => $profile['organization_id'] ?? null,
            'other_organization_ids' => $profile['other_organization_ids'] ?? null,
            'school_id' => $profile['school_id'] ?? null,
            'year_level' => $profile['year_level'] ?? null,
            'course' => $profile['course'] ?? null,
            'student_type' => $profile['student_type'] ?? null,
            'irregular_year_levels' => $profile['irregular_year_levels'] ?? null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []),
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => auth_error_message($result, 'Sign up failed.')];
    }
    $data = $result['data'];
    $user = $data['user'] ?? (isset($data['id']) ? $data : null);
    if (!$user) {
        return ['ok' => false, 'message' => 'Unexpected response from the sign-in server.'];
    }
    // With "Confirm email" on, there's no session until the emailed link is clicked.
    if (empty($data['access_token'])) {
        return ['ok' => true, 'message' => 'We sent a confirmation link to ' . $email . '. Open it to activate your account (check your spam folder too).', 'needsEmailConfirmation' => true];
    }
    start_user_session($user, $data['access_token'], $data['refresh_token'] ?? null);
    return ['ok' => true, 'message' => 'Account created.'];
}

/**
 * Log in with email + password and store the session. Accounts whose email
 * isn't confirmed yet are told so ('unconfirmed' => true, to offer a new
 * link); accounts that may not sign in any more are logged straight out.
 */
function supabase_auth_signin(string $email, string $password): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/token?grant_type=password', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], ['email' => strtolower(trim($email)), 'password' => $password]);

    if (!$result['ok']) {
        $data = is_array($result['data']) ? $result['data'] : [];
        $raw = (string) ($data['error_description'] ?? $data['msg'] ?? $data['message'] ?? '');
        if (($data['error_code'] ?? '') === 'email_not_confirmed' || stripos($raw, 'not confirmed') !== false) {
            return ['ok' => false, 'unconfirmed' => true, 'message' => 'Please confirm your email first: open the link we sent to ' . strtolower(trim($email)) . ' (check your spam folder too).'];
        }
        return [
            'ok' => false,
            // Supabase answered "wrong email or password" (not an outage or its own rate
            // limit): the login page counts these to stop password guessing.
            'wrong_password' => ($data['error_code'] ?? '') === 'invalid_credentials' || (int) $result['status'] === 400,
            'message' => auth_error_message($result, 'Invalid email or password.'),
        ];
    }
    $data = $result['data'];
    if (empty($data['user']) || empty($data['access_token'])) {
        return ['ok' => false, 'message' => 'Unexpected response from the sign-in server.'];
    }
    start_user_session($data['user'], $data['access_token'], $data['refresh_token'] ?? null);
    if (($_SESSION['user']['sign_in_allowed'] ?? true) === false) {
        supabase_auth_signout();
        return ['ok' => false, 'message' => blocked_account_message()];
    }
    return ['ok' => true, 'message' => 'Logged in.'];
}

/**
 * Start a session from tokens Supabase put in the URL after Google sign-in,
 * a confirmation link or a password-reset link (see auth/callback.php). The
 * token is checked with Supabase before it's trusted, and the account must
 * be allowed to sign in (school email, exception, or Super Admin).
 */
function supabase_session_from_tokens(string $accessToken, ?string $refreshToken): array
{
    $result = supabase_http('GET', rtrim(SUPABASE_URL, '/') . '/auth/v1/user', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . $accessToken,
    ]);
    if (!$result['ok'] || empty($result['data']['id'])) {
        return ['ok' => false, 'message' => 'This sign-in link is invalid or has expired. Please try again.'];
    }
    start_user_session($result['data'], $accessToken, $refreshToken);
    if (($_SESSION['user']['sign_in_allowed'] ?? true) === false) {
        $viaGoogle = ($result['data']['app_metadata']['provider'] ?? '') === 'google';
        supabase_auth_signout();
        return ['ok' => false, 'message' => $viaGoogle ? 'Use your LCUP Google account (@' . SCHOOL_EMAIL_DOMAIN . '). ' . school_email_rule_message() : blocked_account_message()];
    }
    return ['ok' => true, 'message' => 'Logged in.'];
}

/** Email a password-reset link that comes back to auth/callback.php. */
function supabase_auth_recover(string $email): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/recover?redirect_to=' . rawurlencode(app_url('auth/callback.php?type=recovery')), [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], ['email' => $email]);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => auth_error_message($result, 'Could not send the reset email. Please try again later.')];
    }
    // Same message whether or not the email has an account (don't reveal who's registered).
    return ['ok' => true, 'message' => 'If that email has an account, a password reset link is on its way. Check your inbox (and spam folder).'];
}

/** Change the logged-in user's password. */
function supabase_auth_update_password(string $newPassword): array
{
    $result = supabase_call('PUT', '/auth/v1/user', ['password' => $newPassword], session_access_token());
    if (!$result['ok']) {
        return ['ok' => false, 'message' => auth_error_message($result, 'Could not change the password.')];
    }
    return ['ok' => true, 'message' => 'Password updated.'];
}

/** Supabase Auth's public settings (sign-in providers, whether emails must be confirmed). [] if unreachable. */
function auth_settings(): array
{
    static $settings = null;
    if ($settings === null) {
        $result = supabase_http('GET', rtrim(SUPABASE_URL, '/') . '/auth/v1/settings', ['apikey: ' . SUPABASE_ANON_KEY]);
        $settings = $result['ok'] && is_array($result['data']) ? $result['data'] : [];
    }
    return $settings;
}

/** Whether a sign-in provider (e.g. 'google') is switched on in Supabase Auth. */
function auth_provider_enabled(string $provider): bool
{
    return !empty(auth_settings()['external'][$provider]);
}

/** Whether new accounts must confirm their email (Supabase: Authentication → Sign In / Providers → Email → Confirm email). null = unknown. */
function auth_requires_email_confirmation(): ?bool
{
    $settings = auth_settings();
    return array_key_exists('mailer_autoconfirm', $settings) ? !$settings['mailer_autoconfirm'] : null;
}

/**
 * Where the "Google" button sends people (Supabase handles the Google part).
 * hd=* asks Google to offer school / work accounts only, not personal Gmail.
 * (Not hd=email.lcup.edu.ph: Google matches it against the school's main
 * Google domain, which may be a different one, and would then hide the
 * students' accounts.) auth/callback.php and the database refuse any address
 * that isn't @email.lcup.edu.ph.
 * prompt=select_account: Google always shows its account chooser first, even
 * when the browser is signed in to just one account, so people can pick (or
 * add) the right school account instead of being signed in automatically.
 */
function google_signin_url(): string
{
    return rtrim(SUPABASE_URL, '/') . '/auth/v1/authorize?provider=google&hd=*&prompt=select_account'
        . '&redirect_to=' . rawurlencode(auth_callback_url());
}

/** Log out: Active Users -1, revoke the Supabase refresh token, clear the session. */
function supabase_auth_signout(): void
{
    session_start_once();
    presence_clear();
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

/** Store a fresh login in the PHP session, with the user's profile from the database. */
function start_user_session(array $authUser, string $accessToken, ?string $refreshToken): void
{
    session_start_once();
    session_regenerate_id(true);
    $_SESSION['access_token'] = $accessToken;
    $_SESSION['refresh_token'] = $refreshToken;
    $_SESSION['user'] = [
        'id' => $authUser['id'],
        'email' => $authUser['email'] ?? '',
        'full_name' => $authUser['user_metadata']['full_name'] ?? $authUser['user_metadata']['name'] ?? explode('@', (string) ($authUser['email'] ?? 'user'))[0],
        'role' => 'user',
    ];
    reload_session_profile();
}

/** Profile columns kept in the session (see current_user()). */
const SESSION_PROFILE_SELECT = 'full_name,role,account_type,department_id,organization_id,year_level,course,student_type,irregular_year_levels,school_ids(school_id,verified_at)';

/**
 * Whether profiles have the name parts (last / first / middle name) — the
 * 20261125130000_name_parts.sql update. Until it's run, the site keeps using
 * the single Full name field everywhere.
 */
function name_parts_ready(): bool
{
    static $ready = null;
    return $ready ??= !isset(pending_db_updates()['20261125130000_name_parts.sql']);
}

/** The profile columns for the name: full_name, plus the parts once they exist. */
function profile_name_columns(): string
{
    return 'full_name' . (name_parts_ready() ? ',last_name,first_name,middle_name' : '');
}

/**
 * Whether profiles have "Other organizations" (other_organization_ids — the
 * 20261125140000_more_organizations.sql update). Until it's run, accounts
 * have their one organization only.
 */
function other_orgs_ready(): bool
{
    static $ready = null;
    return $ready ??= !isset(pending_db_updates()['20261125140000_more_organizations.sql']);
}

/** The profile columns for organizations: organization_id, plus the other ones once they exist. */
function profile_org_columns(): string
{
    return 'organization_id' . (other_orgs_ready() ? ',other_organization_ids' : '');
}

/** The account's other organizations' names (from profiles.other_organization_ids), in the list's order. */
function other_organization_names(array $profile, array $organizations): array
{
    $names = array_column($organizations, 'name', 'id');
    $ids = is_array($profile['other_organization_ids'] ?? null) ? $profile['other_organization_ids'] : [];
    $out = array_values(array_filter(array_map(fn($id) => $names[$id] ?? null, $ids)));
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** "First Middle Last" from the parts (what full_name holds and the site shows). */
function compose_full_name(string $first, string $middle, string $last): string
{
    return trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));
}

/** The three name fields from a form (trimmed, spaces tidied), and the full name they make. */
function name_parts_from_post(): array
{
    $part = fn(string $key): string => trim(preg_replace('/\s+/', ' ', post_param($key)));
    $parts = ['last_name' => $part('last_name'), 'first_name' => $part('first_name'), 'middle_name' => $part('middle_name')];
    return $parts + ['full_name' => compose_full_name($parts['first_name'], $parts['middle_name'], $parts['last_name'])];
}

/** Problems with the three name fields (Last and First are required; 60 characters each). */
function name_parts_errors(array $parts): array
{
    $errors = [];
    if ($parts['last_name'] === '') $errors[] = 'Please enter your last name.';
    if ($parts['first_name'] === '') $errors[] = 'Please enter your first name.';
    foreach (['last_name' => 'Last name', 'first_name' => 'First name', 'middle_name' => 'Middle name'] as $key => $label) {
        if (mb_strlen($parts[$key]) > 60) $errors[] = $label . ' must be 60 characters or fewer.';
    }
    return $errors;
}

/** Re-read name / role / account details from `profiles` into the session. */
function reload_session_profile(): void
{
    if (empty($_SESSION['user']['id'])) return;
    $id = '&id=eq.' . urlencode($_SESSION['user']['id']);
    $select = str_replace(['full_name,', 'organization_id,'], [profile_name_columns() . ',', profile_org_columns() . ','], SESSION_PROFILE_SELECT);
    $rows = supabase_request('profiles', 'select=' . $select . $id, 'GET', null, $_SESSION['access_token'] ?? null);
    $schemaReady = true;
    if (empty($rows) && supabase_last_error() !== '' && !supabase_session_expired()) {
        // The database doesn't have the new account columns yet (migration
        // 20260930120000_solis_suggestions.sql not run): read the columns that
        // exist, so roles (admins!) still work in the meantime.
        $rows = supabase_request('profiles', 'select=full_name,role,year_level,course,organization_id' . $id, 'GET', null, $_SESSION['access_token'] ?? null);
        $schemaReady = false;
    }
    if (!empty($rows[0])) {
        $row = $rows[0];
        $row['school_id'] = $row['school_ids']['school_id'] ?? null;
        $row['school_id_verified'] = !empty($row['school_ids']['verified_at']);
        unset($row['school_ids']);
        $row['schema_ready'] = $schemaReady;
        // Read on its own: before migration 20261017120000_super_admin_approvals.sql the column
        // doesn't exist, and that mustn't stop the rest of the profile from loading.
        $flag = supabase_request('profiles', 'select=is_super_admin' . $id, 'GET', null, $_SESSION['access_token'] ?? null);
        $row['is_super_admin'] = !empty($flag[0]['is_super_admin']);
        $GLOBALS['supabase_last_error'] = '';
        $_SESSION['user'] = array_merge($_SESSION['user'], $row);
        // School emails only (Super Admins and exceptions excepted) — only
        // decided when the profile could be read, so a failed read never
        // locks anyone out.
        $_SESSION['user']['sign_in_allowed'] = account_may_sign_in($_SESSION['user']['email'] ?? '', $_SESSION['user']['role'] ?? 'user');
    }
    $_SESSION['user_checked_at'] = time();
}

/** Appearance chosen with the top bar's switcher (includes/theme-switch.php), kept in the "theme" cookie. */
const THEMES = ['light' => 'Light', 'dim' => 'Dim', 'dark' => 'Dark'];

function current_theme(): string
{
    $theme = is_string($_COOKIE['theme'] ?? null) ? $_COOKIE['theme'] : 'light';
    return isset(THEMES[$theme]) ? $theme : 'light';
}

/**
 * <head> tags that make the site installable on phones ("Add to Home
 * screen" / "Install app"): the app manifest, icons, the browser-bar colour
 * and assets/js/app.js (the service worker and the Install app button).
 */
function app_head_tags(): string
{
    $b = htmlspecialchars(BASE_URL);
    // ?v=<file time>: a new icon file is fetched at once instead of the browser's cached copy.
    $v = fn(string $file): string => '?v=' . (@filemtime(__DIR__ . '/../public/' . $file) ?: '1');
    return '<link rel="manifest" href="' . $b . '/manifest.json">' . "\n"
        . '<meta name="theme-color" content="#213e60">' . "\n"
        . '<link rel="icon" type="image/png" sizes="192x192" href="' . $b . '/assets/images/app/icon-192.png' . $v('assets/images/app/icon-192.png') . '">' . "\n"
        . '<link rel="apple-touch-icon" href="' . $b . '/assets/images/app/apple-touch-icon.png' . $v('assets/images/app/apple-touch-icon.png') . '">' . "\n"
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
    // The logo on the site's navy, 1200×630 (the shape link previews use, so nothing is cropped).
    $image = app_url('assets/images/app/share-preview.png') . '?v=' . (@filemtime(__DIR__ . '/../public/assets/images/app/share-preview.png') ?: '1');
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
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="630">',
        '<meta property="og:image:alt" content="' . SITE_NAME . ' logo">',
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
}

// =============================================================================
// PROFILES
// =============================================================================

/**
 * Check the account details of the sign-up / My Profile form.
 * Required for everyone: student or faculty, department, organization (one
 * of that department's). Students also: school ID (valid, not used by
 * another account), course, highest year level, regular or irregular, and
 * for irregular students the year levels of their subjects (which must
 * include the highest one).
 *
 * @param array  $in  raw form values (post_param strings; irregular_year_levels may be an array)
 * @param ?string $ownSchoolId the account's current school ID (it isn't "taken" by itself)
 * @return array{fields: array, school_id: ?string, errors: string[]}
 *         fields = profile columns to save
 */
function validate_account_fields(array $in, array $departments, array $organizations, ?string $ownSchoolId = null): array
{
    $errors = [];
    $type = (string) ($in['account_type'] ?? '');
    $departmentId = (string) ($in['department_id'] ?? '');
    $organizationId = (string) ($in['organization_id'] ?? '');
    $orgsById = array_column($organizations, null, 'id');

    if (!isset(ACCOUNT_TYPES[$type])) $errors[] = 'Please choose whether you are a student or faculty.';
    if (!in_array($departmentId, array_column($departments, 'id'), true)) $errors[] = 'Please choose your department.';
    if (!isset($orgsById[$organizationId])) {
        $errors[] = 'Please choose your organization.';
    } elseif ($departmentId !== '' && !empty($orgsById[$organizationId]['department_id']) && $orgsById[$organizationId]['department_id'] !== $departmentId) {
        $errors[] = 'That organization belongs to another department. Choose one of your department\'s organizations.';
    }

    $fields = [
        'account_type' => $type ?: null,
        'department_id' => $departmentId ?: null,
        'organization_id' => $organizationId ?: null,
        'year_level' => null,
        'course' => null,
        'student_type' => null,
        'irregular_year_levels' => null,
    ];
    // Other organizations (optional, any department): known ones only, never the main one, each once.
    if (array_key_exists('other_organization_ids', $in) && other_orgs_ready()) {
        $others = array_map('strval', is_array($in['other_organization_ids']) ? $in['other_organization_ids'] : []);
        $fields['other_organization_ids'] = array_values(array_unique(array_filter($others, fn($id) => isset($orgsById[$id]) && $id !== $organizationId)));
        if (count($fields['other_organization_ids']) > 20) $errors[] = 'Choose up to 20 other organizations.';
    }
    $schoolId = null;

    if ($type === 'student') {
        $schoolId = normalize_school_id($in['school_id'] ?? '');
        $yearLevel = parse_year_levels($in['year_level'] ?? '')[0] ?? null;
        $studentType = (string) ($in['student_type'] ?? '');
        $course = trim((string) ($in['course'] ?? ''));

        // The account's own saved ID, unchanged (it may be from before the format was required).
        $unchanged = (string) $ownSchoolId !== '' && $schoolId === normalize_school_id($ownSchoolId);
        if ($schoolId === '') {
            $errors[] = 'Your School ID is required.';
        } elseif (!$unchanged && !valid_school_id($schoolId)) {
            $errors[] = 'Please enter your School ID in the format ' . SCHOOL_ID_EXAMPLE . ' (2 digits, 4 digits, then 3 digits).';
        } elseif (!$unchanged && school_id_taken($schoolId)) {
            $errors[] = 'That School ID is already registered to another account. If it\'s yours, contact an admin.';
        }
        if ($course === '') $errors[] = 'Your course is required.';
        if (mb_strlen($course) > 60) $errors[] = 'Course must be 60 characters or fewer.';
        if (!$yearLevel) $errors[] = 'Please choose your highest year level.';
        if (!isset(STUDENT_TYPES[$studentType])) $errors[] = 'Please choose whether you are a regular or irregular student.';

        $fields['year_level'] = $yearLevel;
        $fields['course'] = $course !== '' ? $course : null;
        $fields['student_type'] = $studentType ?: null;

        if ($studentType === 'irregular') {
            $levels = parse_year_levels($in['irregular_year_levels'] ?? []);
            if ($yearLevel) {
                $levels = parse_year_levels(array_merge($levels, [$yearLevel]));
            }
            if (count($levels) < 2) {
                $errors[] = 'Irregular students: pick every year level you have subjects in (at least one besides your highest year level).';
            } elseif ($yearLevel && max($levels) > $yearLevel) {
                $errors[] = 'Your highest year level must be the highest of the year levels you picked.';
            }
            $fields['irregular_year_levels'] = $levels;
        }
    }
    return ['fields' => $fields, 'school_id' => $schoolId, 'errors' => $errors];
}

/**
 * Update the logged-in user's own details (never the role — the database
 * blocks that). $schoolId (students): saved in school_ids — added if
 * missing, or corrected while it isn't verified yet.
 */
function update_my_profile(array $fields, ?string $schoolId = null): array
{
    $user = current_user();
    if (!$user) return ['ok' => false, 'message' => 'Please log in again.'];
    $schemaReady = ($user['schema_ready'] ?? true) !== false;

    if ($schemaReady && $schoolId !== null && $schoolId !== ($user['school_id'] ?? null)) {
        if (!empty($user['school_id_verified'])) {
            return ['ok' => false, 'message' => 'Your School ID is already verified. Ask an admin if it needs to change.'];
        }
        $rows = !empty($user['school_id'])
            ? supabase_user_request('school_ids', 'user_id=eq.' . urlencode($user['id']), 'PATCH', ['school_id' => $schoolId])
            : supabase_user_request('school_ids', '', 'POST', ['user_id' => $user['id'], 'school_id' => $schoolId]);
        if (empty($rows)) {
            return ['ok' => false, 'message' => friendly_error('Could not save your School ID. Please try again.')];
        }
    }

    $allowed = array_intersect_key($fields, array_flip($schemaReady
        ? ['full_name', 'last_name', 'first_name', 'middle_name', 'account_type', 'department_id', 'organization_id', 'other_organization_ids', 'year_level', 'course', 'student_type', 'irregular_year_levels']
        : ['full_name', 'organization_id', 'year_level', 'course'])); // database not updated yet: the older columns only
    // Moderators' organization is their scope: only an admin changes it (the database refuses too).
    if (($user['role'] ?? '') === 'moderator') {
        unset($allowed['organization_id']);
    }
    if (array_key_exists('other_organization_ids', $allowed)) $allowed['other_organization_ids'] ??= []; // none = an empty list
    if ($allowed) {
        $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($user['id']), 'PATCH', $allowed);
        if (empty($rows)) {
            return ['ok' => false, 'message' => friendly_error('Could not save your profile. Please try again.')];
        }
    }
    reload_session_profile();
    return ['ok' => true, 'message' => 'Profile saved.'];
}

// ---- Profile edits need the super admin's approval ----------------------------
// (migration 20261017120000_super_admin_approvals.sql: profile_change_requests,
// review_profile_change(); the database refuses direct changes too.)

/** The details on the profile form, as shown to the reviewer. */
const PROFILE_DETAIL_LABELS = [
    'last_name' => 'Last name',
    'first_name' => 'First name',
    'middle_name' => 'Middle name',
    'full_name' => 'Full name',
    'account_type' => 'Student or faculty',
    'department_id' => 'Department',
    'organization_id' => 'Organization',
    'other_organization_ids' => 'Other organizations',
    'school_id' => 'School ID',
    'course' => 'Course',
    'year_level' => 'Highest year level',
    'student_type' => 'Student status',
    'irregular_year_levels' => 'Year levels of subjects',
];

/** One detail in a comparable form (empty = null). */
function profile_value_norm(string $key, $value)
{
    if ($key === 'irregular_year_levels') return parse_year_levels($value ?? []) ?: null;
    if ($key === 'other_organization_ids') {
        $ids = array_values(array_unique(array_filter(array_map('strval', is_array($value) ? $value : []), 'is_uuid')));
        sort($ids);
        return $ids ?: null;
    }
    if ($key === 'year_level') return $value === null || $value === '' ? null : (int) $value;
    if ($key === 'school_id') return ($id = normalize_school_id(is_string($value) ? $value : null)) === '' ? null : $id;
    $value = is_string($value) ? trim($value) : $value;
    return $value === '' ? null : $value;
}

/**
 * Compare the profile form with the saved profile.
 * @param array $current the saved profile (current_user(), with school_id)
 * @param array $new     full_name + validate_account_fields() fields + school_id
 * @return array{fills: array, changes: array} fills = details that were empty,
 *         changes = saved details that would change
 */
function profile_detail_diff(array $current, array $new): array
{
    $out = ['fills' => [], 'changes' => []];
    foreach (array_keys(PROFILE_DETAIL_LABELS) as $key) {
        if (!array_key_exists($key, $new)) continue;
        $old = profile_value_norm($key, $current[$key] ?? null);
        $value = profile_value_norm($key, $new[$key]);
        if ($old === $value || ($key === 'school_id' && $value === null)) continue;
        $out[$old === null ? 'fills' : 'changes'][$key] = $value;
    }
    return $out;
}

/** A detail as people read it: department / organization names, "3rd Year", … ('—' when empty). */
function profile_value_label(string $key, $value, array $departments, array $organizations): string
{
    $value = profile_value_norm($key, $value);
    if ($value === null) return '—';
    return match ($key) {
        'account_type' => label_of(ACCOUNT_TYPES, $value),
        'department_id' => department_label(array_column($departments, null, 'id')[$value] ?? null) ?: 'Unknown department',
        'organization_id' => array_column($organizations, 'name', 'id')[$value] ?? 'Unknown organization',
        'other_organization_ids' => implode(', ', other_organization_names(['other_organization_ids' => $value], $organizations)) ?: '—',
        'year_level' => year_label($value),
        'student_type' => label_of(STUDENT_TYPES, $value),
        'irregular_year_levels' => year_levels_label($value) ?: 'Every year level',
        default => (string) $value,
    };
}

/**
 * Save the profile form. Details that were still empty are saved straight
 * away (completing a profile). Changes to saved details go to the super
 * admin as a change request — except the super admin's own, which are saved.
 * $completing (the "complete your profile" form): only the empty details are
 * saved; changes to the others have to be sent from Edit my profile.
 * @return array{ok: bool, message: string, requested?: bool, unchanged?: bool}
 */
function save_profile_details(array $new, bool $completing = false): array
{
    $user = current_user();
    if (!$user) return ['ok' => false, 'message' => 'Please log in again.'];
    $diff = profile_detail_diff($user, $new);
    $pending = $completing ? null : get_my_profile_request('pending');

    if (!$diff['fills'] && !$diff['changes']) {
        if ($pending && withdraw_profile_request($pending['id'])['ok']) {
            return ['ok' => true, 'unchanged' => true, 'message' => 'That\'s the same as your current profile, so your waiting request was withdrawn.'];
        }
        return ['ok' => true, 'unchanged' => true, 'message' => 'Nothing changed.'];
    }

    $save = function (array $fields): array {
        $schoolId = $fields['school_id'] ?? null;
        unset($fields['school_id']);
        return update_my_profile($fields, $schoolId);
    };
    $later = 'Changes to details you had already filled in need a Super Admin\'s approval — send them from Edit my profile.';

    // Only empty details filled in, or the super admin's own profile (or no
    // super admin yet — the database update isn't run): saved now.
    if (!$diff['changes'] || !profile_edits_need_approval($user)) {
        $result = $save($diff['fills'] + $diff['changes']);
        if ($result['ok'] && $pending) withdraw_profile_request($pending['id']); // replaced by what was just saved
        return $result;
    }
    // Completing a profile: the empty details are saved, the changes aren't.
    if ($completing) {
        if (!$diff['fills']) return ['ok' => false, 'message' => $later];
        $result = $save($diff['fills']);
        return $result['ok'] ? ['ok' => true, 'message' => 'Saved. ' . $later] : $result;
    }
    // Everything else waits for the super admin (empty details too, as they may depend on the changes).
    return send_profile_change_request($diff['fills'] + $diff['changes']);
}

/** Whether this account's profile changes wait for a Super Admin (not a Super Admin's own). */
function profile_edits_need_approval(array $user): bool
{
    return !is_super_admin_user($user) && approvals_ready();
}

/** True once migration 20261017120000_super_admin_approvals.sql is run (approvals switched on). */
function approvals_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        supabase_request('profiles', 'select=id&is_super_admin=is.true&limit=1');
        $ready = supabase_last_error() === '';
        $GLOBALS['supabase_last_error'] = '';
    }
    return $ready;
}

/** The logged-in user's latest change request ($status: only one with that status), or null. */
function get_my_profile_request(?string $status = null): ?array
{
    $user = current_user();
    if (!$user) return null;
    $rows = supabase_user_request('profile_change_requests', 'select=*&user_id=eq.' . urlencode($user['id'])
        . ($status ? '&status=eq.' . urlencode($status) : '') . '&order=created_at.desc&limit=1');
    return $rows[0] ?? null;
}

/** Send changes for approval (a request that's still waiting is updated instead). */
function send_profile_change_request(array $changes): array
{
    $user = current_user();
    $pending = get_my_profile_request('pending');
    $rows = $pending
        ? supabase_user_request('profile_change_requests', 'id=eq.' . urlencode($pending['id']) . '&status=eq.pending', 'PATCH', ['changes' => $changes])
        : supabase_user_request('profile_change_requests', '', 'POST', ['user_id' => $user['id'], 'changes' => $changes]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => str_contains(supabase_last_error(), 'profile_change_requests')
            ? 'Profile edits need the latest database update first. Please ask an admin to run it.'
            : friendly_error('Could not send your changes. Please try again.')];
    }
    return ['ok' => true, 'requested' => true, 'message' => ($pending ? 'Your waiting request was updated.' : 'Sent to a Super Admin for approval.')
        . ' Your profile keeps its current details until the changes are approved.'];
}

/** Take back the logged-in user's waiting request. */
function withdraw_profile_request(string $requestId): array
{
    $user = current_user();
    $rows = is_uuid($requestId) ? supabase_user_request('profile_change_requests',
        'id=eq.' . urlencode($requestId) . '&user_id=eq.' . urlencode((string) ($user['id'] ?? '')) . '&status=eq.pending', 'PATCH', ['status' => 'withdrawn']) : [];
    return $rows
        ? ['ok' => true, 'message' => 'Your request was withdrawn. Your profile stays as it is.']
        : ['ok' => false, 'message' => friendly_error('That request was already handled or withdrawn.')];
}

/** Change requests with that status, with the account's current details (Approvals). */
function get_profile_requests_admin(string $status = 'pending'): array
{
    return supabase_user_request('profile_change_requests',
        'select=*,profiles(id,' . profile_name_columns() . ',role,account_type,department_id,' . profile_org_columns() . ',year_level,course,student_type,irregular_year_levels,school_ids(school_id,verified_at))'
        . '&status=eq.' . urlencode($status) . '&order=' . ($status === 'pending' ? 'created_at.asc' : 'reviewed_at.desc') . '&limit=300');
}

/** The super admin approves (the changes are saved to the profile) or rejects a request. */
function review_profile_request(string $requestId, bool $approve, string $note = ''): array
{
    if (!is_super_admin_user()) {
        return ['ok' => false, 'message' => 'Only a Super Admin can approve or reject profile changes.'];
    }
    if (!is_uuid($requestId)) return ['ok' => false, 'message' => 'Unknown request.'];
    $note = mb_substr(trim($note), 0, 500);
    $result = supabase_rpc('review_profile_change', ['p_request' => $requestId, 'p_approve' => $approve, 'p_note' => $note !== '' ? $note : null], true);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => friendly_error('Could not save your decision. Please try again.')];
    }
    return ['ok' => true, 'message' => $approve ? 'Approved — the changes are saved to their profile.' : 'Rejected — their profile stays as it was.'];
}

/**
 * A Super Admin's note on something still waiting for approval (without
 * deciding yet) — 'event' (events.approval_note) or 'profile'
 * (note_profile_change()). The person who sent it sees the note; '' removes it.
 */
function save_approval_note(string $kind, string $id, string $note): array
{
    if (!is_super_admin_user()) return ['ok' => false, 'message' => 'Only a Super Admin can leave notes.'];
    if (!is_uuid($id)) return ['ok' => false, 'message' => 'Unknown request.'];
    $note = mb_substr(trim($note), 0, 500);
    if ($kind === 'event') {
        $rows = supabase_user_request('events', 'id=eq.' . urlencode($id) . '&approval_status=eq.pending', 'PATCH', ['approval_note' => $note !== '' ? $note : null]);
        $ok = !empty($rows);
        if (!$ok && supabase_last_error() === '') return ['ok' => false, 'message' => 'That event isn\'t waiting for approval anymore.'];
    } else {
        $result = supabase_rpc('note_profile_change', ['p_request' => $id, 'p_note' => $note], true);
        $ok = $result['ok'];
        if (!$ok && str_contains(supabase_last_error(), 'note_profile_change')) {
            return ['ok' => false, 'message' => 'Notes on profile edits need the latest database update first (20261018120000_approval_notes.sql).'];
        }
    }
    if (!$ok) return ['ok' => false, 'message' => friendly_error('Could not save the note. Please try again.')];
    return ['ok' => true, 'message' => $note !== '' ? 'Note saved — they\'ll see it with their request.' : 'Note removed.'];
}

/** Protected Super Admin accounts (profiles.is_super_admin — Arian Abergas): can't be demoted or deleted from the site. */
function protected_account_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        // As the logged-in Super Admin: profiles are private (20261105120000_private_profiles.sql).
        $ids = array_column(supabase_user_request('profiles', 'select=id&is_super_admin=is.true'), 'id');
        $GLOBALS['supabase_last_error'] = '';
    }
    return $ids;
}

/**
 * How many events and profile edits wait for the super admin — the count on
 * the sidebar's Approvals link (admins). Kept for a minute in the session so
 * pages don't ask the database every time; 0 before the database update.
 */
function approvals_waiting_count(bool $fresh = false): int
{
    return array_sum(approvals_waiting_counts($fresh));
}

/**
 * What's waiting, per list: ['events' => n, 'profiles' => n] (the sidebar's
 * Approvals ▾ dropdown shows each). Cached for a minute per session.
 */
function approvals_waiting_counts(bool $fresh = false): array
{
    // Super Admins: everything waiting. Admins: their own organization's events and their own edits (RLS).
    if (!is_staff_user()) return ['events' => 0, 'profiles' => 0];
    $cache = $_SESSION['approvals_waiting'] ?? null;
    if (!$fresh && is_array($cache) && isset($cache['events'], $cache['profiles']) && $cache['at'] > time() - 60) {
        return ['events' => $cache['events'], 'profiles' => $cache['profiles']];
    }
    $counts = [
        'events' => count(supabase_user_request('events', 'select=id&approval_status=eq.pending&status=eq.draft&limit=1000')),
        'profiles' => count(supabase_user_request('profile_change_requests', 'select=id&status=eq.pending&limit=1000')),
    ];
    $GLOBALS['supabase_last_error'] = '';
    $_SESSION['approvals_waiting'] = $counts + ['at' => time()];
    return $counts;
}

/** Every account with its details, for the admin Users page. */
function get_all_profiles_admin(): array
{
    return supabase_user_request_compat('profiles',
        'select=id,full_name,role,account_type,year_level,course,student_type,irregular_year_levels,created_at,' . profile_org_columns() . ',department_id,'
        . 'organizations!profiles_organization_id_fkey(name),departments(name,code),school_ids(school_id,verified_at)&order=role.asc,full_name.asc',
        'select=id,full_name,role,year_level,course,created_at,organization_id,organizations!profiles_organization_id_fkey(name)&order=role.asc,full_name.asc');
}

/**
 * Change someone's role: 'user' (student / faculty), 'moderator' (runs
 * their organization's events — they need an organization) or 'admin'.
 */
function set_user_role_admin(string $userId, string $role): array
{
    if (!isset(ROLES[$role])) {
        return ['ok' => false, 'message' => 'Invalid role.'];
    }
    if ($userId === (current_user()['id'] ?? '')) {
        return ['ok' => false, 'message' => "You can't change your own role."];
    }
    if ($role === 'moderator') {
        $target = supabase_user_request('profiles', 'select=organization_id&id=eq.' . urlencode($userId));
        if (empty($target[0]['organization_id'])) {
            return ['ok' => false, 'message' => 'An Admin runs their organization\'s events, so this account needs an organization first.'];
        }
    }
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($userId), 'PATCH', ['role' => $role]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not change the role.')];
    }
    $as = ['admin' => 'a Super Admin', 'moderator' => 'an Admin', 'user' => 'a regular ' . strtolower(role_display('user', $rows[0]['account_type'] ?? null)) . ' account'][$role];
    return ['ok' => true, 'message' => $rows[0]['full_name'] . ' is now ' . $as . '.'];
}

/** One account with its details and School ID (Super Admin: Users › Edit), or null. */
function get_profile_admin(string $userId): ?array
{
    if (!is_uuid($userId)) return null;
    $rows = supabase_user_request('profiles', 'select=id,' . profile_name_columns() . ',role,account_type,department_id,' . profile_org_columns() . ',year_level,course,student_type,irregular_year_levels,school_ids(school_id,verified_at)&id=eq.' . urlencode($userId));
    if (empty($rows[0])) return null;
    $row = $rows[0];
    $row['school_id'] = $row['school_ids']['school_id'] ?? null;
    $row['school_id_verified'] = !empty($row['school_ids']['verified_at']);
    unset($row['school_ids']);
    return $row;
}

/**
 * Super Admin: save someone's details straight away (no approval needed) —
 * $fields from validate_account_fields() plus full_name; students' School ID
 * is saved (and verified or not) when it or its verified mark changed.
 */
function update_profile_admin(array $account, array $fields, ?string $schoolId, bool $verified): array
{
    if (!is_super_admin_user()) return ['ok' => false, 'message' => 'Only a Super Admin can edit other accounts.'];
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($account['id']), 'PATCH', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save the account. Please try again.')];
    }
    if ($schoolId !== null && $schoolId !== '' && ($schoolId !== ($account['school_id'] ?? null) || $verified !== !empty($account['school_id_verified']))) {
        $result = set_school_id_admin($account['id'], $schoolId, $verified);
        if (!$result['ok']) return ['ok' => false, 'message' => 'The details were saved, but not the School ID: ' . $result['message']];
    }
    if ($account['id'] === (current_user()['id'] ?? '')) reload_session_profile();
    return ['ok' => true, 'message' => $rows[0]['full_name'] . '\'s account was saved.'];
}

/** Super Admin: delete an account — its login and profile (database: delete_account()). */
function delete_account_admin(string $userId): array
{
    if (!is_super_admin_user()) return ['ok' => false, 'message' => 'Only a Super Admin can delete accounts.'];
    if (!is_uuid($userId)) return ['ok' => false, 'message' => 'Unknown account.'];
    if ($userId === (current_user()['id'] ?? '')) return ['ok' => false, 'message' => "You can't delete your own account."];
    $result = supabase_rpc('delete_account', ['p_user' => $userId], true);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => str_contains(supabase_last_error(), 'delete_account')
            ? 'Deleting accounts needs the latest database update first (20261018090000_three_role_levels.sql).'
            : friendly_error('Could not delete the account. Please try again.')];
    }
    return ['ok' => true, 'message' => (is_string($result['data']) && $result['data'] !== '' ? $result['data'] . '\'s account' : 'The account') . ' was deleted.'];
}

/**
 * Accounts with their school IDs that Import & Verify compares the
 * registrar's list against: students of every role — Students, Admins (role
 * 'moderator') and Super Admins (role 'admin') — plus accounts whose type
 * isn't set yet. Faculty are left out (they aren't on a student list).
 */
function get_student_accounts_admin(): array
{
    return supabase_user_request_compat('profiles',
        'select=id,full_name,role,account_type,year_level,course,student_type,irregular_year_levels,'
        . 'organizations!profiles_organization_id_fkey(name),departments(name,code),school_ids(school_id,verified_at)'
        . '&role=in.(user,moderator,admin)&or=(account_type.eq.student,account_type.is.null)&order=full_name.asc&limit=5000',
        'select=id,full_name,role,year_level,course,organizations!profiles_organization_id_fkey(name)&role=in.(user,moderator,admin)&order=full_name.asc&limit=5000');
}

/**
 * Set accounts' year levels to the registrar's list (Import & Verify): $changes
 * is [user id => year level 1–5]. Year level is the student's highest one, so
 * an irregular student's subject year levels above it are dropped. Super
 * Admins only (the database's "Admins can update any profile" rule).
 * Everyone whose year level changed — students and Admins — is told, in the
 * bell and by email (the usual email queue), with how to ask if it's wrong.
 * @param array<string,int> $changes
 * @param array<string,array> $accounts the compared accounts by id (for their irregular year levels)
 */
function set_year_levels_admin(array $changes, array $accounts): array
{
    $updated = []; // user id => new year level
    $failed = [];
    foreach ($changes as $userId => $year) {
        if (!is_uuid((string) $userId) || !isset(YEAR_LEVELS[$year])) continue;
        $fields = ['year_level' => $year];
        $levels = parse_year_levels($accounts[$userId]['irregular_year_levels'] ?? []);
        if ($levels) $fields['irregular_year_levels'] = array_values(array_filter($levels, fn($l) => $l <= $year)) ?: [$year];
        $rows = supabase_user_request('profiles', 'id=eq.' . urlencode((string) $userId), 'PATCH', $fields);
        if ($rows && supabase_last_error() === '') $updated[$userId] = $year;
        else $failed[] = $accounts[$userId]['full_name'] ?? $userId;
    }
    $done = count($updated);
    if (!$done && !$failed) return ['ok' => false, 'message' => 'Nothing to update.'];

    // Tell each person (bell + email).
    $notified = 0;
    if ($updated) {
        $now = gmdate('c');
        $rows = supabase_user_request('notifications', '', 'POST', array_map(fn($uid, $year) => [
            'user_id' => $uid,
            'title' => 'Your year level was updated',
            'message' => 'A Super Admin updated your year level to ' . YEAR_LEVELS[$year] . ' to match the registrar\'s student list. '
                . 'Events open to your year level now follow it. If this isn\'t right, open My Profile › Edit my profile to send a correction, or contact a Super Admin.',
            'status' => 'sent',
            'sent_at' => $now,
        ], array_keys($updated), array_values($updated)));
        $notified = count($rows);
        if ($notified) queue_notification_emails(); // emailed from the next page (assets/js/email-outbox.js)
    }
    $told = $notified ? ' ' . ($notified === 1 ? 'They were' : 'They were all') . ' notified in the site' . (mail_enabled() ? ' and by email' : '') . '.' : '';

    if ($failed) {
        return ['ok' => $done > 0, 'message' => ($done ? $done . ' year level' . ($done === 1 ? '' : 's') . ' updated;' . $told . ' ' : '')
            . 'Couldn\'t update ' . implode(', ', $failed) . '. ' . friendly_error('Please try again.')];
    }
    return ['ok' => true, 'message' => $done . ' year level' . ($done === 1 ? ' was' : 's were') . ' updated to match the list.' . $told];
}

/** Mark accounts' school IDs as verified (matched with the registrar's list). */
function mark_school_ids_verified(array $userIds): array
{
    $ids = array_values(array_unique(array_filter($userIds, 'is_uuid')));
    if (!$ids) return ['ok' => false, 'message' => 'Nothing to verify.'];
    $rows = [];
    foreach (array_chunk($ids, 150) as $chunk) {
        $rows = array_merge($rows, supabase_user_request('school_ids', 'user_id=in.(' . implode(',', $chunk) . ')&verified_at=is.null', 'PATCH', [
            'verified_at' => gmdate('c'),
            'verified_by' => current_user()['id'] ?? null,
        ]));
    }
    if (!$rows && supabase_last_error() !== '') {
        return ['ok' => false, 'message' => friendly_error('Could not mark the accounts as verified.')];
    }
    return ['ok' => true, 'message' => count($rows) . ' ' . (count($rows) === 1 ? 'account was' : 'accounts were') . ' marked verified.'];
}

/** Admin: set / correct / un-verify a student's school ID. */
function set_school_id_admin(string $userId, string $schoolId, bool $verified): array
{
    $schoolId = normalize_school_id($schoolId);
    if (!valid_school_id($schoolId)) {
        return ['ok' => false, 'message' => 'Please enter the School ID in the format ' . SCHOOL_ID_EXAMPLE . ' (2 digits, 4 digits, then 3 digits).'];
    }
    $fields = ['school_id' => $schoolId, 'verified_at' => $verified ? gmdate('c') : null, 'verified_by' => $verified ? (current_user()['id'] ?? null) : null];
    $existing = supabase_user_request('school_ids', 'select=user_id&user_id=eq.' . urlencode($userId));
    $rows = $existing
        ? supabase_user_request('school_ids', 'user_id=eq.' . urlencode($userId), 'PATCH', $fields)
        : supabase_user_request('school_ids', '', 'POST', $fields + ['user_id' => $userId]);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save the School ID.')]
        : ['ok' => true, 'message' => 'School ID saved' . ($verified ? ' and verified.' : '.')];
}

// =============================================================================
// EVENTS (public side)
// =============================================================================

/** Columns used for event cards/lists: category + organization joined in. */
const EVENT_CARD_SELECT = 'select=*,categories(name),organizations!events_organization_id_fkey(id,name,logo_url)';

/**
 * One event by id. Uses the logged-in user's token when there is one, so
 * admins can preview drafts and students can still open completed/cancelled
 * events they registered for.
 */
function get_event(string $eventId): ?array
{
    if (!is_uuid($eventId)) return null;
    $rows = supabase_request('events', EVENT_CARD_SELECT . '&id=eq.' . urlencode($eventId) . '&limit=1', 'GET', null, session_access_token());
    return $rows[0] ?? null;
}

/** Several events by id (card columns), soonest first. */
function get_events_by_ids(array $eventIds): array
{
    $ids = array_values(array_unique(array_filter($eventIds, 'is_uuid')));
    if (!$ids) return [];
    return supabase_request('events', EVENT_CARD_SELECT . '&id=in.(' . implode(',', $ids) . ')&order=start_date.asc,start_time.asc.nullslast', 'GET', null, session_access_token());
}

/** The logged-in person's registration status for each of these events: [event id => 'registered' | 'attended' | ...]. */
function my_registration_statuses(array $eventIds): array
{
    $user = current_user();
    $ids = array_values(array_unique(array_filter($eventIds, 'is_uuid')));
    if (!$user || !$ids) return [];
    $rows = supabase_user_request('event_registrations', 'select=event_id,status&user_id=eq.' . urlencode($user['id']) . '&event_id=in.(' . implode(',', $ids) . ')');
    return array_column($rows, 'status', 'event_id');
}

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
    if ($excludeId && is_uuid($excludeId)) {
        $query .= '&id=neq.' . urlencode($excludeId);
    }
    return supabase_request('events', $query);
}

/**
 * Events for the Event Calendar (events.php): published and completed
 * events that overlap $from..$to ('Y-m-d'; '' = no bound), in date order.
 * $filters: q (keyword: title, description, venue, or an organization /
 * category whose name matches), category, organization,
 * audience ('students'|'faculty'), venue_type.
 * "For students" also lists events for students & faculty (same for faculty).
 * $statuses: the calendar's published + completed events; the assistant
 * also asks for cancelled ones.
 */
function find_events(array $filters, string $from = '', string $to = '', int $limit = 500, array $statuses = ['published', 'completed']): array
{
    $parts = [EVENT_CARD_SELECT, 'status=in.(' . implode(',', $statuses) . ')', 'order=start_date.asc,start_time.asc.nullslast,title.asc', 'limit=' . $limit];
    if ($to !== '') $parts[] = 'start_date=lte.' . $to;
    if ($from !== '') $parts[] = 'end_date=gte.' . $from;

    if (is_uuid($filters['category'] ?? null)) $parts[] = 'category_id=eq.' . $filters['category'];
    if (is_uuid($filters['organization'] ?? null)) $parts[] = 'organization_id=eq.' . $filters['organization'];
    $audience = (string) ($filters['audience'] ?? '');
    if (isset(AUDIENCE_GROUPS[$audience]) && audiences_ready()) {
        // events.audiences, or the older events.audience on events saved before it.
        $old = $audience === 'outsiders' ? '' : ',and(audiences.is.null,audience.in.(' . $audience . ',all))';
        $parts[] = 'and=(or(audiences.cs.%7B' . $audience . '%7D' . $old . '))';
    } elseif (in_array($audience, ['students', 'faculty'], true)) {
        $parts[] = 'audience=in.(' . $audience . ',all)';
    }
    if (isset(VENUE_TYPES[$filters['venue_type'] ?? ''])) $parts[] = 'venue_type=eq.' . $filters['venue_type'];

    $term = trim(preg_replace('/[^\p{L}\p{N}\s\-\'&]/u', ' ', (string) ($filters['q'] ?? '')));
    $term = preg_replace('/\s+/', ' ', $term);
    if ($term !== '') {
        $like = rawurlencode('"*' . $term . '*"');
        $or = ["title.ilike.$like", "description.ilike.$like", "venue.ilike.$like"];
        $orgIds = array_column(supabase_request('organizations', 'select=id&name=ilike.' . rawurlencode('*' . $term . '*')), 'id');
        if ($orgIds) $or[] = 'organization_id.in.(' . implode(',', $orgIds) . ')';
        $catIds = array_column(supabase_request('categories', 'select=id&name=ilike.' . rawurlencode('*' . $term . '*')), 'id');
        if ($catIds) $or[] = 'category_id.in.(' . implode(',', $catIds) . ')';
        $parts[] = 'or=(' . implode(',', $or) . ')';
    }
    return supabase_request('events', implode('&', $parts));
}

/** Every date ('Y-m-d') from an event's start date to its end date (capped at 62 days). */
function event_dates(array $ev): array
{
    $start = $ev['start_date'] ?? null;
    if (!$start) return [];
    $end = max($start, $ev['end_date'] ?? $start);
    $dates = [];
    for ($d = new DateTimeImmutable($start); $d->format('Y-m-d') <= $end && count($dates) < 62; $d = $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }
    return $dates;
}

/** Programs (segments) of one event, in running order — the event details Schedule. */
function get_event_programs(string $eventId): array
{
    if (!is_uuid($eventId)) return [];
    return supabase_request('event_programs', 'select=*&event_id=eq.' . urlencode($eventId) . '&order=sort_order.asc,created_at.asc', 'GET', null, session_access_token());
}

/**
 * Registration totals per event: ['<event uuid>' => 12, ...]. Uses the
 * event_registration_counts() database function, which returns counts only
 * (users can't read other people's registrations because of RLS).
 */
function get_registration_counts(): array
{
    static $counts = null; // one request per page load
    if ($counts === null) {
        $counts = [];
        $result = supabase_rpc('event_registration_counts');
        foreach ((is_array($result['data']) && $result['ok']) ? $result['data'] : [] as $row) {
            $counts[$row['event_id']] = (int) $row['going'];
        }
    }
    return $counts;
}

function get_registration_count(string $eventId): int
{
    return get_registration_counts()[$eventId] ?? 0;
}

/** Upcoming published events with the most registrations ("Trending"); ties keep date order. */
function get_trending_events(int $limit = 3): array
{
    $events = supabase_request('events', EVENT_CARD_SELECT
        . '&status=eq.published&end_date=gte.' . date('Y-m-d') . '&order=start_date.asc&limit=200');
    $counts = get_registration_counts();
    foreach ($events as &$ev) {
        $ev['going'] = $counts[$ev['id']] ?? 0;
    }
    unset($ev);
    usort($events, fn($a, $b) => $b['going'] <=> $a['going']);
    return array_slice($events, 0, $limit);
}

/**
 * Published and completed events for the dashboard's small calendar: a dot
 * on each day, and clicking a day lists all of that day's events.
 */
function get_calendar_events(): array
{
    return array_map(
        fn($e) => [
            'id' => $e['id'],
            'title' => $e['title'],
            'start' => $e['start_date'],
            'end' => $e['end_date'],
            'time' => format_event_time($e['start_time'] ?? null),
            'place' => event_place($e),
            'status' => $e['status'],
        ],
        supabase_request('events', 'select=id,title,start_date,end_date,start_time,venue,is_online,status&status=in.(published,completed)&order=start_date.asc,start_time.asc.nullslast&limit=1000')
    );
}

// =============================================================================
// REGISTRATION (event_registrations)
// =============================================================================

/**
 * The logged-in user's registrations (not cancelled), newest first, with the
 * event joined in. $limit 0 = all.
 */
function get_my_registrations(int $limit = 5): array
{
    $user = current_user();
    if (!$user) return [];
    $rows = supabase_user_request(
        'event_registrations',
        'select=id,status,registered_at,events(' . substr(EVENT_CARD_SELECT, 7) . ')'
            . '&user_id=eq.' . urlencode($user['id']) . '&status=neq.cancelled&order=registered_at.desc'
            . ($limit > 0 ? '&limit=' . $limit : '')
    );
    return array_values(array_filter($rows, fn($r) => !empty($r['events'])));
}

/** The logged-in user's registration row for one event (any status), or null. */
function get_my_registration(string $eventId): ?array
{
    $user = current_user();
    if (!$user || !is_uuid($eventId)) return null;
    $rows = supabase_user_request('event_registrations', 'select=id,status,registered_at&event_id=eq.' . urlencode($eventId) . '&user_id=eq.' . urlencode($user['id']));
    return $rows[0] ?? null;
}

/**
 * Why this user may NOT register for this event because of who it's for
 * (audience, year levels), or '' if they may. Mirrors the database's
 * check_event_registration(): admins / the event's moderators are exempt;
 * irregular students count every year level they have subjects in.
 */
function registration_restriction(array $ev, ?array $user): string
{
    if (!$user || can_manage_event($ev, $user)) return '';
    $type = $user['account_type'] ?? null;
    $groups = event_audiences($ev);
    if (($type === 'student' && !in_array('students', $groups, true)) || ($type === 'faculty' && !in_array('faculty', $groups, true))) {
        return 'This event is for ' . event_audience_label($ev) . ' only.';
    }

    $levels = parse_year_levels($ev['year_levels'] ?? []);
    if ($levels && $type !== 'faculty') {
        if (empty($user['year_level'])) return 'This event is only for some year levels. Add your year level in My Profile first.';
        $mine = ($user['student_type'] ?? '') === 'irregular' && !empty($user['irregular_year_levels'])
            ? parse_year_levels(array_merge($user['irregular_year_levels'], [$user['year_level']]))
            : [(int) $user['year_level']];
        if (!array_intersect($mine, $levels)) {
            return 'This event is only open to ' . year_levels_label($levels) . ' students.';
        }
    }
    return '';
}

/**
 * What the Register button should do for this user and event — mirrors the
 * rules the database enforces in check_event_registration().
 * @return array{state:string, can_register:bool, can_cancel:bool, note:string}
 */
function registration_state(array $ev, ?array $myReg, int $going, ?array $user = null): array
{
    $today = date('Y-m-d');
    $ended = event_has_ended($ev);
    $mine = $myReg['status'] ?? null;
    $state = fn(string $s, bool $reg, bool $cancel, string $note) => ['state' => $s, 'can_register' => $reg, 'can_cancel' => $cancel, 'note' => $note];

    if ($mine === 'attended') return $state('attended', false, false, 'You attended this event.');
    if (($ev['status'] ?? '') === 'cancelled') return $state('closed', false, false, 'This event was cancelled.');
    // Over: nothing to register for or cancel any more.
    if ($ended) return $state('ended', false, false, $mine === 'registered' ? 'This event has ended. You were registered.' : 'This event has ended, so registration is closed.');
    if ($mine === 'registered') return $state('registered', false, true, "You're registered for this event.");
    if (($ev['status'] ?? '') !== 'published') return $state('closed', false, false, "This event isn't open for registration yet.");
    if (empty($ev['enable_registration'])) return $state('closed', false, false, 'No registration needed — just show up!');
    if (!empty($ev['registration_deadline']) && $today > $ev['registration_deadline']) {
        return $state('closed', false, false, 'Registration closed on ' . format_event_date($ev['registration_deadline']) . '.');
    }
    $restriction = registration_restriction($ev, $user ?? current_user());
    if ($restriction !== '') return $state('restricted', false, false, $restriction);
    $limit = (int) ($ev['registration_limit'] ?? 0);
    if ($limit > 0 && $going >= $limit) return $state('full', false, false, 'This event is full.');

    $notes = [];
    if ($limit > 0) $notes[] = ($limit - $going) . ' of ' . $limit . ' slots left';
    if (!empty($ev['registration_deadline'])) $notes[] = 'register by ' . format_event_date($ev['registration_deadline']);
    return $state('open', true, false, $notes ? ucfirst(implode(' · ', $notes)) . '.' : '');
}

/**
 * Whether an event is over: its last day has passed, or it's that day and
 * its end time has passed (Philippine time), or it's marked Completed.
 * An event without an end time lasts until the end of its last day.
 */
function event_has_ended(array $ev): bool
{
    if (($ev['status'] ?? '') === 'completed') return true;
    $day = (string) (($ev['end_date'] ?? null) ?: ($ev['start_date'] ?? ''));
    if ($day === '') return false;
    $time = substr((string) (($ev['end_time'] ?? null) ?: '23:59:59'), 0, 8);
    $tz = new DateTimeZone('Asia/Manila');
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day . ' ' . (strlen($time) === 5 ? $time . ':00' : $time), $tz);
    return $end !== false && $end < new DateTimeImmutable('now', $tz);
}

/**
 * Register the logged-in user (or re-activate a cancelled registration).
 * The database checks every rule; its reason is passed back on failure.
 * The database adds a "Registered" notification (notify_registration()),
 * which is emailed to them right away (email_registration_notice()).
 */
function register_for_event(string $eventId): array
{
    $user = current_user();
    if (!$user) return ['ok' => false, 'message' => 'Please log in to register for this event.'];
    $event = get_event($eventId);
    if ($event && event_has_ended($event)) {
        return ['ok' => false, 'message' => 'This event has ended, so registration is closed.'];
    }

    $existing = get_my_registration($eventId);
    if ($existing && in_array($existing['status'], ['registered', 'attended'], true)) {
        return ['ok' => false, 'message' => "You're already registered for this event."];
    }
    $rows = $existing
        ? supabase_user_request('event_registrations', 'id=eq.' . urlencode($existing['id']), 'PATCH', ['status' => 'registered'])
        : supabase_user_request('event_registrations', '', 'POST', ['event_id' => $eventId, 'user_id' => $user['id'], 'status' => 'registered']);

    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Something went wrong registering you. Please try again.')];
    }
    $emailed = email_registration_notice($eventId, $event);
    return ['ok' => true, 'message' => "You're registered!" . ($emailed ? ' A confirmation was sent to ' . $user['email'] . '.' : '') . " We'll notify you about any updates."];
}

/**
 * Email the "Registered" notice the database just added to the logged-in
 * person themselves, and take it out of the email queue (if sending fails,
 * the queue tries again later). False when nothing was sent.
 */
function email_registration_notice(string $eventId, ?array $event): bool
{
    $user = current_user();
    if (!$user || !mail_enabled() || !account_may_sign_in($user['email'] ?? '', $user['role'] ?? 'user')) return false;
    $rows = supabase_user_request('notifications', 'select=id,event_id,title,message&event_id=eq.' . urlencode($eventId)
        . '&user_id=eq.' . urlencode($user['id']) . '&title=like.Registered*&email_pending=is.true&order=created_at.desc&limit=1');
    $notice = $rows[0] ?? null;
    if (!$notice) return false; // before the database update (20261106120000_registration_notices.sql)
    $mail = notification_email_content($notice, $event);
    $sent = smtp_send([(string) $user['email']], $mail['subject'], $mail['text'], $mail['html']);
    if (!$sent['ok']) return false;
    supabase_rpc('my_notification_emailed', ['p_id' => $notice['id']], true);
    return true;
}

/** Cancel the logged-in user's registration (frees the seat). */
function cancel_registration(string $eventId): array
{
    $existing = get_my_registration($eventId);
    if (!$existing || $existing['status'] !== 'registered') {
        return ['ok' => false, 'message' => "You aren't registered for this event."];
    }
    $rows = supabase_user_request('event_registrations', 'id=eq.' . urlencode($existing['id']), 'PATCH', ['status' => 'cancelled']);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not cancel your registration. Please try again.')];
    }
    return ['ok' => true, 'message' => 'Your registration was cancelled.'];
}

/** Whether the logged-in user is registered (registered or attended). */
function is_registered(string $eventId): bool
{
    return in_array(get_my_registration($eventId)['status'] ?? null, ['registered', 'attended'], true);
}

// =============================================================================
// SITE STATS & ACTIVE USERS
// =============================================================================

/**
 * Live landing-page counters from the site_stats() database function.
 * @return array<int, array<string, string>> each row has key, label, value; [] if Supabase is unreachable
 */
function get_site_stats(): array
{
    $result = supabase_rpc('site_stats');
    $row = ($result['ok'] && is_array($result['data'])) ? ($result['data'][0] ?? null) : null;
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
 * Active Users heartbeat for the logged-in user (api/presence.php, called by
 * assets/js/presence.js): 'ping' every ~30s while a page is open, 'leave' on
 * logout / closing the tab. Active = pinged in the last 2 minutes.
 */
function presence_ping(): void
{
    if (current_user()) supabase_rpc('touch_presence', [], true);
}

function presence_clear(): void
{
    if (!empty($_SESSION['user'])) supabase_rpc('clear_presence', [], true);
}

/** <script> tags for the Active Users heartbeat — output only for logged-in users. */
function presence_script_tag(): string
{
    if (!current_user()) return '';
    return '<script>window.PRESENCE_URL = ' . json_encode(BASE_URL . '/api/presence.php') . ';</script>' . "\n"
        . '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/presence.js') . '"></script>'
        . email_outbox_script_tag(); // notifications waiting to be emailed (staff)
}

// =============================================================================
// NOTIFICATIONS
// =============================================================================

/** The logged-in user's latest notifications (RLS only returns their own). */
function get_my_notifications(int $limit = 10): array
{
    $user = current_user();
    if (!$user) return [];
    return supabase_user_request('notifications', 'select=id,event_id,title,message,status,created_at&user_id=eq.' . urlencode($user['id']) . '&order=created_at.desc&limit=' . $limit);
}

function unread_count(array $notifications): int
{
    return count(array_filter($notifications, fn($n) => ($n['status'] ?? '') !== 'read'));
}

/** Mark all of the logged-in user's notifications as read (opening the bell). */
function mark_my_notifications_read(): bool
{
    $user = current_user();
    if (!$user) return false;
    supabase_user_request('notifications', 'user_id=eq.' . urlencode($user['id']) . '&status=neq.read', 'PATCH', ['status' => 'read']);
    return supabase_last_error() === '';
}

/**
 * Send a notification about an event (the event's admins only — the
 * database checks), in the site and by email. $audience:
 *   'open'         everyone the event is open to (audiences, year levels)
 *   'open_others'  everyone it's open to who isn't required to attend
 *   'required'     the people required to attend
 *   'registered'   the people registered for it, and those required to attend
 * @return int how many people it was sent to (-1 on failure)
 */
function notify_event(string $eventId, string $audience, string $title, string $message): int
{
    $result = supabase_rpc('notify_event', [
        'p_event_id' => $eventId,
        'p_audience' => in_array($audience, ['open', 'open_others', 'required', 'registered'], true) ? $audience : 'open',
        'p_title' => $title,
        'p_message' => $message,
    ], true);
    if ($result['ok'] && (int) $result['data'] > 0) {
        queue_notification_emails();
    }
    return $result['ok'] ? (int) $result['data'] : -1;
}

// ---- Required attendance ----------------------------------------------------
/** events.required_for: who must attend (they must also be people the event is open to). */
const REQUIRED_FOR = [
    'everyone' => 'All eligible attendees',
    'members' => 'Members of the organizing organization(s) only',
    'department' => 'Everyone in the organizers\' department(s)',
];

/** "Required for members of the organizing organization(s) only" ('' when not required). */
function required_for_label(array $ev): string
{
    $key = $ev['required_for'] ?? null;
    return isset(REQUIRED_FOR[$key]) ? 'Required for ' . lcfirst(REQUIRED_FOR[$key]) : '';
}

/**
 * Upcoming published events the logged-in person is required to attend
 * (the database's my_required_events()). [] when logged out or before the
 * database update.
 */
function my_required_event_ids(): array
{
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    if (!current_user()) return $ids;
    // Before the database update the function isn't there: asked again every 5 minutes, not on every page.
    if (($_SESSION['required_events_missing'] ?? 0) > time() - 300) return $ids;
    $lastError = $GLOBALS['supabase_last_error'] ?? '';
    $result = supabase_rpc('my_required_events', [], true);
    if ($result['ok'] && is_array($result['data'])) {
        $ids = array_values(array_filter(array_column($result['data'], 'event_id'), 'is_string'));
        unset($_SESSION['required_events_missing']);
    } elseif (str_contains(supabase_last_error(), 'Could not find the function')) {
        $_SESSION['required_events_missing'] = time();
    }
    $GLOBALS['supabase_last_error'] = $lastError;
    return $ids;
}

function is_required_for_me(?string $eventId): bool
{
    return $eventId !== null && in_array($eventId, my_required_event_ids(), true);
}

/**
 * Tell people about an event that has just been published: the people
 * required to attend always (bell + email), and — when the organizer picked
 * "Send Notification" — everyone else it's open to.
 * @return int how many people were notified
 */
function announce_published_event(array $ev, bool $toEveryoneOpen): int
{
    $where = event_when($ev) . ' · ' . event_place($ev);
    $required = !empty($ev['required_for']);
    $sent = 0;
    if ($required) {
        $sent += max(0, notify_event($ev['id'], 'required', 'Required: ' . $ev['title'], 'Your attendance is required. ' . $where));
    }
    if ($toEveryoneOpen) {
        $sent += max(0, notify_event($ev['id'], $required ? 'open_others' : 'open', 'New event: ' . $ev['title'], $where));
    }
    return $sent;
}

// =============================================================================
// EMAIL (notifications by email — config/mail.local.php)
// =============================================================================
// Every new notification is also emailed. The site sends them itself over
// SMTP (e.g. a Gmail account with an App Password), in the background: the
// page that created them starts assets/js/email-outbox.js, which asks
// api/notification-emails.php to send the next batch until none are left.
// Addresses come from the database's pending_notification_emails() (staff
// only) and are put in Bcc, so recipients never see each other. An email
// that fails is tried again 10 minutes, 2 hours and a day later, from the
// pages Admins open (the outbox also runs every 10 minutes for them).

/**
 * Email settings from config/mail.local.php (a copy of mail.local.example.php),
 * or environment variables. Two ways to send:
 *   - Brevo's email API (BREVO_API_KEY) — over HTTPS, so it works on hosts that
 *     block the email ports (Render's free plan blocks 25 / 465 / 587);
 *   - SMTP (e.g. Gmail with an App Password) — used when there's no Brevo key.
 */
function mail_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/mail.local.php';
        $local = is_file($file) ? (array) (include $file) : [];
        $get = fn(string $key, string $env, $default = '') => getenv($env) !== false && getenv($env) !== '' ? getenv($env) : ($local[$key] ?? $default);
        $config = [
            'brevo_api_key' => trim((string) $get('brevo_api_key', 'BREVO_API_KEY')),
            'host' => trim((string) $get('host', 'SMTP_HOST', 'smtp.gmail.com')),
            'port' => (int) $get('port', 'SMTP_PORT', 465),
            'username' => trim((string) $get('username', 'SMTP_USERNAME')),
            'password' => str_replace(' ', '', (string) $get('password', 'SMTP_PASSWORD')), // Gmail shows App Passwords in groups of four
            'from_email' => trim((string) $get('from_email', 'SMTP_FROM_EMAIL')),
            'from_name' => trim((string) $get('from_name', 'SMTP_FROM_NAME', 'Campus Event Hub')),
        ];
        if ($config['from_email'] === '') $config['from_email'] = $config['username'];
        if (stripos($config['brevo_api_key'], 'paste') !== false) $config['brevo_api_key'] = ''; // the example's placeholder
    }
    return $config;
}

/** Which way email goes out: 'brevo', 'smtp', or '' (not set up). */
function mail_method(): string
{
    $c = mail_config();
    if (filter_var($c['from_email'], FILTER_VALIDATE_EMAIL) === false) return '';
    if ($c['brevo_api_key'] !== '') return 'brevo';
    if ($c['host'] !== '' && $c['username'] !== '' && $c['password'] !== '' && stripos($c['password'], 'paste') === false) return 'smtp';
    return '';
}

/** True once real email settings are filled in (the example file's placeholders don't count). */
function mail_enabled(): bool
{
    return mail_method() !== '';
}

/**
 * Send one email through Brevo's API (https://api.brevo.com/v3/smtp/email).
 * Several recipients: one request with a "version" per person, so each gets
 * their own copy addressed only to them (like Bcc: nobody sees the others).
 * The sender address must be verified in Brevo (Senders).
 * @param string[] $recipients valid, unique addresses
 * @return array{ok:bool, error?:string}
 */
function brevo_send(array $recipients, string $subject, string $text, string $html): array
{
    $c = mail_config();
    foreach (array_chunk($recipients, 500) as $chunk) {
        $payload = [
            'sender' => ['name' => $c['from_name'], 'email' => $c['from_email']],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
            'headers' => ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All'],
        ];
        if (count($chunk) === 1) {
            $payload['to'] = [['email' => $chunk[0]]];
        } else {
            $payload['messageVersions'] = array_map(fn($e) => ['to' => [['email' => $e]]], $chunk);
        }
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['api-key: ' . $c['brevo_api_key'], 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA); // as in supabase_http()
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if ($response === false || $status < 200 || $status >= 300) {
            $answer = json_decode((string) $response, true);
            $error = $curlError !== '' ? $curlError : 'Brevo: ' . ($answer['message'] ?? ('HTTP ' . $status));
            if ($status === 401) $error .= ' — check BREVO_API_KEY, and turn off Security › Authorised IPs in Brevo.';
            error_log('[brevo_send] ' . $error);
            return ['ok' => false, 'error' => $error];
        }
    }
    return ['ok' => true];
}

/** A header value with non-ASCII text encoded (subjects, names). */
function mail_header_text(string $text): string
{
    return preg_match('/[^\x20-\x7E]/', $text) ? mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n") : $text;
}

/**
 * Send one email (plain text + HTML) to $recipients — through Brevo when a
 * Brevo key is set (brevo_send()), else over SMTP: one recipient goes in To:;
 * several go in Bcc (To: shows the site's address).
 * @param string[] $recipients
 * @return array{ok:bool, error?:string}
 */
function smtp_send(array $recipients, string $subject, string $text, string $html): array
{
    if (!mail_enabled()) return ['ok' => false, 'error' => 'Email isn\'t set up (config/mail.local.php).'];
    $c = mail_config();
    $recipients = array_values(array_unique(array_filter(array_map('trim', $recipients), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
    if (!$recipients) return ['ok' => false, 'error' => 'No valid recipients.'];
    if (mail_method() === 'brevo') return brevo_send($recipients, $subject, $text, $html);

    $from = mail_header_text($c['from_name']) . ' <' . $c['from_email'] . '>';
    $boundary = 'ceh_' . bin2hex(random_bytes(8));
    $host = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $from,
        'To: ' . (count($recipients) === 1 ? '<' . $recipients[0] . '>' : $from),
        'Subject: ' . mail_header_text($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
    ];
    $part = fn(string $type, string $body) => '--' . $boundary . "\r\n"
        . 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    $message = implode("\r\n", $headers) . "\r\n\r\n"
        . $part('text/plain', $text) . $part('text/html', $html) . '--' . $boundary . "--\r\n";

    $ssl = $c['port'] === 465;
    $offset = 0;
    $ch = curl_init(($ssl ? 'smtps://' : 'smtp://') . $c['host'] . ':' . $c['port']);
    curl_setopt_array($ch, [
        CURLOPT_USERNAME => $c['username'],
        CURLOPT_PASSWORD => $c['password'],
        CURLOPT_MAIL_FROM => '<' . $c['from_email'] . '>',
        CURLOPT_MAIL_RCPT => array_map(fn($e) => '<' . $e . '>', $recipients),
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILESIZE => strlen($message),
        CURLOPT_READFUNCTION => function ($ch, $fd, int $length) use ($message, &$offset): string {
            $chunk = (string) substr($message, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        },
        CURLOPT_USE_SSL => CURLUSESSL_ALL, // port 587: STARTTLS (465 is SSL from the start)
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    // One refused address doesn't stop the rest (PHP 8.2 spells it ALLLOWFAILS, like old libcurl).
    $allowFails = defined('CURLOPT_MAIL_RCPT_ALLOWFAILS') ? constant('CURLOPT_MAIL_RCPT_ALLOWFAILS')
        : (defined('CURLOPT_MAIL_RCPT_ALLLOWFAILS') ? constant('CURLOPT_MAIL_RCPT_ALLLOWFAILS') : null);
    if ($allowFails !== null) curl_setopt($ch, $allowFails, true);
    $ok = curl_exec($ch) !== false;
    $errno = curl_errno($ch);
    $error = curl_error($ch) ?: ($errno ? curl_strerror($errno) : ''); // some errors leave curl_error() empty
    curl_close($ch);
    if (!$ok) {
        if ($errno === 67) $error = 'Login denied: check the email address and App Password in config/mail.local.php'; // CURLE_LOGIN_DENIED
        error_log('[smtp_send] ' . $error);
        return ['ok' => false, 'error' => $error !== '' ? $error : 'The mail server refused the message.'];
    }
    return ['ok' => true];
}

/**
 * The email for a notification: its title and message, the event's date,
 * place and organizer, and a button to the event page.
 * @return array{subject:string, text:string, html:string}
 */
function notification_email_content(array $n, ?array $event): array
{
    $title = (string) ($n['title'] ?? 'Campus Event Hub');
    $message = (string) ($n['message'] ?? '');
    $required = str_starts_with($title, 'Required') || str_starts_with($title, 'Now required');
    $url = !empty($n['event_id']) ? app_url('event-details.php?id=' . rawurlencode($n['event_id'])) : app_url('index.php');
    // "Register": while the event still takes registrations. It opens the event
    // page at its Register button (registering itself stays a click on the site).
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $canRegister = $event && !empty($n['event_id']) && !str_starts_with($title, 'Registered') // already registered: no button
        && !str_starts_with($title, 'Approved:') && !str_starts_with($title, 'Not approved:') // the author's approval notice
        && !event_has_ended($event)
        && ($event['status'] ?? '') === 'published' && !empty($event['enable_registration'])
        && (string) (($event['end_date'] ?? null) ?: ($event['start_date'] ?? '')) >= $today
        && (empty($event['registration_deadline']) || (string) $event['registration_deadline'] >= $today);
    $registerUrl = $canRegister ? $url . '#register' : null;
    $details = [];
    if ($event) {
        $details['When'] = event_when($event);
        $details['Where'] = event_place($event) . (!empty($event['address']) ? ' — ' . $event['address'] : '');
        if (!empty($event['organizations']['name'])) $details['Organized by'] = $event['organizations']['name'];
        if (($label = required_for_label($event)) !== '') $details['Attendance'] = $label;
    }

    $text = "Hello,\n\n" . $title . "\n" . ($message !== '' ? $message . "\n" : '') . "\n";
    foreach ($details as $label => $value) $text .= $label . ': ' . $value . "\n";
    $text .= ($registerUrl ? "\nRegister: " . $registerUrl : '')
        . "\nOpen the event: " . $url . "\n\n— Campus Event Hub · " . SCHOOL_NAME
        . "\nYou're getting this email because you have a Campus Event Hub account.\n";

    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($details as $label => $value) {
        $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#4d5866;font-size:13px;white-space:nowrap;vertical-align:top">' . $e($label)
            . '</td><td style="padding:4px 0;color:#162b44;font-size:14px">' . $e($value) . '</td></tr>';
    }
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f2ef;font-family:Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ef;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#213e60;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:800;letter-spacing:.3px">'
        . '<span style="color:#e68c3a">CAMPUS</span> EVENT HUB</td></tr>'
        . '<tr><td style="padding:24px">'
        . ($required ? '<div style="display:inline-block;background:#a85a14;color:#ffffff;font-size:11px;font-weight:800;letter-spacing:.6px;padding:4px 10px;border-radius:999px;margin-bottom:12px">REQUIRED ATTENDANCE</div>' : '')
        . '<h1 style="margin:0 0 8px;font-size:20px;color:#162b44">' . $e($title) . '</h1>'
        . ($message !== '' ? '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#162b44">' . $e($message) . '</p>' : '')
        . ($rows !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px">' . $rows . '</table>' : '')
        . ($registerUrl
            ? '<a href="' . $e($registerUrl) . '" style="display:inline-block;background:#e68c3a;color:#162b44;text-decoration:none;font-weight:800;font-size:14px;padding:12px 26px;border-radius:8px;margin:0 8px 8px 0">Register</a>'
              . '<a href="' . $e($url) . '" style="display:inline-block;background:#ffffff;color:#213e60;text-decoration:none;font-weight:700;font-size:14px;padding:10px 20px;border-radius:8px;border:2px solid #213e60;margin:0 0 8px">Open the event</a>'
            : '<a href="' . $e($url) . '" style="display:inline-block;background:#213e60;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px">Open the event</a>')
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #ebe7dd;color:#6f6a5d;font-size:12px;line-height:1.5">'
        . $e(SCHOOL_NAME) . ' · Campus Event Hub<br>You\'re getting this email because you have a Campus Event Hub account.</td></tr>'
        . '</table></td></tr></table></body></html>';

    return ['subject' => $title, 'text' => $text, 'html' => $html];
}

/** Notifications were just created: email them from the next page (assets/js/email-outbox.js). */
function queue_notification_emails(): void
{
    if (!mail_enabled()) return;
    session_start_once();
    $_SESSION['email_outbox'] = time();
}

/**
 * Email the next batch of notifications that are waiting (staff only; the
 * database decides which ones this person may send). Notifications with
 * the same text go out as one email with the recipients in Bcc.
 * @return array{enabled:bool, sent:int, failed:int, more:bool, error:string}
 */
function deliver_notification_emails(int $maxRecipients = 40): array
{
    $out = ['enabled' => mail_enabled(), 'sent' => 0, 'failed' => 0, 'more' => false, 'error' => ''];
    if (!$out['enabled'] || !is_staff_user()) return $out;

    $result = supabase_rpc('pending_notification_emails', ['p_limit' => $maxRecipients + 1], true);
    if (!$result['ok'] || !is_array($result['data'])) {
        $out['error'] = supabase_last_error() ?: 'Could not read the notifications waiting to be emailed.';
        return $out;
    }
    $rows = $result['data'];
    $out['more'] = count($rows) > $maxRecipients;
    $groups = [];
    foreach (array_slice($rows, 0, $maxRecipients) as $r) {
        $groups[($r['event_id'] ?? '') . "\n" . $r['title'] . "\n" . $r['message']][] = $r;
    }
    $events = [];
    foreach ($groups as $group) {
        $eventId = $group[0]['event_id'] ?? null;
        if ($eventId && !array_key_exists($eventId, $events)) $events[$eventId] = get_event_admin($eventId);
        $mail = notification_email_content($group[0], $eventId ? $events[$eventId] : null);
        $idsByEmail = [];
        foreach ($group as $r) $idsByEmail[strtolower((string) $r['email'])][] = $r['id'];
        $sent = smtp_send(array_keys($idsByEmail), $mail['subject'], $mail['text'], $mail['html']);
        supabase_rpc('mark_notification_emails', ['p_ids' => array_merge(...array_values($idsByEmail)), 'p_error' => $sent['ok'] ? null : $sent['error']], true);
        if ($sent['ok']) {
            $out['sent'] += count($idsByEmail);
        } else {
            // The mail server is refusing (down, wrong password, daily limit):
            // stop here; the database schedules the next try for these.
            $out['failed'] += count($idsByEmail);
            $out['error'] = $sent['error'];
            $out['more'] = false;
            break;
        }
    }
    return $out;
}

/**
 * The background sender (staff): on the page opened right after
 * notifications were created, and every 10 minutes while an Admin uses the
 * site (emails that failed are tried again then).
 */
function email_outbox_script_tag(): string
{
    if (!is_staff_user() || !mail_enabled()) return '';
    if (empty($_SESSION['email_outbox']) && ($_SESSION['email_outbox_checked'] ?? 0) > time() - 600) return '';
    return '<script>window.EMAIL_OUTBOX_URL = ' . json_encode(BASE_URL . '/api/notification-emails.php') . ';</script>' . "\n"
        . '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/email-outbox.js') . '" defer></script>';
}

/** Data + script for the notification bell (see includes/topbar.php, assets/js/notifications.js). */
function notifications_bootstrap(array $notifications): string
{
    return '<script>window.USER_NOTIFICATIONS = ' . json_encode(array_values($notifications)) . ';'
        . ' window.NOTIF_READ_URL = ' . json_encode(BASE_URL . '/api/notifications.php') . ';'
        . ' window.EVENT_URL = ' . json_encode(BASE_URL . '/event-details.php?id=') . ';</script>' . "\n"
        . '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/notifications.js') . '" defer></script>';
}

// =============================================================================
// IMAGE UPLOADS (Supabase Storage)
// =============================================================================

/**
 * Upload an image from a form (<input type="file">) to a public Storage
 * bucket as the logged-in admin. Returns its public URL.
 * @param array $file one entry of $_FILES
 * @return array{ok:bool, url?:string, message?:string}  ok:false + no message = no file chosen
 */
function upload_public_image(string $bucket, array $file, string $folder = ''): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'The image could not be uploaded. Please try again.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Images must be 5 MB or smaller.'];
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$mime])) {
        return ['ok' => false, 'message' => 'Please upload a JPG, PNG, WEBP or GIF image.'];
    }

    $path = trim($folder, '/') . ($folder !== '' ? '/' : '') . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
    $result = supabase_call('POST', '/storage/v1/object/' . $bucket . '/' . $path, file_get_contents($file['tmp_name']), session_access_token(), [
        'Content-Type: ' . $mime,
        'x-upsert: false',
    ]);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => 'Upload failed: ' . supabase_last_error()];
    }
    return ['ok' => true, 'url' => rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/' . $bucket . '/' . $path];
}

// =============================================================================
// ADMIN — EVENTS (admin/event-organizer.php)
// =============================================================================
// Writes run AS the logged-in admin: the events RLS policies require
// is_admin() for insert/update/delete, which only an admin's token satisfies.

/**
 * Events (every status), newest first, for the Manage Events table:
 * every event for admins, their organization's events for moderators.
 */
function get_all_events_admin(): array
{
    $user = current_user();
    $scope = ($user['role'] ?? '') === 'admin' ? '' : '&organization_id=eq.' . urlencode((string) ($user['organization_id'] ?? '00000000-0000-0000-0000-000000000000'));
    return supabase_user_request('events', 'select=*,categories(name),organizations!events_organization_id_fkey(name,logo_url),event_programs(count)&order=created_at.desc' . $scope);
}

/** Organizations the logged-in user may create events for: all (admin) or their own (moderator). */
function get_event_organizations_for(array $user): array
{
    $organizations = get_organizations();
    if (($user['role'] ?? '') === 'admin') return $organizations;
    return array_values(array_filter($organizations, fn($o) => $o['id'] === ($user['organization_id'] ?? null)));
}

/**
 * Other events (not cancelled) on the dates of an event being created or
 * edited, and which of them it would clash with — the same rules as the
 * database's trg_events_schedule_rules, checked first for a clear message
 * and for the "other events on these dates" hint on the form.
 * $ev: title, start_date, end_date, start_time, end_time, venue, venue_type
 * @return array{same_dates: array, duplicate: ?array, venue_conflict: ?array}
 */
function find_schedule_conflicts(array $ev, ?string $excludeId = null): array
{
    $out = ['same_dates' => [], 'duplicate' => null, 'venue_conflict' => null];
    $start = (string) ($ev['start_date'] ?? '');
    $end = (string) ($ev['end_date'] ?? '') ?: $start;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return $out;

    $rows = supabase_request('events', 'select=id,title,status,start_date,end_date,start_time,end_time,venue,venue_type,is_online,organizations!events_organization_id_fkey(name)'
        . '&status=neq.cancelled&start_date=lte.' . max($start, $end) . '&end_date=gte.' . min($start, $end) . '&order=start_date.asc,start_time.asc.nullslast&limit=100',
        'GET', null, session_access_token());

    // Start / end as comparable 'Y-m-d H:i:s' strings (no end time = end of the end date).
    $range = function (array $e): array {
        $s = ($e['start_date'] ?? '') . ' ' . (!empty($e['start_time']) ? substr($e['start_time'], 0, 8) : '00:00:00');
        $endDate = $e['end_date'] ?: $e['start_date'];
        $f = !empty($e['end_time']) ? $endDate . ' ' . substr($e['end_time'], 0, 8) : date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
        return [$s, max($s, $f)];
    };
    $online = fn(array $e): bool => !empty($e['is_online']) || ($e['venue_type'] ?? '') === 'online';
    [$newStart, $newEnd] = $range($ev + ['end_date' => $end]);
    $venue = mb_strtolower(trim((string) ($ev['venue'] ?? '')));

    foreach ($rows as $row) {
        if ($excludeId && $row['id'] === $excludeId) continue;
        $out['same_dates'][] = $row;
        if (!$out['duplicate'] && mb_strtolower(trim($row['title'])) === mb_strtolower(trim((string) ($ev['title'] ?? ''))) && $row['start_date'] === $start) {
            $out['duplicate'] = $row;
        }
        if (!$out['venue_conflict'] && $venue !== '' && !$online($ev) && !$online($row)
            && mb_strtolower(trim((string) $row['venue'])) === $venue) {
            [$s, $f] = $range($row);
            if ($s < $newEnd && $newStart < $f) $out['venue_conflict'] = $row;
        }
    }
    return $out;
}

/**
 * Mark ended events 'completed' (the database's complete_ended_events()).
 * Runs at most every 5 minutes for the whole site, with a short timeout so
 * a slow connection never holds up a page. $now: run right away (after an
 * event is saved, so one that's already over shows Completed at once).
 */
function auto_complete_events(bool $now = false): void
{
    $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ceh-complete-events-' . md5(SUPABASE_URL) . '.txt';
    if (!$now && is_file($stamp) && filemtime($stamp) > time() - 300) return;
    @touch($stamp);
    supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/rest/v1/rpc/complete_ended_events', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], new stdClass(), 4);
}

/** Single event by id (any status), for pre-filling the edit form. */
function get_event_admin(string $eventId): ?array
{
    if (!is_uuid($eventId)) return null;
    $rows = supabase_user_request('events', 'select=*,categories(name),organizations!events_organization_id_fkey(name,logo_url,departments(name,code))&id=eq.' . urlencode($eventId) . '&limit=1');
    return $rows[0] ?? null;
}

/**
 * Co-organizers of multi-organization events (event_organizations), as
 * event id => [organization rows]. The main organizer is events.organization_id
 * and isn't repeated here. Pass ids for a few events, or null for every event.
 * Empty until migration 20261015120000_manage_events_update.sql is run.
 */
function get_event_partner_orgs(?array $eventIds = null): array
{
    $ids = array_values(array_filter($eventIds ?? [], 'is_uuid'));
    if ($eventIds !== null && !$ids) return [];
    $rows = supabase_request('event_organizations',
        'select=event_id,organizations(id,name,logo_url)' . ($ids ? '&event_id=in.(' . implode(',', $ids) . ')' : ''),
        'GET', null, session_access_token());
    $map = [];
    foreach ($rows as $row) {
        if (!empty($row['organizations'])) $map[$row['event_id']][] = $row['organizations'];
    }
    foreach ($map as &$orgs) usort($orgs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $map;
}

/** Replace an event's co-organizers. @return bool false if they couldn't be saved */
function set_event_partner_orgs(string $eventId, array $organizationIds): bool
{
    supabase_user_request('event_organizations', 'event_id=eq.' . urlencode($eventId), 'DELETE');
    if (supabase_last_error() !== '') return false;
    if (!$organizationIds) return true;
    $rows = array_map(fn($id) => ['event_id' => $eventId, 'organization_id' => $id], array_values($organizationIds));
    supabase_user_request('event_organizations', '', 'POST', $rows);
    return supabase_last_error() === '';
}

/** "JPSSITE" or "JPSSITE, SIES & ACpES" — an event's main organizer, then its co-organizers. */
function event_organizers_label(array $ev, array $partners = [], string $default = 'Organizer'): string
{
    $names = array_values(array_filter(array_merge([$ev['organizations']['name'] ?? ''], array_column($partners, 'name'))));
    if (!$names) return $default;
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' & ' . $last : $last;
}

/**
 * A save (POST / PATCH) as the logged-in user that still works when the
 * database is missing a newer column (a database update that hasn't been
 * run): those columns are left out and listed in $dropped.
 */
function supabase_user_write_lenient(string $table, string $query, string $method, array $fields, ?array &$dropped = null): array
{
    $dropped = [];
    for ($try = 0; $try < 5; $try++) {
        $rows = supabase_user_request($table, $query, $method, $fields);
        if ($rows) return $rows;
        // PostgREST: "Could not find the 'x' column of 'events' in the schema cache" / Postgres: "column events.x does not exist"
        if (!preg_match("/Could not find the '(\w+)' column|column \w+\.(\w+) does not exist/", supabase_last_error(), $m)) return $rows;
        $column = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        if (!array_key_exists($column, $fields)) return $rows;
        unset($fields[$column]);
        $dropped[] = $column;
    }
    return [];
}

/** @return array{ok:bool, message:string, event?:array, dropped?:string[]} dropped: columns the database doesn't have yet */
function create_event_admin(array $fields): array
{
    $fields['created_by'] = current_user()['id'] ?? null;
    $rows = supabase_user_write_lenient('events', '', 'POST', $fields, $dropped);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not create the event. Please check the fields and try again.')];
    }
    return ['ok' => true, 'message' => 'Event created.', 'event' => $rows[0], 'dropped' => $dropped];
}

/** @return array{ok:bool, message:string, event?:array, dropped?:string[]} */
function update_event_admin(string $eventId, array $fields): array
{
    $rows = supabase_user_write_lenient('events', 'id=eq.' . urlencode($eventId), 'PATCH', $fields, $dropped);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save changes. Please try again.')];
    }
    return ['ok' => true, 'message' => 'Event updated.', 'event' => $rows[0], 'dropped' => $dropped];
}

// ---- Admins' new events need a Super Admin's approval ------------------------
// (migrations 20261017120000_super_admin_approvals.sql, 20261018090000_three_role_levels.sql).
// events.approval_status: null = no approval needed (Super Admins' events, older
// events), 'needed' = an Admin's (moderator role) event not sent yet, 'pending' =
// waiting (still a draft), 'approved', 'rejected' (approval_note says why).
// Publishing one of these events sends it for approval instead — the database
// does that (trg_events_approval).

/** True if this event, published by this user now, would wait for a Super Admin. */
function event_needs_approval(?array $event, array $user): bool
{
    if (($user['role'] ?? '') !== 'moderator' || !approvals_ready()) return false;
    return $event === null || in_array($event['approval_status'] ?? null, ['needed', 'pending', 'rejected'], true);
}

/** Admins' events with that approval status (Approvals). */
function get_event_approvals_admin(string $status = 'pending'): array
{
    return supabase_user_request('events', 'select=id,title,status,start_date,end_date,start_time,end_time,venue,venue_type,is_online,'
        . 'send_notification,created_by,approval_status,approval_note,approval_requested_at,approval_reviewed_by,approval_reviewed_at,categories(name),organizations!events_organization_id_fkey(name)'
        . '&approval_status=eq.' . urlencode($status)
        . ($status === 'pending' ? '&status=eq.draft&order=approval_requested_at.asc' : '&order=approval_reviewed_at.desc') . '&limit=300');
}

/**
 * The super admin approves an event (it's published, and announced if its
 * organizer picked "Send Notification") or rejects it (it stays a draft;
 * its admin can change it and send it again).
 */
function review_event_admin(string $eventId, bool $approve, string $note = ''): array
{
    if (!is_super_admin_user()) {
        return ['ok' => false, 'message' => 'Only a Super Admin can approve or reject events.'];
    }
    $ev = get_event_admin($eventId);
    if (!$ev || ($ev['approval_status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'That event isn\'t waiting for approval anymore.'];
    }
    $note = mb_substr(trim($note), 0, 500);
    $fields = [
        'approval_status' => $approve ? 'approved' : 'rejected',
        'approval_reviewed_by' => current_user()['id'] ?? null,
        'approval_reviewed_at' => gmdate('c'),
    ];
    if ($note !== '') $fields['approval_note'] = $note; // otherwise an earlier note stays
    if ($approve) $fields += ['status' => 'published', 'is_published' => true];
    $rows = supabase_user_request('events', 'id=eq.' . urlencode($eventId) . '&approval_status=eq.pending', 'PATCH', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save your decision. Please try again.')];
    }
    // The event's author was just notified by the database (20261122120000_approval_notices.sql): email it now.
    queue_notification_emails();
    if (!$approve) {
        return ['ok' => true, 'message' => 'Rejected — "' . $ev['title'] . '" stays a draft. Its admin can change it and send it again.'];
    }
    $message = 'Approved — "' . $ev['title'] . '" is published.';
    // Required attendees are always told; everyone else it's open to when "Send Notification" was picked.
    $sent = announce_published_event($ev, !empty($ev['send_notification']));
    if ($sent > 0) $message .= ' ' . $sent . ' ' . ($sent === 1 ? 'person was' : 'people were') . ' notified (in the site and by email).';
    auto_complete_events(true); // already over: Completed right away
    return ['ok' => true, 'message' => $message];
}

/** Delete an event (its registrations, programs and notifications go with it). Admins only. */
function delete_event_admin(string $eventId): array
{
    if (!is_admin_user()) {
        return ['ok' => false, 'message' => 'Only admins can delete events. Cancel it instead.'];
    }
    $rows = supabase_user_request('events', 'id=eq.' . urlencode($eventId), 'DELETE');
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not delete the event — it may already be gone.')];
    }
    return ['ok' => true, 'message' => 'Event "' . $rows[0]['title'] . '" deleted.'];
}

/**
 * Change only an event's status (Cancel / Mark Completed / Reopen as Draft).
 * is_published stays true for everything except drafts, so students who
 * registered can still open completed or cancelled events. Cancelling
 * notifies everyone who registered.
 */
function set_event_status_admin(string $eventId, string $status): array
{
    if (!in_array($status, ['draft', 'published', 'cancelled', 'completed'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }
    $rows = supabase_user_request('events', 'id=eq.' . urlencode($eventId), 'PATCH', [
        'status' => $status,
        'is_published' => $status !== 'draft',
    ]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not update the event status. Please try again.')];
    }

    $ev = $rows[0];
    $message = 'Event "' . $ev['title'] . '" ' . ['draft' => 'moved back to draft', 'published' => 'published', 'cancelled' => 'cancelled', 'completed' => 'marked completed'][$status] . '.';
    if ($status === 'cancelled') {
        $sent = notify_event($ev['id'], 'registered', 'Event cancelled: ' . $ev['title'],
            'Sorry — "' . $ev['title'] . '" (' . event_when($ev) . ') has been cancelled.');
        if ($sent > 0) $message .= ' ' . $sent . ' ' . ($sent === 1 ? 'person was' : 'people were') . ' notified (registered and required attendees).';
    }
    return ['ok' => true, 'message' => $message];
}

/** Categories / organizations for the event form's dropdowns (alphabetical). */
/** Categories A–Z, with "Other" always last. */
function get_categories(): array
{
    $categories = supabase_request('categories', 'select=id,name&order=name.asc');
    usort($categories, fn($a, $b) => is_other_category($a['name']) <=> is_other_category($b['name']));
    return $categories;
}

function get_organizations(): array
{
    // select=* so it works before and after the organization_codes update adds "code".
    return supabase_request('organizations', 'select=*&order=name.asc');
}

// ---- Event programs (the Program Flow) — event form section 6, admin/event-programs.php ---------------

function create_program_admin(string $eventId, array $fields): array
{
    $existing = get_event_programs($eventId);
    $fields['event_id'] = $eventId;
    $fields['sort_order'] = $existing ? max(array_column($existing, 'sort_order')) + 1 : 1;
    $rows = supabase_user_request('event_programs', '', 'POST', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not add the program.')]
        : ['ok' => true, 'message' => 'Program added.'];
}

function update_program_admin(string $programId, array $fields): array
{
    $rows = supabase_user_request('event_programs', 'id=eq.' . urlencode($programId), 'PATCH', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save the program.')]
        : ['ok' => true, 'message' => 'Program saved.'];
}

function delete_program_admin(string $programId): array
{
    $rows = supabase_user_request('event_programs', 'id=eq.' . urlencode($programId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete the program.')]
        : ['ok' => true, 'message' => 'Program deleted.'];
}

/**
 * Save an event's whole program flow from the event form (section 6), in
 * the order given: parts that are kept are updated (only if they changed),
 * new ones added, and the ones no longer in the list deleted.
 * @param array<array{id:string,title:string,speaker:string,description:string}> $rows
 * @return bool false if any change couldn't be saved
 */
function save_event_programs(string $eventId, array $rows): bool
{
    if (!is_uuid($eventId)) return false;
    $existing = array_column(get_event_programs($eventId), null, 'id');
    $ok = true;
    $kept = [];
    foreach (array_values($rows) as $i => $r) {
        $fields = [
            'title' => $r['title'],
            'speaker' => $r['speaker'] !== '' ? $r['speaker'] : null,
            'description' => $r['description'] !== '' ? $r['description'] : null,
            'sort_order' => $i + 1,
        ];
        $old = isset($existing[$r['id'] ?? '']) ? $existing[$r['id']] : null;
        if ($old) {
            $kept[] = $old['id'];
            $same = $old['title'] === $fields['title'] && ($old['speaker'] ?? null) === $fields['speaker']
                && ($old['description'] ?? null) === $fields['description'] && (int) $old['sort_order'] === $fields['sort_order'];
            if (!$same) $ok = !empty(supabase_user_request('event_programs', 'id=eq.' . urlencode($old['id']), 'PATCH', $fields)) && $ok;
        } else {
            $ok = !empty(supabase_user_request('event_programs', '', 'POST', $fields + ['event_id' => $eventId])) && $ok;
        }
    }
    foreach (array_diff(array_keys($existing), $kept) as $id) {
        $ok = !empty(supabase_user_request('event_programs', 'id=eq.' . urlencode($id) . '&event_id=eq.' . urlencode($eventId), 'DELETE')) && $ok;
    }
    return $ok;
}

/** Move a program one place up (-1) or down (+1) in the running order. */
function move_program_admin(string $eventId, string $programId, int $direction): array
{
    $programs = get_event_programs($eventId);
    $ids = array_column($programs, 'id');
    $from = array_search($programId, $ids, true);
    $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);
    if ($from === false || $to < 0 || $to >= count($ids)) {
        return ['ok' => true, 'message' => ''];
    }
    [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
    // Renumber 1..n so older rows with equal sort_order values end up consistent.
    foreach ($ids as $i => $id) {
        $current = $programs[array_search($id, array_column($programs, 'id'), true)]['sort_order'];
        if ((int) $current !== $i + 1) {
            supabase_user_request('event_programs', 'id=eq.' . urlencode($id), 'PATCH', ['sort_order' => $i + 1]);
        }
    }
    return ['ok' => true, 'message' => ''];
}

// ---- Registrations of one event — admin/event-registrations.php ------------

function get_event_registrations_admin(string $eventId): array
{
    if (!is_uuid($eventId)) return [];
    $where = '&event_id=eq.' . urlencode($eventId) . '&order=registered_at.asc,id.asc';
    return supabase_user_request_compat('event_registrations',
        'select=id,status,registered_at,attended_at,user_id,profiles(full_name,account_type,year_level,course,student_type,irregular_year_levels,'
        . 'organizations!profiles_organization_id_fkey(name),school_ids(school_id,verified_at))' . $where,
        'select=id,status,registered_at,attended_at,user_id,profiles(full_name,year_level,course,organizations!profiles_organization_id_fkey(name))' . $where);
}

/** Ids of the people who evaluated an event (their participation is Completed). */
function get_event_evaluator_ids(string $eventId): array
{
    if (!is_uuid($eventId)) return [];
    return array_column(supabase_user_request('event_evaluations', 'select=user_id&event_id=eq.' . urlencode($eventId)), 'user_id');
}

/**
 * Set the status of one or more registrations of an event (Attended /
 * No-show / Reset). People newly marked Attended get a notification asking
 * them to evaluate the event.
 * @param string[] $registrationIds
 */
function set_registration_status_admin(array $event, array $registrationIds, string $status): array
{
    if (!in_array($status, ['registered', 'attended', 'no_show', 'cancelled'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }
    $ids = array_values(array_filter($registrationIds, 'is_uuid'));
    if (!$ids) {
        return ['ok' => false, 'message' => 'Select at least one person first.'];
    }
    $idList = 'id=in.(' . implode(',', $ids) . ')&event_id=eq.' . urlencode($event['id']);

    $before = [];
    if ($status === 'attended') {
        foreach (supabase_user_request('event_registrations', 'select=id,status&' . $idList) as $r) {
            $before[$r['id']] = $r['status'];
        }
    }
    $rows = supabase_user_request('event_registrations', $idList, 'PATCH', ['status' => $status]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not update the registration.')];
    }

    $message = count($rows) === 1 ? 'Registration updated.' : count($rows) . ' registrations updated.';
    if ($status === 'attended') {
        $newlyAttended = array_filter($rows, fn($r) => ($before[$r['id']] ?? '') !== 'attended');
        $asked = request_evaluations($event, array_column($newlyAttended, 'user_id'));
        if ($asked > 0) {
            $message .= ' ' . $asked . ' ' . ($asked === 1 ? 'attendee was' : 'attendees were') . ' asked to evaluate the event.';
        }
    }
    return ['ok' => true, 'message' => $message];
}

/**
 * Notify attendees who haven't evaluated an event yet to do so (the
 * notification opens the event page, which shows "Evaluate this event").
 * @param string[] $userIds
 * @return int how many people were notified
 */
function request_evaluations(array $event, array $userIds): int
{
    $userIds = array_values(array_unique(array_filter($userIds, 'is_uuid')));
    if (!$userIds) return 0;
    $done = array_column(supabase_user_request('event_evaluations', 'select=user_id&event_id=eq.' . urlencode($event['id'])), 'user_id');
    $todo = array_values(array_diff($userIds, $done));
    if (!$todo) return 0;

    $now = gmdate('c');
    $rows = supabase_user_request('notifications', '', 'POST', array_map(fn($uid) => [
        'event_id' => $event['id'],
        'user_id' => $uid,
        'title' => 'Please evaluate: ' . $event['title'],
        'message' => 'Thanks for attending! Open the event and choose "Evaluate this event". It takes about a minute.',
        'status' => 'sent',
        'sent_at' => $now,
    ], $todo));
    if ($rows) queue_notification_emails(); // also by email
    return count($rows);
}

// =============================================================================
// ADMIN — CATEGORIES, ORGANIZATIONS & OFFICERS (admin/admin-taxonomy.php,
// admin/organization-officers.php)
// =============================================================================

/** All categories with how many events use each. */
function get_all_categories_admin(): array
{
    return supabase_user_request('categories', 'select=*,events(count)&order=name.asc');
}

function get_category_admin(string $categoryId): ?array
{
    if (!is_uuid($categoryId)) return null;
    $rows = supabase_user_request('categories', 'select=*&id=eq.' . urlencode($categoryId) . '&limit=1');
    return $rows[0] ?? null;
}

function create_category_admin(array $fields): array
{
    $fields['created_by'] = current_user()['id'] ?? null;
    $rows = supabase_user_request('categories', '', 'POST', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not create the category. The name may already be taken.')]
        : ['ok' => true, 'message' => 'Category created.'];
}

function update_category_admin(string $categoryId, array $fields): array
{
    $rows = supabase_user_request('categories', 'id=eq.' . urlencode($categoryId), 'PATCH', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save changes. The name may already be taken.')]
        : ['ok' => true, 'message' => 'Category saved.'];
}

/** How many events (any status) use a category / organization. */
function count_events_using(string $column, string $id): int
{
    return count(supabase_user_request('events', 'select=id&' . $column . '=eq.' . urlencode($id)));
}

/** Delete a category — refused while events still use it (the database also refuses). */
function delete_category_admin(string $categoryId): array
{
    $used = count_events_using('category_id', $categoryId);
    if ($used > 0) {
        return ['ok' => false, 'message' => "Can't delete this category: $used event" . ($used === 1 ? ' uses' : 's use') . ' it. Change their category first.'];
    }
    $rows = supabase_user_request('categories', 'id=eq.' . urlencode($categoryId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete this category.')]
        : ['ok' => true, 'message' => 'Category deleted.'];
}

/** All organizations with their department and event / officer counts. */
function get_all_organizations_admin(): array
{
    return supabase_user_request('organizations', 'select=*,departments(name,code),events!events_organization_id_fkey(count),organization_officers(count)&order=name.asc');
}

function get_organization_admin(string $organizationId): ?array
{
    if (!is_uuid($organizationId)) return null;
    $rows = supabase_user_request('organizations', 'select=*&id=eq.' . urlencode($organizationId) . '&limit=1');
    return $rows[0] ?? null;
}

function create_organization_admin(array $fields): array
{
    $fields['created_by'] = current_user()['id'] ?? null;
    $rows = supabase_user_request('organizations', '', 'POST', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not create the organization. The name may already be taken.')]
        : ['ok' => true, 'message' => 'Organization created.'];
}

function update_organization_admin(string $organizationId, array $fields): array
{
    $rows = supabase_user_request('organizations', 'id=eq.' . urlencode($organizationId), 'PATCH', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save changes. The name may already be taken.')]
        : ['ok' => true, 'message' => 'Organization saved.'];
}

/** Delete an organization — refused while events use it. Its officers are deleted with it. */
function delete_organization_admin(string $organizationId): array
{
    $used = count_events_using('organization_id', $organizationId);
    if ($used > 0) {
        return ['ok' => false, 'message' => "Can't delete this organization: $used event" . ($used === 1 ? ' uses' : 's use') . ' it. Change their organization first.'];
    }
    $rows = supabase_user_request('organizations', 'id=eq.' . urlencode($organizationId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete this organization.')]
        : ['ok' => true, 'message' => 'Organization deleted.'];
}

/** Standard officer positions and their display order (Year Representatives = 8+). */
const OFFICER_POSITIONS = [
    'President' => 1,
    'Vice President - Internal' => 2,
    'Vice President - External' => 3,
    'Secretary' => 4,
    'Treasurer' => 5,
    'Auditor' => 6,
    'PRO' => 7,
    'Year Representative' => 8,
];

/** Officers of one organization (active first, newest academic year first, then by position). */
function get_org_officers_admin(string $organizationId): array
{
    if (!is_uuid($organizationId)) return [];
    return supabase_user_request('organization_officers', 'select=*&organization_id=eq.' . urlencode($organizationId) . '&order=is_active.desc,academic_year.desc,sort_order.asc,full_name.asc');
}

function get_officer_admin(string $officerId): ?array
{
    if (!is_uuid($officerId)) return null;
    $rows = supabase_user_request('organization_officers', 'select=*&id=eq.' . urlencode($officerId) . '&limit=1');
    return $rows[0] ?? null;
}

function save_officer_admin(?string $officerId, array $fields): array
{
    $rows = $officerId
        ? supabase_user_request('organization_officers', 'id=eq.' . urlencode($officerId), 'PATCH', $fields)
        : supabase_user_request('organization_officers', '', 'POST', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save the officer.')]
        : ['ok' => true, 'message' => 'Officer saved.'];
}

function delete_officer_admin(string $officerId): array
{
    $rows = supabase_user_request('organization_officers', 'id=eq.' . urlencode($officerId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete the officer.')]
        : ['ok' => true, 'message' => 'Officer removed.'];
}

// =============================================================================
// ORGANIZATIONS (public page)
// =============================================================================

/**
 * Every organization with its active officers for the latest academic year
 * (in position order) and its upcoming events.
 */
function get_organizations_directory(): array
{
    $orgs = supabase_request('organizations',
        'select=*,departments(name,code),organization_officers(*)&organization_officers.is_active=eq.true&organization_officers.order=sort_order.asc,full_name.asc&order=name.asc');

    $upcoming = [];
    foreach (get_upcoming_events(200) as $ev) {
        $upcoming[$ev['organization_id']][] = $ev;
    }

    foreach ($orgs as &$org) {
        $officers = $org['organization_officers'] ?? [];
        $latestYear = $officers ? max(array_column($officers, 'academic_year')) : null;
        $org['academic_year'] = $latestYear;
        $org['officers'] = array_values(array_filter($officers, fn($o) => $o['academic_year'] === $latestYear));
        $org['upcoming_events'] = $upcoming[$org['id']] ?? [];
        unset($org['organization_officers']);
    }
    unset($org);
    return $orgs;
}

// =============================================================================
// SHARED ADMIN HELPERS (constraint messages, CSV downloads, number formats)
// =============================================================================

/**
 * Friendly message when the last error names one of these database
 * constraints (e.g. 'academic_terms_no_overlap' => 'Those dates overlap…');
 * otherwise the usual friendly_error($fallback).
 */
function constraint_error(array $messages, string $fallback): string
{
    $error = supabase_last_error();
    foreach ($messages as $constraint => $message) {
        if (str_contains($error, $constraint)) {
            return $message;
        }
    }
    return friendly_error($fallback);
}

/**
 * Send rows as a CSV download (opens in Excel / Google Sheets) and stop.
 * Text that starts with = + - @ (e.g. a comment typed as "=HYPERLINK(…)")
 * gets a leading apostrophe so spreadsheets show it instead of running it.
 */
function send_csv(string $filename, array $header, array $rows): void
{
    $safe = fn($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names as UTF-8
    fputcsv($out, array_map($safe, $header), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map($safe, $row), ',', '"', '');
    }
    fclose($out);
    exit;
}

/**
 * One page of a list, $perPage rows at a time. Pages start at 1; a page
 * past the end shows the last one.
 * @return array{rows: array, page: int, pages: int, total: int, from: int, to: int}
 */
function paginate(array $rows, int $perPage, int $page): array
{
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;
    return [
        'rows' => array_slice(array_values($rows), $offset, $perPage),
        'page' => $page, 'pages' => $pages, 'total' => $total,
        'from' => $total ? $offset + 1 : 0, 'to' => min($offset + $perPage, $total),
    ];
}

/**
 * ‹ Prev · 1 2 3 … 9 · Next › under a list, and "Showing 1–10 of 24 events".
 * $p from paginate(); $url(n) is the link to page n.
 */
function render_pagination(array $p, callable $url, string $one = 'item', string $many = 'items'): string
{
    $html = '';
    if ($p['pages'] > 1) {
        $link = fn(int $n, string $text, string $attrs = '') => '<a href="' . htmlspecialchars($url($n)) . '"' . $attrs . '>' . $text . '</a>';
        $html .= '<nav class="pagination admin-pagination" aria-label="Pages">';
        if ($p['page'] > 1) $html .= $link($p['page'] - 1, '‹ Prev', ' class="btn btn-outline" rel="prev"');
        $html .= '<span class="admin-pages">';
        for ($n = 1; $n <= $p['pages']; $n++) {
            if ($n === $p['page']) {
                $html .= '<span class="admin-page is-current" aria-current="page">' . $n . '</span>';
            } elseif ($n === 1 || $n === $p['pages'] || abs($n - $p['page']) <= 1) {
                $html .= $link($n, (string) $n, ' class="admin-page"');
            } elseif (abs($n - $p['page']) === 2) {
                $html .= '<span class="admin-page-gap" aria-hidden="true">…</span>';
            }
        }
        $html .= '</span>';
        if ($p['page'] < $p['pages']) $html .= $link($p['page'] + 1, 'Next ›', ' class="btn btn-outline" rel="next"');
        $html .= '</nav>';
    }
    if ($p['total']) {
        $html .= '<p class="admin-hint admin-pagination-info">Showing ' . $p['from'] . '–' . $p['to'] . ' of ' . $p['total'] . ' ' . ($p['total'] === 1 ? $one : $many) . '</p>';
    }
    return $html;
}

/** True if $q (already trimmed) is found in any of the texts, ignoring case. */
function matches_search(string $q, array $texts): bool
{
    if ($q === '') return true;
    foreach ($texts as $text) {
        if (stripos((string) $text, $q) !== false) return true;
    }
    return false;
}

/** 'Kalinangan Hall' -> 'kalinangan-hall' (file names). */
function slugify(string $text, string $fallback = 'report'): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-') ?: $fallback;
}

/** 0.8734 -> '87%'; null -> '—'. */
function format_percent(?float $ratio): string
{
    return $ratio === null ? '—' : round($ratio * 100) . '%';
}

/** 4.5 -> '4.50'; null -> '—'. */
function format_rating(?float $mean): string
{
    return $mean === null ? '—' : number_format($mean, 2);
}

// =============================================================================
// DEPARTMENTS (admin/admin-taxonomy.php?tab=departments)
// =============================================================================

/** Departments for dropdowns and filters (alphabetical). */
function get_departments(): array
{
    return supabase_request('departments', 'select=id,name,code&order=name.asc');
}

/** "Information Technology (IT)" */
function department_label(?array $department): string
{
    if (empty($department['name'])) return '—';
    return $department['name'] . (!empty($department['code']) ? ' (' . $department['code'] . ')' : '');
}

/** "Full Name (CODE)" for an organization, the same way as department_label(). */
function organization_label(?array $organization): string
{
    if (empty($organization['name'])) return '—';
    return $organization['name'] . (!empty($organization['code']) ? ' (' . $organization['code'] . ')' : '');
}

/** All departments with how many organizations belong to each. */
function get_all_departments_admin(): array
{
    return supabase_user_request('departments', 'select=*,organizations(count)&order=name.asc');
}

function get_department_admin(string $departmentId): ?array
{
    if (!is_uuid($departmentId)) return null;
    $rows = supabase_user_request('departments', 'select=*&id=eq.' . urlencode($departmentId) . '&limit=1');
    return $rows[0] ?? null;
}

function save_department_admin(?string $departmentId, array $fields): array
{
    $rows = $departmentId
        ? supabase_user_request('departments', 'id=eq.' . urlencode($departmentId), 'PATCH', $fields)
        : supabase_user_request('departments', '', 'POST', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => constraint_error([
            'departments_name_key' => 'Another department already has that name.',
            'departments_code_key' => 'Another department already uses that code.',
        ], 'Could not save the department.')];
    }
    return ['ok' => true, 'message' => $departmentId ? 'Department saved.' : 'Department created.'];
}

/** Delete a department — refused while organizations belong to it (the database also refuses). */
function delete_department_admin(string $departmentId): array
{
    $used = count(supabase_user_request('organizations', 'select=id&department_id=eq.' . urlencode($departmentId)));
    if ($used > 0) {
        return ['ok' => false, 'message' => "Can't delete this department: $used organization" . ($used === 1 ? ' belongs' : 's belong') . ' to it. Move them to another department first.'];
    }
    $rows = supabase_user_request('departments', 'id=eq.' . urlencode($departmentId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete this department.')]
        : ['ok' => true, 'message' => 'Department deleted.'];
}

// =============================================================================
// VENUES (admin/admin-taxonomy.php?tab=venues) — the event form's Venue dropdown
// =============================================================================
// events.venue keeps the place's name as text, so deleting a venue from the
// list never changes an event that already uses it.

/** The venues list for the event form: ['on_campus' => ['AVR 1', …], 'online' => ['Zoom', …]] (A–Z; empty before the venues update is run). */
function get_venue_options(): array
{
    $out = array_fill_keys(array_keys(VENUE_LISTS), []);
    foreach (supabase_request('venues', 'select=name,venue_type&order=name.asc') as $v) {
        if (isset($out[$v['venue_type'] ?? ''])) $out[$v['venue_type']][] = $v['name'];
    }
    return $out;
}

/** Every venue on the list, with how many events (any status) are held there. */
function get_all_venues_admin(): array
{
    $venues = supabase_user_request('venues', 'select=*&order=name.asc');
    if (!$venues) return [];
    $used = [];
    foreach (supabase_user_request('events', 'select=venue,venue_type&venue=not.is.null') as $ev) {
        $key = ($ev['venue_type'] ?? '') . '|' . mb_strtolower(trim((string) $ev['venue']));
        $used[$key] = ($used[$key] ?? 0) + 1;
    }
    foreach ($venues as &$v) {
        $v['events'] = $used[$v['venue_type'] . '|' . mb_strtolower(trim($v['name']))] ?? 0;
    }
    return $venues;
}

function create_venue_admin(array $fields): array
{
    $rows = supabase_user_request('venues', '', 'POST', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => constraint_error([
            'venues_type_name_key' => '"' . $fields['name'] . '" is already on the ' . label_of(VENUE_LISTS, $fields['venue_type'], '') . ' list.',
        ], 'Could not add the venue.')];
    }
    return ['ok' => true, 'message' => 'Venue added: ' . $fields['name'] . '.'];
}

function delete_venue_admin(string $venueId): array
{
    if (!is_uuid($venueId)) return ['ok' => false, 'message' => 'That venue doesn\'t exist anymore.'];
    $rows = supabase_user_request('venues', 'id=eq.' . urlencode($venueId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete this venue.')]
        : ['ok' => true, 'message' => 'Venue deleted. Events already held there keep it.'];
}

// =============================================================================
// SCHOOL CALENDAR (academic_terms) — admin/school-calendar.php
// =============================================================================
// An event is counted in the school year and semester its start date falls
// in. Admins set each term's dates; a date outside every term uses the
// default calendar (school year starts in August: 1st sem Aug–Dec, 2nd sem
// Jan–May, summer Jun–Jul) — the same rule as school_period() in the database.

/** All terms, newest first. */
function get_academic_terms(): array
{
    static $terms = null;
    if ($terms === null) {
        $terms = supabase_request('academic_terms', 'select=*&order=start_date.desc');
    }
    return $terms;
}

function get_term_admin(string $termId): ?array
{
    if (!is_uuid($termId)) return null;
    $rows = supabase_user_request('academic_terms', 'select=*&id=eq.' . urlencode($termId) . '&limit=1');
    return $rows[0] ?? null;
}

/** Default-calendar dates of a term, e.g. ['2026-08-01', '2026-12-31'] for 2026-2027 1st. */
function default_term_range(string $schoolYear, string $semester): array
{
    $first = (int) substr($schoolYear, 0, 4);
    return match ($semester) {
        '1st' => [$first . '-08-01', $first . '-12-31'],
        '2nd' => [($first + 1) . '-01-01', ($first + 1) . '-05-31'],
        default => [($first + 1) . '-06-01', ($first + 1) . '-07-31'],
    };
}

/**
 * First and last day of a school year, or of one of its semesters: the
 * dates set in the school calendar, or the default calendar where a term
 * isn't set. @return array{0:string, 1:string}
 */
function term_range(string $schoolYear, string $semester = ''): array
{
    $starts = $ends = [];
    foreach ($semester !== '' ? [$semester] : array_keys(SEMESTERS) as $sem) {
        $range = default_term_range($schoolYear, $sem);
        foreach (get_academic_terms() as $t) {
            if ($t['school_year'] === $schoolYear && $t['semester'] === $sem) {
                $range = [$t['start_date'], $t['end_date']];
            }
        }
        $starts[] = $range[0];
        $ends[] = $range[1];
    }
    return [min($starts), max($ends)];
}

/**
 * School year and semester of a date (default: today), from the database's
 * school_period() — the same rule the reports use.
 * @return array{school_year:string, semester:string}
 */
function school_period_of(?string $date = null): array
{
    static $cache = [];
    $date = $date ?: date('Y-m-d');
    if (!isset($cache[$date])) {
        $result = supabase_rpc('school_period', ['p_date' => $date]);
        $row = ($result['ok'] && is_array($result['data'])) ? ($result['data'][0] ?? null) : null;
        if (!$row) {
            // Database unreachable: the same default calendar school_period() uses.
            $y = (int) substr($date, 0, 4);
            $m = (int) substr($date, 5, 2);
            $row = [
                'school_year' => $m >= 8 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y,
                'semester' => $m >= 8 ? '1st' : ($m <= 5 ? '2nd' : 'summer'),
            ];
        }
        $cache[$date] = $row;
    }
    return $cache[$date];
}

/** "SY 2026-2027 · 1st Semester" */
function period_label(string $schoolYear, string $semester = ''): string
{
    return 'SY ' . $schoolYear . ($semester !== '' ? ' · ' . label_of(SEMESTERS, $semester) : '');
}

function save_term_admin(?string $termId, array $fields): array
{
    $rows = $termId
        ? supabase_user_request('academic_terms', 'id=eq.' . urlencode($termId), 'PATCH', $fields)
        : supabase_user_request('academic_terms', '', 'POST', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => constraint_error([
            'academic_terms_no_overlap' => "Those dates overlap another term. Terms can't share a day.",
            'academic_terms_one_per_semester' => 'That school year already has this semester. Edit it instead.',
            'academic_terms_school_year_format' => 'School year must be two consecutive years, like 2026-2027.',
            'academic_terms_dates_check' => "The end date can't be before the start date.",
        ], 'Could not save the term.')];
    }
    return ['ok' => true, 'message' => 'Term saved.'];
}

function delete_term_admin(string $termId): array
{
    $rows = supabase_user_request('academic_terms', 'id=eq.' . urlencode($termId), 'DELETE');
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not delete the term.')]
        : ['ok' => true, 'message' => 'Term deleted. Its dates now follow the default calendar.'];
}

// =============================================================================
// REPORTS — admin/dashboard.php, admin/reports.php (the event_report view)
// =============================================================================

/**
 * Rows of the event_report view (one per non-draft event), in date order.
 * $filters: school_year, semester, department, organization, audience,
 *           venue_type, from / to (start-date range, 'Y-m-d')
 */
/**
 * Whose events the Admin Dashboard and Reports cover: null for a Super Admin
 * (every organization), else the Admin's own organization — the events it
 * runs (events.organization_id), the same ones Manage Events lists for them.
 * An Admin without an organization gets a scope nothing matches.
 */
function report_scope_organization(): ?string
{
    $user = current_user();
    if (($user['role'] ?? '') === 'admin') return null;
    $org = (string) ($user['organization_id'] ?? '');
    return is_uuid($org) ? $org : '00000000-0000-0000-0000-000000000000';
}

function get_event_report(array $filters = []): array
{
    // Admins only ever get their own organization's events, whatever the page asks for.
    if (($scope = report_scope_organization()) !== null) $filters['organization'] = $scope;
    $parts = ['select=*', 'order=start_date.asc,start_time.asc.nullslast,title.asc', 'limit=1000'];
    if (preg_match('/^\d{4}-\d{4}$/', $filters['school_year'] ?? '')) $parts[] = 'school_year=eq.' . $filters['school_year'];
    if (isset(SEMESTERS[$filters['semester'] ?? ''])) $parts[] = 'semester=eq.' . $filters['semester'];
    if (is_uuid($filters['department'] ?? '')) $parts[] = 'department_id=eq.' . $filters['department'];
    if (is_uuid($filters['organization'] ?? '')) $parts[] = 'organization_id=eq.' . $filters['organization'];
    if (isset(EVENT_AUDIENCES[$filters['audience'] ?? ''])) $parts[] = 'audience=eq.' . $filters['audience'];
    if (isset(VENUE_TYPES[$filters['venue_type'] ?? ''])) $parts[] = 'venue_type=eq.' . $filters['venue_type'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ?? '')) $parts[] = 'start_date=gte.' . $filters['from'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ?? '')) $parts[] = 'start_date=lte.' . $filters['to'];
    return supabase_user_request('event_report', implode('&', $parts));
}

/** School years that have events or calendar terms (plus the current one), newest first. */
function get_report_school_years(): array
{
    $years = array_merge(
        array_column(supabase_user_request('event_report', 'select=school_year'
            . (($scope = report_scope_organization()) !== null ? '&organization_id=eq.' . $scope : '')), 'school_year'),
        array_column(get_academic_terms(), 'school_year'),
        [school_period_of()['school_year']]
    );
    $years = array_values(array_unique(array_filter($years)));
    rsort($years);
    return $years;
}

/** Whether an event (report row) is over — attendance rates only count these. */
function report_event_ended(array $row): bool
{
    return ($row['status'] ?? '') === 'completed' || ($row['end_date'] ?? $row['start_date'] ?? '') < date('Y-m-d');
}

/**
 * Totals for a set of event_report rows. Cancelled events are counted on
 * their own and left out of every other number.
 * Keys: events, cancelled, on_campus, off_campus, online, for_students,
 * for_faculty, for_all, registered, attended, no_show, attendance_rate
 * (ended events only), evaluations, average_rating.
 */
function report_totals(array $rows): array
{
    $t = ['events' => 0, 'cancelled' => 0, 'registered' => 0, 'attended' => 0, 'no_show' => 0,
          'ended_registered' => 0, 'ended_attended' => 0, 'evaluations' => 0];
    foreach (array_keys(VENUE_TYPES) as $key) $t[$key] = 0;
    foreach (array_keys(EVENT_AUDIENCES) as $key) $t['for_' . $key] = 0;
    $ratingSum = 0.0;
    $ratingWeight = 0;

    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'cancelled') {
            $t['cancelled']++;
            continue;
        }
        $t['events']++;
        if (isset(VENUE_TYPES[$r['venue_type'] ?? ''])) $t[$r['venue_type']]++;
        if (isset(EVENT_AUDIENCES[$r['audience'] ?? ''])) $t['for_' . $r['audience']]++;
        $t['registered'] += (int) $r['registered_count'];
        $t['attended'] += (int) $r['attended_count'];
        $t['no_show'] += (int) $r['no_show_count'];
        if (report_event_ended($r)) {
            $t['ended_registered'] += (int) $r['registered_count'];
            $t['ended_attended'] += (int) $r['attended_count'];
        }
        $t['evaluations'] += (int) $r['evaluation_count'];
        if ($r['average_rating'] !== null && (int) $r['evaluation_count'] > 0) {
            $ratingSum += (float) $r['average_rating'] * (int) $r['evaluation_count'];
            $ratingWeight += (int) $r['evaluation_count'];
        }
    }
    $t['attendance_rate'] = $t['ended_registered'] > 0 ? $t['ended_attended'] / $t['ended_registered'] : null;
    $t['average_rating'] = $ratingWeight > 0 ? round($ratingSum / $ratingWeight, 2) : null;
    return $t;
}

/** Group rows by a key, keeping first-seen order: ['2026-09' => [rows], ...]. */
function group_rows(array $rows, callable $keyOf): array
{
    $groups = [];
    foreach ($rows as $row) {
        $groups[$keyOf($row)][] = $row;
    }
    return $groups;
}

/** Monday of the week a date falls in ('Y-m-d'). Weeks run Monday to Sunday. */
function week_start(string $date): string
{
    $d = new DateTimeImmutable($date);
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}

/** Every month ('Y-m') from $from to $to, inclusive. */
function month_list(string $from, string $to): array
{
    $months = [];
    $last = substr($to, 0, 7);
    for ($m = new DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= $last; $m = $m->modify('+1 month')) {
        $months[] = $m->format('Y-m');
    }
    return $months;
}

/** '2026-09' -> 'September 2026' ($short: 'Sep'). */
function month_label(string $month, bool $short = false): string
{
    $ts = strtotime($month . '-01');
    return $ts ? date($short ? 'M' : 'F Y', $ts) : $month;
}

/** 'Sep 21 – 27, 2026' for the week starting on $monday. */
function week_label(string $monday): string
{
    $start = new DateTimeImmutable($monday);
    $end = $start->modify('+6 days');
    return $start->format('M j') . ' – ' . ($start->format('M') === $end->format('M') ? $end->format('j') : $end->format('M j')) . ', ' . $end->format('Y');
}

// =============================================================================
// EVENT EVALUATION — ONE form for every event
// (admin/evaluation-form.php, enduser/evaluate.php, admin/event-evaluations.php)
// =============================================================================

/** The 1-5 rating scale, best first (labels on the form and in the results). */
const RATING_SCALE = [5 => 'Excellent', 4 => 'Very Satisfactory', 3 => 'Satisfactory', 2 => 'Fair', 1 => 'Needs Improvement'];

/**
 * Verbal interpretation of a mean rating, in equal intervals of 0.80:
 * 4.21–5.00 Excellent · 3.41–4.20 Very Satisfactory · 2.61–3.40 Satisfactory
 * · 1.81–2.60 Fair · 1.00–1.80 Needs Improvement.
 */
function rating_interpretation(?float $mean): string
{
    if ($mean === null) return '—';
    $mean = round($mean, 2);
    return match (true) {
        $mean >= 4.21 => 'Excellent',
        $mean >= 3.41 => 'Very Satisfactory',
        $mean >= 2.61 => 'Satisfactory',
        $mean >= 1.81 => 'Fair',
        default => 'Needs Improvement',
    };
}

/** The form's questions in order ($includeHidden: hidden ones too, for admins). */
function get_evaluation_questions(bool $includeHidden = false): array
{
    return supabase_request('evaluation_questions',
        'select=*' . ($includeHidden ? '' : '&is_active=eq.true') . '&order=sort_order.asc,created_at.asc',
        'GET', null, session_access_token());
}

function get_evaluation_question_admin(string $questionId): ?array
{
    if (!is_uuid($questionId)) return null;
    $rows = supabase_user_request('evaluation_questions', 'select=*&id=eq.' . urlencode($questionId) . '&limit=1');
    return $rows[0] ?? null;
}

/** Add a question (at the end of the form) or save changes to one. */
function save_question_admin(?string $questionId, array $fields): array
{
    if (!$questionId) {
        $existing = get_evaluation_questions(true);
        $fields['sort_order'] = $existing ? max(array_column($existing, 'sort_order')) + 1 : 1;
    }
    $rows = $questionId
        ? supabase_user_request('evaluation_questions', 'id=eq.' . urlencode($questionId), 'PATCH', $fields)
        : supabase_user_request('evaluation_questions', '', 'POST', $fields);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not save the question.')]
        : ['ok' => true, 'message' => $questionId ? 'Question saved.' : 'Question added to the form.'];
}

/** Hide or show a question (hidden questions keep their past answers in the results). */
function set_question_active_admin(string $questionId, bool $active): array
{
    $rows = supabase_user_request('evaluation_questions', 'id=eq.' . urlencode($questionId), 'PATCH', ['is_active' => $active]);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not update the question.')]
        : ['ok' => true, 'message' => $active ? 'Question is back on the form.' : 'Question hidden from the form. Past answers stay in the results.'];
}

/** Delete a question — refused once anyone has answered it (hide it instead). */
function delete_question_admin(string $questionId): array
{
    $rows = supabase_user_request('evaluation_questions', 'id=eq.' . urlencode($questionId), 'DELETE');
    if (empty($rows)) {
        return ['ok' => false, 'message' => constraint_error([
            'event_evaluation_answers_question_id_fkey' => "People have already answered this question, so it can't be deleted. Hide it instead; past results keep it.",
        ], 'Could not delete the question.')];
    }
    return ['ok' => true, 'message' => 'Question deleted.'];
}

/** Move a question one place up (-1) or down (+1) on the form. */
function move_question_admin(string $questionId, int $direction): array
{
    $questions = get_evaluation_questions(true);
    $ids = array_column($questions, 'id');
    $from = array_search($questionId, $ids, true);
    $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);
    if ($from === false || $to < 0 || $to >= count($ids)) {
        return ['ok' => true, 'message' => ''];
    }
    [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
    $current = array_column($questions, 'sort_order', 'id');
    foreach ($ids as $i => $id) {
        if ((int) $current[$id] !== $i + 1) {
            supabase_user_request('evaluation_questions', 'id=eq.' . urlencode($id), 'PATCH', ['sort_order' => $i + 1]);
        }
    }
    return ['ok' => true, 'message' => ''];
}

/**
 * Submit the logged-in user's evaluation of an event (all answers at once).
 * The database checks that they attended and haven't evaluated yet.
 * @param array $answers [question id => '1'..'5' | written answer]
 */
function submit_evaluation(string $eventId, array $answers): array
{
    $result = supabase_rpc('submit_event_evaluation', ['p_event_id' => $eventId, 'p_answers' => (object) $answers], true);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => friendly_error('Your evaluation could not be submitted. Please try again.')];
    }
    return ['ok' => true, 'message' => 'Thank you! Your evaluation was submitted.'];
}

/** Ids of the events the logged-in user has evaluated. */
function get_my_evaluated_event_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $user = current_user();
        $ids = $user
            ? array_column(supabase_user_request('event_evaluations', 'select=event_id&user_id=eq.' . urlencode($user['id'])), 'event_id')
            : [];
    }
    return $ids;
}

function has_evaluated(string $eventId): bool
{
    return in_array($eventId, get_my_evaluated_event_ids(), true);
}

/** Events the logged-in user attended but hasn't evaluated yet, most recent first (dashboard reminder). */
function get_my_pending_evaluations(): array
{
    $user = current_user();
    if (!$user) return [];
    $rows = supabase_user_request('event_registrations',
        'select=attended_at,events(id,title,start_date,end_date,poster_url)&user_id=eq.' . urlencode($user['id'])
        . '&status=eq.attended&order=attended_at.desc.nullslast');
    $done = get_my_evaluated_event_ids();
    return array_values(array_filter(array_column($rows, 'events'), fn($ev) => $ev && !in_array($ev['id'], $done, true)));
}

/** Every evaluation of an event with its answers (admins). Names aren't included: results are anonymous. */
function get_event_evaluations_admin(string $eventId): array
{
    if (!is_uuid($eventId)) return [];
    return supabase_user_request('event_evaluations',
        'select=id,submitted_at,event_evaluation_answers(question_id,rating,answer_text)&event_id=eq.' . urlencode($eventId) . '&order=submitted_at.asc');
}

/**
 * Per-question results of an event's evaluations.
 * Each question gets: count, mean, interpretation, distribution [5 => n, ..., 1 => n]
 * and, for written questions, answers (text list).
 * @return array{questions: array, overall_mean: ?float, responses: int}
 */
function summarize_evaluations(array $questions, array $evaluations): array
{
    $byQuestion = [];
    foreach ($evaluations as $ev) {
        foreach ($ev['event_evaluation_answers'] ?? [] as $a) {
            $byQuestion[$a['question_id']][] = $a;
        }
    }

    $out = [];
    $allRatings = [];
    foreach ($questions as $q) {
        $answers = $byQuestion[$q['id']] ?? [];
        if (!$answers && empty($q['is_active'])) {
            continue; // hidden and never answered for this event
        }
        $item = $q + ['count' => 0, 'mean' => null, 'interpretation' => '—', 'distribution' => array_fill_keys(array_keys(RATING_SCALE), 0), 'answers' => []];
        if ($q['question_type'] === 'rating') {
            foreach ($answers as $a) {
                if ($a['rating'] === null) continue;
                $item['distribution'][(int) $a['rating']]++;
                $allRatings[] = (int) $a['rating'];
                $item['count']++;
            }
            if ($item['count'] > 0) {
                $sum = 0;
                foreach ($item['distribution'] as $rating => $n) $sum += $rating * $n;
                $item['mean'] = round($sum / $item['count'], 2);
                $item['interpretation'] = rating_interpretation($item['mean']);
            }
        } else {
            $item['answers'] = array_values(array_filter(array_map(fn($a) => trim((string) $a['answer_text']), $answers), fn($t) => $t !== ''));
            $item['count'] = count($item['answers']);
        }
        $out[] = $item;
    }

    return [
        'questions' => $out,
        'overall_mean' => $allRatings ? round(array_sum($allRatings) / count($allRatings), 2) : null,
        'responses' => count($evaluations),
    ];
}

// Ended events become 'completed' on their own (at most every 5 minutes, site-wide).
if (PHP_SAPI !== 'cli') {
    auto_complete_events();
}
