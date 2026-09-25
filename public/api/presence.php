<?php
/**
 * Active Users heartbeat endpoint, called by assets/js/presence.js.
 *   POST action=ping  -> "I'm still here" (every ~30s while a page is open)
 *   POST action=leave -> "I closed the tab" (Active Users -1 right away)
 * Only logged-in users are ever counted; anything else is ignored.
 */
require_once __DIR__ . '/../../config/supabase.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && current_user()) {
    if (($_POST['action'] ?? 'ping') === 'leave') {
        presence_clear();
    } else {
        presence_ping();
    }
}

http_response_code(204); // nothing to send back
