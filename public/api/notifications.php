<?php
/**
 * Notification bell endpoint, called by assets/js/notifications.js.
 *   POST action=mark_read -> mark all of the logged-in user's notifications read
 * Responds with JSON {ok: bool}.
 */
require_once __DIR__ . '/../../config/supabase.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$ok = ($_POST['action'] ?? '') === 'mark_read' && mark_my_notifications_read();
echo json_encode(['ok' => $ok]);
