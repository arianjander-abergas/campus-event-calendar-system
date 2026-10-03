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

// ---- School identity --------------------------------------------------------
// Shown in the header, sidebar, footer, login pages and on every printout.
const SCHOOL_NAME = 'La Consolacion University Philippines';
// IMAGE SLOT: school logo | file: public/assets/images/school-logo.png | square, 256×256 or larger
// Replace the placeholder file with the real logo (keep the file name).
const SCHOOL_LOGO = 'assets/images/school-logo.png';

// ---- Site base URL ----------------------------------------------------------
// Web path of the public/ folder, e.g. '/campus-event-calendar-system/public'
// under XAMPP, or '' if public/ is the web root. Shared files (this config,
// includes/*.php) are loaded from pages in different subfolders, so they
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

// ---- Database shape (Thesis Supabase project) --------------------------------
// profiles(id -> auth.users.id, full_name, role user_role['admin'|'moderator'|'user'],
//          account_type['student'|'faculty'], department_id -> departments,
//          organization_id -> organizations, year_level int2 (1-5, the student's HIGHEST year level),
//          course, student_type['regular'|'irregular'], irregular_year_levels int2[] (every year
//          level an irregular student has subjects in), avatar_url, phone, created_at, updated_at)
//          Created automatically by the handle_new_user() trigger on sign-up; only admins
//          can change `role` (protect_profile_role() trigger), moderators can't change their
//          organization, and the student/faculty choice is fixed once made.
// school_ids(user_id -> profiles, school_id unique e.g. '2023-00123', verified_at, verified_by)
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
//        year_levels int2[] (student year levels that may register; empty = all))
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
//        created_at, sent_at)
// user_presence(user_id, last_seen_at) -- Active Users heartbeat (only via functions)
// Storage buckets (public read, admin upload): event-posters, org-logos, officer-photos.
// Functions: site_stats(), event_registration_counts(), touch_presence(), clear_presence(),
//        notify_event(event_id, audience 'all'|'registered', title, message), is_admin(),
//        is_moderator(), can_manage_event(org id), can_manage_event_id(event id),
//        school_period(date) -> (school_year, semester),
//        submit_event_evaluation(event_id, answers jsonb), school_id_taken(text),
//        complete_ended_events().
// Roles: admin = everything. moderator = the events of their own organization (create, edit,
//        publish, cancel — not delete), their schedules, registrations, attendance, printouts,
//        evaluation results and attendance imports, plus their organization's officers.
//        user = student or faculty.

// =============================================================================
// HTTP LAYER
// =============================================================================

/**
 * Raw cURL request. $body: array/object -> JSON, string -> sent as-is (file uploads).
 * @return array{ok:bool, status:int, data:mixed, raw:string}
 */
