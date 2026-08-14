<?php
/**
 * Supabase configuration + tiny REST helper.
 *
 * PLACEHOLDER: this app has no real backend wired up yet.
 * Drop your project values in below (or better, load them from
 * environment variables) and the helper functions will start
 * talking to your actual Supabase project via its REST (PostgREST)
 * and Auth endpoints.
 *
 * Get these from: Supabase Dashboard -> Project Settings -> API
 */

// ---- Placeholder credentials --------------------------------------------
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://fgwaeugfkrljgbbgvaox.supabase.co');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZnd2FldWdma3JsamdiYmd2YW94Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODY3MTExNjIsImV4cCI6MjEwMjI4NzE2Mn0.HvSYkJy2m0rXo5J-Dlx34tTplUT3qRDoZIM67gyY1dc');

// Service role key should ONLY ever be used server-side (never sent to the browser).
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: 'YOUR-SUPABASE-SERVICE-ROLE-KEY');

// ---- Suggested table shape (create these in Supabase's SQL editor) ------
// events(id uuid pk, title text, category text, cover_url text, event_date date,
//        location text, status text, attendee_count int, organization_id uuid, created_at timestamptz)
// organizations(id uuid pk, name text, logo_url text)
// registrations(id uuid pk, event_id uuid fk, user_id uuid fk, status text)
// announcements(id uuid pk, title text, body text, type text, created_at timestamptz)
// profiles(id uuid pk, full_name text, role text, avatar_url text)

/**
 * Minimal PostgREST request helper.
 *
 * @param string $table   Table name, e.g. "events"
 * @param string $query   Raw PostgREST query string, e.g. "select=*&order=event_date.asc"
 * @param string $method  GET | POST | PATCH | DELETE
 * @param array|null $body Payload for POST/PATCH
 * @param bool $useServiceKey Use the service role key instead of anon key (server-only actions)
 * @return array Decoded JSON response (or ['error' => ...] on failure)
 */
function supabase_request(string $table, string $query = '', string $method = 'GET', ?array $body = null, bool $useServiceKey = false): array
{
    // NOTE: This is a placeholder implementation. Until real credentials are
    // set above, it short-circuits and returns mock/sample data so the UI
    // has something to render. Once SUPABASE_URL / keys are real, remove
    // the mock branch below (or leave it as an offline fallback).
    if (SUPABASE_URL === 'https://YOUR-PROJECT-REF.supabase.co') {
        return supabase_mock_data($table);
    }

    $key = $useServiceKey ? SUPABASE_SERVICE_KEY : SUPABASE_ANON_KEY;
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . ($query ? '?' . $query : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ],
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['error' => 'Invalid response', 'raw' => $response];
}

/**
 * Sample data so the frontend renders sensibly before Supabase is connected.
 * Mirrors the table shapes documented above.
 */
function supabase_mock_data(string $table): array
{
    $mocks = [
        'events' => [
            ['id' => 1, 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'category' => 'Seminar', 'cover_url' => 'assets/event-pace.jpg', 'event_date' => 'TBA', 'location' => 'AVR 1, CITE Building', 'attendee_count' => 0],
            ['id' => 2, 'title' => 'OSH Training or SIES-ACpEs', 'category' => 'Seminar', 'cover_url' => 'assets/event-osh.jpg', 'event_date' => 'TBA', 'location' => 'AVR 1, CITE Building', 'attendee_count' => 0],
            ['id' => 3, 'title' => 'GEN Z Night 2026', 'category' => 'Event', 'cover_url' => 'assets/event-genz.jpg', 'event_date' => 'TBA', 'location' => 'AVR 1, CITE Building', 'attendee_count' => 0],
            ['id' => 4, 'title' => 'JPSSITE Talk: Misinformation', 'category' => 'Seminar', 'cover_url' => 'assets/event-talk.jpg', 'event_date' => 'TBA', 'location' => 'AVR 1, CITE Building', 'attendee_count' => 0],
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

/**
 * Very small session helper placeholder. In a real integration this would
 * verify a Supabase Auth JWT (e.g. from a cookie set after login) instead
 * of trusting a plain session variable.
 */
function current_user(): ?array
{
    session_start();
    return $_SESSION['user'] ?? [
        // Placeholder "logged in" user so the dashboard has something to show.
        'id' => 'demo-user',
        'full_name' => 'Juan Dela Cruz',
        'role' => 'Student',
        'avatar_url' => null,
    ];
}
