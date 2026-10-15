<?php
/**
 * Event Hub Assistant endpoint, called by assets/js/assistant.js (the chat box
 * in the corner of every page — includes/assistant-widget.php).
 *   POST {"action":"send", "message":"…", "page":"/events.php?…", "title":"…"}
 *        -> {ok: true, entry: {who: "bot", text, link: {label, url} | null}}
 *   POST {"action":"reset"}   -> {ok: true}               ("New chat")
 *   GET  ?action=history      -> {ok: true, log, busy}    (an answer still on its
 *                                                          way after a change of page)
 * Errors: {ok: false, error: "message for the chat box"}.
 * Only this site's own pages may call it. The assistant itself — which can
 * only read the site — is in includes/assistant.php (with an API key: Claude)
 * and includes/assistant-basic.php (without one: basic mode, no AI).
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/assistant.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function assistant_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!assistant_same_origin()) {
    assistant_respond(['ok' => false, 'error' => 'Not allowed.'], 403);
}

$user = current_user();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && get_param('action') === 'history') {
    assistant_respond(['ok' => true, 'log' => assistant_display_log(), 'busy' => assistant_busy()]);
}
// JSON only: other sites' forms can't send it (the browser would ask this site first, and it says no).
if ($method !== 'POST' || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    assistant_respond(['ok' => false, 'error' => 'Not allowed.'], 405);
}

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
$action = is_array($in) ? ($in['action'] ?? '') : '';

if ($action === 'reset') {
    assistant_reset();
    assistant_respond(['ok' => true]);
}
if ($action !== 'send') {
    assistant_respond(['ok' => false, 'error' => 'Not allowed.'], 400);
}

$message = assistant_clean_text($in['message'] ?? '', ASSISTANT_MAX_MESSAGE);
if ($message === '') {
    assistant_respond(['ok' => false, 'error' => 'Type a question first.'], 422);
}
if (assistant_busy()) {
    assistant_respond(['ok' => false, 'error' => "I'm still answering your last message — one moment."], 409);
}
$limited = assistant_rate_limit($user);
if ($limited !== '') {
    assistant_respond(['ok' => false, 'error' => $limited], 429);
}

$result = assistant_handle($user, $message, assistant_page_context($in['page'] ?? '', $in['title'] ?? ''));
assistant_respond($result['ok'] ? $result : ['ok' => false, 'error' => $result['error'] ?? 'Sorry, something went wrong.'], $result['ok'] ? 200 : (int) ($result['status'] ?? 502));
