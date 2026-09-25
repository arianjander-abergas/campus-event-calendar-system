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
// profiles(id -> auth.users.id, full_name, role user_role['admin'|'user'], year_level int2 (1-5),
//          course, organization_id -> organizations, avatar_url, phone, created_at, updated_at)
//          Created automatically by the handle_new_user() trigger on sign-up; only admins
//          can change `role` (protect_profile_role() trigger).
// organizations(id, name unique, description, logo_url, created_by, created_at, updated_at)
// organization_officers(id, organization_id, profile_id (optional), full_name, position, photo_url,
//          academic_year e.g. '2026-2027', year_level, course, sort_order, is_active, ...)
// categories(id, name unique, description, created_by, created_at, updated_at)
// events(id, title, category_id, organization_id, event_type['academic'|'seminar'|'workshop'|
//        'sports'|'cultural'|'other'], description, start_date, start_time, end_date, end_time,
//        venue, is_online, online_event_link, address, poster_url, registration_limit,
//        registration_deadline, contact_email, contact_number, enable_registration,
//        is_published (false only for drafts), send_notification,
//        status['draft'|'published'|'cancelled'|'completed'], created_by, created_at, updated_at)
//        Categories/organizations can't be deleted while an event uses them (ON DELETE RESTRICT).
// event_programs(id, event_id, title, description, speaker, sort_order, ...)  -- event Schedule
// event_registrations(id, event_id, user_id, status['registered'|'waitlisted'|'cancelled'|
//        'attended'|'no_show'], registered_at) unique (event_id, user_id)
//        check_event_registration() trigger enforces: published, registration enabled,
//        before the deadline, not ended, not full. Students can only cancel/re-register.
// notifications(id, event_id, user_id, title, message, status['pending'|'sent'|'failed'|'read'],
//        created_at, sent_at)
// user_presence(user_id, last_seen_at) -- Active Users heartbeat (only via functions)
// Storage buckets (public read, admin upload): event-posters, org-logos, officer-photos.
// Functions: site_stats(), event_registration_counts(), touch_presence(), clear_presence(),
//        notify_event(event_id, audience 'all'|'registered', title, message), is_admin().

// =============================================================================
// HTTP LAYER
// =============================================================================

/**
 * Raw cURL request. $body: array/object -> JSON, string -> sent as-is (file uploads).
 * @return array{ok:bool, status:int, data:mixed, raw:string}
 */
function supabase_http(string $method, string $url, array $headers, $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
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

/** Placeholder shown on event cards when an event has no poster. */
function event_poster_url(?string $posterUrl): string
{
    return asset_url($posterUrl, BASE_URL . '/assets/images/event-placeholder.svg');
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
 * Create an account. The profile row (with year level / course /
 * organization) is created by the database's handle_new_user() trigger from
 * the metadata sent here.
 *
 * @param array $profile ['year_level' => 1-5|null, 'course' => ?string, 'organization_id' => ?uuid]
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
            'year_level' => $profile['year_level'] ?? null,
            'course' => $profile['course'] ?? null,
            'organization_id' => $profile['organization_id'] ?? null,
        ], fn($v) => $v !== null && $v !== ''),
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

/** Re-read name / role / student details from `profiles` into the session. */
function reload_session_profile(): void
{
    if (empty($_SESSION['user']['id'])) return;
    $rows = supabase_request('profiles', 'select=full_name,role,year_level,course,organization_id&id=eq.' . urlencode($_SESSION['user']['id']), 'GET', null, $_SESSION['access_token'] ?? null);
    if (!empty($rows[0])) {
        $_SESSION['user'] = array_merge($_SESSION['user'], $rows[0]);
    }
    $_SESSION['user_checked_at'] = time();
}

/**
 * The logged-in user (id, email, full_name, role, year_level, course,
 * organization_id), or null. The role is re-checked every 5 minutes so an
 * admin change takes effect without logging out.
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

/** 'admin' -> 'Admin', 'user' -> 'Student' (the database keeps the enum value). */
function role_display(string $role): string
{
    return $role === 'admin' ? 'Admin' : 'Student';
}

/** Send logged-out visitors to the login page, then back here afterwards. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: ' . BASE_URL . '/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? BASE_URL . '/enduser/dashboard.php'));
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

// =============================================================================
// PROFILES
// =============================================================================

/** Update the logged-in user's own details (never the role — the database blocks that). */
function update_my_profile(array $fields): array
{
    $user = current_user();
    if (!$user) return ['ok' => false, 'message' => 'Please log in again.'];
    $allowed = array_intersect_key($fields, array_flip(['full_name', 'year_level', 'course', 'organization_id']));
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($user['id']), 'PATCH', $allowed);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not save your profile. Please try again.')];
    }
    reload_session_profile();
    return ['ok' => true, 'message' => 'Profile saved.'];
}

/** All accounts, for the admin Users page. */
function get_all_profiles_admin(): array
{
    return supabase_user_request('profiles', 'select=id,full_name,role,year_level,course,created_at,organizations!profiles_organization_id_fkey(name)&order=role.asc,full_name.asc');
}

