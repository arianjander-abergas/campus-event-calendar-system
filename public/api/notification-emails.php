<?php
/**
 * Sends the next batch of notifications by email (admins and moderators),
 * called by assets/js/email-outbox.js from the page opened after an event
 * was published, updated, cancelled or approved (and every 10 minutes while
 * an Admin uses the site, for emails that failed before).
 *   POST {}  -> {ok, enabled, sent, failed, more, error}
 * "more": call again for the next batch. Only this site's own pages may call
 * it (same origin, JSON); the database decides which notifications this
 * person may send (pending_notification_emails()).
 */
require_once __DIR__ . '/../../config/supabase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = function (array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originHost = $origin !== '' ? (string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') : '';
if (($site !== '' && $site !== 'same-origin') || ($origin !== '' && strcasecmp($originHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0)) {
    $respond(['ok' => false, 'error' => 'Not allowed.'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    $respond(['ok' => false, 'error' => 'Not allowed.'], 405);
}
if (!is_staff_user()) {
    $respond(['ok' => false, 'error' => 'Admins and moderators only.'], 403);
}

$result = deliver_notification_emails(40);
$_SESSION['email_outbox_checked'] = time();
if (!$result['more']) {
    unset($_SESSION['email_outbox']); // nothing left for now (failed ones are tried again later)
}
$respond(['ok' => $result['error'] === '' || $result['sent'] > 0] + $result);