function supabase_http(string $method, string $url, array $headers, $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

/** URL of the school logo (header, sidebar, footer, printouts). */
function school_logo_url(): string
{
    return asset_url(SCHOOL_LOGO);
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
    return '<img src="' . htmlspecialchars(BASE_URL . '/assets/images/icons/' . $name . '.' . ICON_EXT) . '"'
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

/** '13:30:00' -> '1:30 PM'; empty -> ''. */
function format_event_time(?string $time): string
{
    if (!$time) return '';
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}

/** "Sep 20, 2026 | 1:00 PM - 3:00 PM" (multi-day: "Sep 20 - Sep 22, 2026"). */
function event_when(array $ev): string
{
    $date = format_event_date($ev['start_date'] ?? null);
    if (!empty($ev['end_date']) && $ev['end_date'] !== ($ev['start_date'] ?? null)) {
        $date .= ' - ' . format_event_date($ev['end_date']);
    }
    $time = format_event_time($ev['start_time'] ?? null);
    if ($time !== '' && !empty($ev['end_time'])) {
        $time .= ' - ' . format_event_time($ev['end_time']);
    }
    return $date . ($time !== '' ? ' | ' . $time : '');
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
    if (!empty($ev['audience'])) $parts[] = 'For ' . label_of(EVENT_AUDIENCES, $ev['audience'], '');
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
function render_flashes(): string
{
    $html = '';
    // Tell admins when the database is missing this version's columns.
    if (schema_outdated() && is_admin_user()) {
        $html .= '<div class="flash flash-info db-banner" role="status"><span><strong>Database update needed.</strong> '
            . 'Moderators, School IDs, year levels and the duplicate checks turn on once it\'s run.</span> '
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
    $origin = getenv('APP_ORIGIN') ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
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

/** Who an event is for (events.audience). */
const EVENT_AUDIENCES = ['students' => 'Students', 'faculty' => 'Faculty', 'all' => 'Students & Faculty'];

/** Where an event happens (events.venue_type). Online = webinar. */
const VENUE_TYPES = ['on_campus' => 'On-campus', 'off_campus' => 'Off-campus', 'online' => 'Online (Webinar)'];

/** Semesters of a school year (academic_terms.semester). */
const SEMESTERS = ['1st' => '1st Semester', '2nd' => '2nd Semester', 'summer' => 'Summer'];

/** Label for a stored value, e.g. label_of(VENUE_TYPES, 'on_campus') -> 'On-campus'. */
function label_of(array $labels, ?string $key, string $default = '—'): string
{
    return $labels[(string) $key] ?? $default;
}

/** Kinds of account (profiles.account_type) and of student (profiles.student_type). */
const ACCOUNT_TYPES = ['student' => 'Student', 'faculty' => 'Faculty'];
const STUDENT_TYPES = ['regular' => 'Regular', 'irregular' => 'Irregular'];

/** Student year levels (profiles.year_level = the HIGHEST one; events.year_levels). */
const YEAR_LEVELS = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => '5th Year'];

/** Roles (profiles.role). 'user' shows as Student or Faculty (see role_display()). */
const ROLES = ['user' => 'Student / Faculty', 'moderator' => 'Moderator', 'admin' => 'Admin'];

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
// Stored uppercase without spaces. Letters, digits and dashes, 4-20 characters,
// with at least 4 digits (e.g. 2023-00123). Change SCHOOL_ID_EXAMPLE (and the
// check in valid_school_id()) to the registrar's exact format when known.
const SCHOOL_ID_EXAMPLE = '2023-00123';

function normalize_school_id(?string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', (string) $value));
}

function valid_school_id(string $normalized): bool
{
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{3,19}$/', $normalized)
        && preg_match_all('/\d/', $normalized) >= 4;
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

// =============================================================================
// SESSION & AUTH
// =============================================================================

function session_start_once(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // HttpOnly: scripts can't read the login cookie. SameSite=Lax: the
        // browser won't send it with form posts from other websites, so
        // another site can't trigger admin actions (CSRF).
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
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
    return is_string($msg) ? $msg : $fallback;
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
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/signup', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], [
        'email' => $email,
        'password' => $password,
        'data' => array_filter([
            'full_name' => $fullName,
            'account_type' => $profile['account_type'] ?? null,
            'department_id' => $profile['department_id'] ?? null,
            'organization_id' => $profile['organization_id'] ?? null,
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
        return ['ok' => true, 'message' => 'Account created. Please check your email to confirm it before logging in.', 'needsEmailConfirmation' => true];
    }
    start_user_session($user, $data['access_token'], $data['refresh_token'] ?? null);
    return ['ok' => true, 'message' => 'Account created.'];
}

/** Log in with email + password and store the session. */
function supabase_auth_signin(string $email, string $password): array
{
    $result = supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/token?grant_type=password', [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ], ['email' => $email, 'password' => $password]);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => auth_error_message($result, 'Invalid email or password.')];
    }
    $data = $result['data'];
    if (empty($data['user']) || empty($data['access_token'])) {
        return ['ok' => false, 'message' => 'Unexpected response from the sign-in server.'];
    }
    start_user_session($data['user'], $data['access_token'], $data['refresh_token'] ?? null);
    return ['ok' => true, 'message' => 'Logged in.'];
}

/**
 * Start a session from tokens Supabase put in the URL after Google sign-in
 * or a password-reset link (see auth/callback.php). The token is checked
 * with Supabase before it's trusted.
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

/** Whether a sign-in provider (e.g. 'google') is switched on in Supabase Auth. */
function auth_provider_enabled(string $provider): bool
{
    static $settings = null;
    if ($settings === null) {
        $result = supabase_http('GET', rtrim(SUPABASE_URL, '/') . '/auth/v1/settings', ['apikey: ' . SUPABASE_ANON_KEY]);
        $settings = $result['ok'] && is_array($result['data']) ? $result['data'] : [];
    }
    return !empty($settings['external'][$provider]);
}

/** Where the "Google" button sends people (Supabase handles the Google part). */
function google_signin_url(): string
{
    return rtrim(SUPABASE_URL, '/') . '/auth/v1/authorize?provider=google&redirect_to=' . rawurlencode(app_url('auth/callback.php'));
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

/** Re-read name / role / account details from `profiles` into the session. */
function reload_session_profile(): void
{
    if (empty($_SESSION['user']['id'])) return;
    $id = '&id=eq.' . urlencode($_SESSION['user']['id']);
    $rows = supabase_request('profiles', 'select=' . SESSION_PROFILE_SELECT . $id, 'GET', null, $_SESSION['access_token'] ?? null);
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
        $_SESSION['user'] = array_merge($_SESSION['user'], $row);
    }
    $_SESSION['user_checked_at'] = time();
}

/**
 * The logged-in user (id, email, full_name, role, account_type,
 * department_id, organization_id, year_level, course, student_type,
 * irregular_year_levels, school_id, school_id_verified), or null. The role
 * is re-checked every 5 minutes so an admin change takes effect without
 * logging out.
 */
function current_user(): ?array
{
    session_start_once();
    if (empty($_SESSION['user'])) return null;
    if (($_SESSION['user_checked_at'] ?? 0) < time() - 300) {
        reload_session_profile();
    }
    return $_SESSION['user'];
}

function is_admin_user(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
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
 * 'admin' -> 'Admin', 'moderator' -> 'Moderator', 'user' -> 'Student' or
 * 'Faculty' (the database keeps the enum value).
 */
function role_display(string $role, ?string $accountType = null): string
{
    return match ($role) {
        'admin' => 'Admin',
        'moderator' => 'Moderator',
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
    if (profile_missing_fields($user) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php') {
        // My Profile lists what's missing.
        header('Location: ' . BASE_URL . '/enduser/profile.php?complete=1');
        exit;
    }
    return $user;
}

/** Admins only; everyone else goes to the dashboard (the database enforces the same via is_admin()). */
function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? 'user') !== 'admin') {
        flash('error', 'That page is for admins only.');
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
        flash('error', $role === 'moderator' ? 'Your moderator account has no organization yet. Ask an admin to set it.' : 'That page is for admins and moderators only.');
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
        flash('error', $event ? 'Moderators can only manage their own organization\'s events.' : "That event doesn't exist anymore.");
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
    $schoolId = null;

    if ($type === 'student') {
        $schoolId = normalize_school_id($in['school_id'] ?? '');
        $yearLevel = parse_year_levels($in['year_level'] ?? '')[0] ?? null;
        $studentType = (string) ($in['student_type'] ?? '');
        $course = trim((string) ($in['course'] ?? ''));

        if ($schoolId === '') {
            $errors[] = 'Your School ID is required.';
        } elseif (!valid_school_id($schoolId)) {
            $errors[] = 'Please enter a valid School ID (letters, numbers and dashes, like ' . SCHOOL_ID_EXAMPLE . ').';
        } elseif ($schoolId !== normalize_school_id($ownSchoolId) && school_id_taken($schoolId)) {
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
        ? ['full_name', 'account_type', 'department_id', 'organization_id', 'year_level', 'course', 'student_type', 'irregular_year_levels']
        : ['full_name', 'organization_id', 'year_level', 'course'])); // database not updated yet: the older columns only
    // Moderators' organization is their scope: only an admin changes it (the database refuses too).
    if (($user['role'] ?? '') === 'moderator') {
        unset($allowed['organization_id']);
    }
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($user['id']), 'PATCH', $allowed);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save your profile. Please try again.')];
    }
    reload_session_profile();
    return ['ok' => true, 'message' => 'Profile saved.'];
}

/** Every account with its details, for the admin Users page. */
function get_all_profiles_admin(): array
{
    return supabase_user_request_compat('profiles',
        'select=id,full_name,role,account_type,year_level,course,student_type,irregular_year_levels,created_at,organization_id,department_id,'
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
            return ['ok' => false, 'message' => 'A moderator runs their organization\'s events, so this account needs an organization first.'];
        }
    }
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($userId), 'PATCH', ['role' => $role]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not change the role.')];
    }
    $as = ['admin' => 'an admin', 'moderator' => 'a moderator', 'user' => 'a regular ' . strtolower(role_display('user', $rows[0]['account_type'] ?? null)) . ' account'][$role];
    return ['ok' => true, 'message' => $rows[0]['full_name'] . ' is now ' . $as . '.'];
}

/**
 * Student accounts with their school IDs (Import & Verify compares the
 * registrar's list against these).
 */
function get_student_accounts_admin(): array
{
    return supabase_user_request_compat('profiles',
        'select=id,full_name,account_type,year_level,course,student_type,irregular_year_levels,'
        . 'organizations!profiles_organization_id_fkey(name),departments(name,code),school_ids(school_id,verified_at)'
        . '&role=eq.user&or=(account_type.eq.student,account_type.is.null)&order=full_name.asc&limit=5000',
        'select=id,full_name,year_level,course,organizations!profiles_organization_id_fkey(name)&role=eq.user&order=full_name.asc&limit=5000');
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
        return ['ok' => false, 'message' => 'Please enter a valid School ID (like ' . SCHOOL_ID_EXAMPLE . ').'];
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
const EVENT_CARD_SELECT = 'select=*,categories(name),organizations(id,name,logo_url)';

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
 */
function find_events(array $filters, string $from = '', string $to = '', int $limit = 500): array
{
    $parts = [EVENT_CARD_SELECT, 'status=in.(published,completed)', 'order=start_date.asc,start_time.asc.nullslast,title.asc', 'limit=' . $limit];
    if ($to !== '') $parts[] = 'start_date=lte.' . $to;
    if ($from !== '') $parts[] = 'end_date=gte.' . $from;

    if (is_uuid($filters['category'] ?? null)) $parts[] = 'category_id=eq.' . $filters['category'];
    if (is_uuid($filters['organization'] ?? null)) $parts[] = 'organization_id=eq.' . $filters['organization'];
    if (in_array($filters['audience'] ?? '', ['students', 'faculty'], true)) $parts[] = 'audience=in.(' . $filters['audience'] . ',all)';
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
    $audience = $ev['audience'] ?? 'students';
    if ($audience === 'faculty' && $type === 'student') return 'This event is for faculty only.';
    if ($audience === 'students' && $type === 'faculty') return 'This event is for students only.';

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
    $ended = ($ev['end_date'] ?? $ev['start_date'] ?? $today) < $today || ($ev['status'] ?? '') === 'completed';
    $mine = $myReg['status'] ?? null;
    $state = fn(string $s, bool $reg, bool $cancel, string $note) => ['state' => $s, 'can_register' => $reg, 'can_cancel' => $cancel, 'note' => $note];

    if ($mine === 'attended') return $state('attended', false, false, 'You attended this event.');
    if (($ev['status'] ?? '') === 'cancelled') return $state('closed', false, false, 'This event was cancelled.');
    if ($mine === 'registered') {
        return $state('registered', false, !$ended, $ended ? 'This event has ended.' : "You're registered for this event.");
    }
    if ($ended) return $state('closed', false, false, 'This event has ended.');
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
 * Register the logged-in user (or re-activate a cancelled registration).
 * The database checks every rule; its reason is passed back on failure.
 */
function register_for_event(string $eventId): array
{
    $user = current_user();
    if (!$user) return ['ok' => false, 'message' => 'Please log in to register for this event.'];

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
    return ['ok' => true, 'message' => "You're registered! We'll notify you about any updates."];
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
 * @return array<array{key:string,label:string,value:string}> [] if Supabase is unreachable
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
        . '<script src="' . htmlspecialchars(BASE_URL . '/assets/js/presence.js') . '"></script>';
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
 * Send a notification about an event (admins only — the database checks).
 * $audience: 'all' (every account) or 'registered' (people registered for it).
 * @return int how many people it was sent to (-1 on failure)
 */
function notify_event(string $eventId, string $audience, string $title, string $message): int
{
    $result = supabase_rpc('notify_event', [
        'p_event_id' => $eventId,
        'p_audience' => $audience === 'registered' ? 'registered' : 'all',
        'p_title' => $title,
        'p_message' => $message,
    ], true);
    return $result['ok'] ? (int) $result['data'] : -1;
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
    return supabase_user_request('events', 'select=*,categories(name),organizations(name,logo_url),event_programs(count)&order=created_at.desc' . $scope);
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

    $rows = supabase_request('events', 'select=id,title,status,start_date,end_date,start_time,end_time,venue,venue_type,is_online,organizations(name)'
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
    $rows = supabase_user_request('events', 'select=*,categories(name),organizations(name,logo_url,departments(name,code))&id=eq.' . urlencode($eventId) . '&limit=1');
    return $rows[0] ?? null;
}

/** @return array{ok:bool, message:string, event?:array} */
function create_event_admin(array $fields): array
{
    $fields['created_by'] = current_user()['id'] ?? null;
    $rows = supabase_user_request('events', '', 'POST', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not create the event. Please check the fields and try again.')];
    }
    return ['ok' => true, 'message' => 'Event created.', 'event' => $rows[0]];
}

/** @return array{ok:bool, message:string} */
function update_event_admin(string $eventId, array $fields): array
{
    $rows = supabase_user_request('events', 'id=eq.' . urlencode($eventId), 'PATCH', $fields);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save changes. Please try again.')];
    }
    return ['ok' => true, 'message' => 'Event updated.'];
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
        if ($sent > 0) $message .= ' ' . $sent . ' registered ' . ($sent === 1 ? 'person was' : 'people were') . ' notified.';
    }
    return ['ok' => true, 'message' => $message];
}

/** Categories / organizations for the event form's dropdowns (alphabetical). */
function get_categories(): array
{
    return supabase_request('categories', 'select=id,name&order=name.asc');
}

function get_organizations(): array
{
    return supabase_request('organizations', 'select=id,name,logo_url,department_id&order=name.asc');
}

// ---- Event programs (the Schedule) — admin/event-programs.php ---------------

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
    return supabase_user_request('organizations', 'select=*,departments(name,code),events(count),organization_officers(count)&order=name.asc');
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
function get_event_report(array $filters = []): array
{
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
        array_column(supabase_user_request('event_report', 'select=school_year'), 'school_year'),
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
const RATING_SCALE = [5 => 'Excellent', 4 => 'Very Satisfactory', 3 => 'Satisfactory', 2 => 'Fair', 1 => 'Poor'];

/**
 * Verbal interpretation of a mean rating, in equal intervals of 0.80:
 * 4.21–5.00 Excellent · 3.41–4.20 Very Satisfactory · 2.61–3.40 Satisfactory
 * · 1.81–2.60 Fair · 1.00–1.80 Poor.
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
        default => 'Poor',
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
