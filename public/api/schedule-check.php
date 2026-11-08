<?php
/**
 * Manage Events form helper (admins + moderators): the other events on the
 * dates being entered, and whether the event would duplicate one (same
 * title, same date) or double-book its venue. Saving checks the same again.
 *   GET ?title=&start_date=&end_date=&start_time=&end_time=&venue=&venue_type=&id=<event being edited>
 */
require_once __DIR__ . '/../../config/supabase.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_staff_user()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admins and Super Admins only.']);
    exit;
}

$ev = [
    'title' => get_param('title'),
    'start_date' => get_param('start_date'),
    'end_date' => get_param('end_date') ?: get_param('start_date'),
    'start_time' => get_param('start_time') ?: null,
    'end_time' => get_param('end_time') ?: null,
    'venue' => get_param('venue'),
    'venue_type' => get_param('venue_type'),
];
$ev['is_online'] = $ev['venue_type'] === 'online';
$exclude = is_uuid(get_param('id')) ? get_param('id') : null;

$result = find_schedule_conflicts($ev, $exclude);
$brief = fn(?array $e): ?array => $e ? [
    'title' => $e['title'],
    'when' => event_when($e),
    'place' => event_place($e),
    'organization' => $e['organizations']['name'] ?? '',
    'status' => $e['status'],
] : null;

echo json_encode([
    'same_dates' => array_map($brief, $result['same_dates']),
    'duplicate' => $brief($result['duplicate']),
    'venue_conflict' => $brief($result['venue_conflict']),
]);
