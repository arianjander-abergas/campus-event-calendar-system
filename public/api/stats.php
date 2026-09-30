<?php
/**
 * Live home-page counters for main.js wireLiveStats():
 * {"events":"4","organizations":"3","active_users":"1","registrations":"4"}
 * (already formatted, e.g. "1.2k"). Totals only — safe for anyone.
 */
require_once __DIR__ . '/../../config/supabase.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = [];
foreach (get_site_stats() as $stat) {
    $out[$stat['key']] = $stat['value'];
}
echo json_encode($out);
