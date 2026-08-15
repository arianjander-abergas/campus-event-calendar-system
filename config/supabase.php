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
// These match the project already wired up in js/supabase-client.js.
// The anon key is safe to expose (it's public by design; access is governed
// by Row Level Security policies on each table) — env vars override these
// if set, so production deploys should still set real env vars.
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://fgwaeugfkrljgbbgvaox.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc');

// Service role key should ONLY ever be used server-side (never sent to the browser).
// Not required for the auth flow below (it deliberately uses the user's own
// access token so Row Level Security still applies), but kept here for future
// admin-only backend actions (e.g. approving events, deleting users).
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'YOUR-SUPABASE-SERVICE-ROLE-KEY');

// ---- Real table shape -----------------------------------------------------
// events(id uuid pk, title text, poster_url text, start_date date, venue text,
//        status text, category_id uuid fk -> categories.id, organization_id uuid, created_at timestamptz)
// categories(id uuid pk, name text)
// organizations(id uuid pk, name text, logo_url text)
// registrations(id uuid pk, event_id uuid fk, user_id uuid fk, status text)
// announcements(id uuid pk, title text, body text, type text, created_at timestamptz)
// profiles(id uuid pk references auth.users(id), full_name text, role text, avatar_url text)
//
// Required Supabase Auth setup (do this once in the dashboard):
// 1. Auth -> Providers -> Email: enable "Email" provider (password sign-in).
// 2. Auth -> Policies (or SQL editor), on `profiles`, add RLS policies:
//      - insert: with check (auth.uid() = id)
//      - select: using (true)                -- or auth.uid() = id if profiles should be private
//      - update: using (auth.uid() = id) with check (auth.uid() = id)
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
 * Minimal PostgREST request helper (unchanged data-access path).
 *
 * @param string $table   Table name, e.g. "events"
 * @param string $query   Raw PostgREST query string, e.g. "select=*,categories(name)&order=start_date.asc"
 * @param string $method  GET | POST | PATCH | DELETE
 * @param array|null $body Payload for POST/PATCH
 * @param bool $useServiceKey Use the service role key instead of anon key (server-only actions)
 * @param string|null $userToken If set, sent as the Authorization bearer instead of the anon/service
 *                               key, so requests run AS that user and RLS policies apply to them.
 * @return array Decoded JSON response (or ['error' => ...] on failure)
 */
function supabase_request(string $table, string $query = '', string $method = 'GET', ?array $body = null, bool $useServiceKey = false, ?string $userToken = null): array
{
    if ($table === 'events' && $query === '' && $method === 'GET') {
        $query = 'select=*,categories(name)&order=start_date.asc';
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

    if (!$result['ok'] && $result['status'] === 0) {
        // Network-level failure (no Supabase reachable at all) — fall back to
        // mock data so the UI still renders something during dev/offline work.
        return supabase_mock_data($table);
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
        'announcements' => [
            ['id' => 1, 'title' => 'New scholarship opportunity available!', 'type' => 'info', 'created_at' => 'TBA'],
            ['id' => 2, 'title' => 'Class suspended on Month 00 0000 (Day)', 'type' => 'alert', 'created_at' => 'TBA'],
            ['id' => 3, 'title' => 'Submission of your requirements!', 'type' => 'reminder', 'created_at' => 'TBA'],
        ],
        'stats' => [
            ['label' => 'Events', 'value' => '000+'],
            ['label' => 'Organizations', 'value' => '0+'],
            ['label' => 'Active Users', 'value' => '0.0k+'],
            ['label' => 'Registrations', 'value' => '00k+'],
        ],
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
        supabase_http('POST', rtrim(SUPABASE_URL, '/') . '/auth/v1/logout', [
            'apikey: ' . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . $accessToken,
        ]);
    }
    $_SESSION = [];
    session_destroy();
}

/** Insert the profiles row for a freshly-created user. */
function create_profile(string $userId, string $fullName, string $accessToken): void
{
    supabase_request('profiles', '', 'POST', [
        'id' => $userId,
        'full_name' => $fullName,
        'role' => 'Student',
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

    // Pull the role from profiles so it reflects reality (defaults to Student).
    $profile = supabase_request('profiles', 'select=full_name,role&id=eq.' . urlencode($authUser['id']), 'GET', null, false, $accessToken);
    $role = $profile[0]['role'] ?? 'Student';
    $profileName = $profile[0]['full_name'] ?? $fullName;

    $_SESSION['access_token'] = $accessToken;
    $_SESSION['refresh_token'] = $refreshToken;
    $_SESSION['user'] = [
        'id' => $authUser['id'],
        'email' => $authUser['email'],
        'full_name' => $profileName,
        'role' => $role,
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