function set_user_role_admin(string $userId, string $role): array
{
    if (!in_array($role, ['admin', 'user'], true)) {
        return ['ok' => false, 'message' => 'Invalid role.'];
    }
    if ($userId === (current_user()['id'] ?? '')) {
        return ['ok' => false, 'message' => "You can't change your own role."];
    }
    $rows = supabase_user_request('profiles', 'id=eq.' . urlencode($userId), 'PATCH', ['role' => $role]);
    if (empty($rows)) {
        return ['ok' => false, 'message' => friendly_error('Could not change the role.')];
    }
    return ['ok' => true, 'message' => $rows[0]['full_name'] . ' is now ' . ($role === 'admin' ? 'an admin.' : 'a student.')];
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
 * Search/browse published events (events.php).
 * $filters: q, category, organization, type, when ('upcoming'|'past'|'all'), sort ('date'|'popular')
 * @return array{events: array, has_more: bool}
 */
function search_events(array $filters, int $page = 1, int $perPage = 12): array
{
    $today = date('Y-m-d');
    $parts = [EVENT_CARD_SELECT, 'status=eq.published'];

    $when = $filters['when'] ?? 'upcoming';
    if ($when === 'past') {
        $parts[] = 'end_date=lt.' . $today;
        $parts[] = 'order=start_date.desc';
    } elseif ($when === 'all') {
        $parts[] = 'order=start_date.desc';
    } else {
        $parts[] = 'end_date=gte.' . $today;
        $parts[] = 'order=start_date.asc,start_time.asc.nullslast';
    }

    if (is_uuid($filters['category'] ?? null)) $parts[] = 'category_id=eq.' . $filters['category'];
    if (is_uuid($filters['organization'] ?? null)) $parts[] = 'organization_id=eq.' . $filters['organization'];
    if (isset(EVENT_TYPES[$filters['type'] ?? ''])) $parts[] = 'event_type=eq.' . $filters['type'];

    // Keyword: title, description, venue — or an organization/category whose name matches.
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

    $page = max(1, $page);
    if (($filters['sort'] ?? '') === 'popular') {
        $events = supabase_request('events', implode('&', $parts) . '&limit=500');
        $counts = get_registration_counts();
        foreach ($events as &$ev) $ev['going'] = $counts[$ev['id']] ?? 0;
        unset($ev);
        usort($events, fn($a, $b) => $b['going'] <=> $a['going']);
        $slice = array_slice($events, ($page - 1) * $perPage, $perPage + 1);
    } else {
        $slice = supabase_request('events', implode('&', $parts) . '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage));
    }
    return ['events' => array_slice($slice, 0, $perPage), 'has_more' => count($slice) > $perPage];
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

/** Dates of published events for the dashboard calendar dots. */
function get_calendar_events(): array
{
    return array_map(
        fn($e) => ['id' => $e['id'], 'title' => $e['title'], 'start' => $e['start_date'], 'end' => $e['end_date']],
        supabase_request('events', 'select=id,title,start_date,end_date&status=eq.published&limit=500')
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
 * What the Register button should do for this user and event — mirrors the
 * rules the database enforces in check_event_registration().
 * @return array{state:string, can_register:bool, can_cancel:bool, note:string}
 */
function registration_state(array $ev, ?array $myReg, int $going): array
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

/** All events (every status), newest first, for the admin table. */
function get_all_events_admin(): array
{
    return supabase_user_request('events', 'select=*,categories(name),organizations(name),event_programs(count)&order=created_at.desc');
}

/** Single event by id (any status), for pre-filling the edit form. */
function get_event_admin(string $eventId): ?array
{
    if (!is_uuid($eventId)) return null;
    $rows = supabase_user_request('events', 'select=*,categories(name),organizations(name)&id=eq.' . urlencode($eventId) . '&limit=1');
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

/** Delete an event (its registrations, programs and notifications go with it). */
function delete_event_admin(string $eventId): array
{
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
    return supabase_request('organizations', 'select=id,name&order=name.asc');
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
    return supabase_user_request('event_registrations',
        'select=id,status,registered_at,user_id,profiles(full_name,year_level,course,organizations!profiles_organization_id_fkey(name))&event_id=eq.' . urlencode($eventId) . '&order=registered_at.asc');
}

function set_registration_status_admin(string $registrationId, string $status): array
{
    if (!in_array($status, ['registered', 'attended', 'no_show', 'cancelled'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }
    $rows = supabase_user_request('event_registrations', 'id=eq.' . urlencode($registrationId), 'PATCH', ['status' => $status]);
    return empty($rows)
        ? ['ok' => false, 'message' => friendly_error('Could not update the registration.')]
        : ['ok' => true, 'message' => 'Registration updated.'];
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

/** All organizations with event and officer counts. */
function get_all_organizations_admin(): array
{
    return supabase_user_request('organizations', 'select=*,events(count),organization_officers(count)&order=name.asc');
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
        'select=*,organization_officers(*)&organization_officers.is_active=eq.true&organization_officers.order=sort_order.asc,full_name.asc&order=name.asc');

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
