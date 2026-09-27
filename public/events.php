<?php
/**
 * All events: search + filters (the header / dashboard search boxes, the
 * dashboard Filters panel and every "View All" link land here).
 *   ?q=keyword &category=<id> &organization=<id> &type=seminar
 *   &audience=students|faculty &venue_type=on_campus|off_campus|online
 *   &when=upcoming|past|all &sort=date|popular &page=2
 *   ?mine=1  -> the logged-in user's registered events
 */
require_once __DIR__ . '/../config/supabase.php';

$activePage = 'events';
$mine = !empty($_GET['mine']);
if ($mine) {
    require_login();
}

// Query values as plain strings (a crafted "?category[]=x" becomes '').
$param = fn(string $key): string => get_param($key);
$filters = [
    'q' => $param('q'),
    'category' => is_uuid($param('category')) ? $param('category') : '',
    'organization' => is_uuid($param('organization')) ? $param('organization') : '',
    'type' => isset(EVENT_TYPES[$param('type')]) ? $param('type') : '',
    'audience' => in_array($param('audience'), ['students', 'faculty'], true) ? $param('audience') : '',
    'venue_type' => isset(VENUE_TYPES[$param('venue_type')]) ? $param('venue_type') : '',
    'when' => in_array($param('when'), ['upcoming', 'past', 'all'], true) ? $param('when') : 'upcoming',
    'sort' => $param('sort') === 'popular' ? 'popular' : 'date',
];
$page = max(1, (int) $param('page'));

if ($mine) {
    $registrations = get_my_registrations(0);
    $events = array_map(fn($r) => $r['events'] + ['my_status' => $r['status']], $registrations);
    $hasMore = false;
} else {
    $result = search_events($filters, $page);
    $events = $result['events'];
    $hasMore = $result['has_more'];
}

$categories = get_categories();
$organizations = get_organizations();
$isFiltered = $filters['q'] !== '' || $filters['category'] || $filters['organization'] || $filters['type']
    || $filters['audience'] || $filters['venue_type'] || $filters['when'] !== 'upcoming' || $filters['sort'] !== 'date';

// Link to another page of results, keeping the current filters.
$pageUrl = fn(int $p): string => 'events.php?' . http_build_query(array_filter($filters + ['page' => $p], fn($v) => $v !== '' && $v !== null));

$heading = $mine ? 'My Events' : ($filters['q'] !== '' ? 'Results for "' . $filters['q'] . '"' : ['upcoming' => 'Upcoming Events', 'past' => 'Past Events', 'all' => 'All Events'][$filters['when']]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($heading) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container page-main">
  <?= render_flashes() ?>

  <div class="page-head">
    <div>
      <h1><?= htmlspecialchars($heading) ?></h1>
      <p class="page-sub">
        <?php if ($mine): ?>
          Events you've registered for. Open one to see its schedule or cancel your registration.
        <?php else: ?>
          Browse campus events — search by keyword, organization, category, type, audience or venue.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($mine): ?>
      <a href="events.php" class="btn btn-outline">Browse all events</a>
    <?php elseif (current_user()): ?>
      <a href="events.php?mine=1" class="btn btn-outline">My registered events</a>
    <?php endif; ?>
  </div>

  <?php if (!$mine): ?>
  <form class="filter-bar" method="get" action="events.php" role="search">
    <input type="search" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Search events, organizations, or keywords" aria-label="Keyword">
    <select name="category" aria-label="Category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= htmlspecialchars($c['id']) ?>" <?= $filters['category'] === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="organization" aria-label="Organization">
      <option value="">All organizations</option>
      <?php foreach ($organizations as $o): ?>
        <option value="<?= htmlspecialchars($o['id']) ?>" <?= $filters['organization'] === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" aria-label="Event type">
      <option value="">All event types</option>
      <?php foreach (EVENT_TYPES as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <select name="audience" aria-label="Audience">
      <option value="">Any audience</option>
      <option value="students" <?= $filters['audience'] === 'students' ? 'selected' : '' ?>>For students</option>
      <option value="faculty" <?= $filters['audience'] === 'faculty' ? 'selected' : '' ?>>For faculty</option>
    </select>
    <select name="venue_type" aria-label="Venue type">
      <option value="">Any venue</option>
      <?php foreach (VENUE_TYPES as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['venue_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <select name="when" aria-label="When">
      <option value="upcoming" <?= $filters['when'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
      <option value="past" <?= $filters['when'] === 'past' ? 'selected' : '' ?>>Past</option>
      <option value="all" <?= $filters['when'] === 'all' ? 'selected' : '' ?>>All dates</option>
    </select>
    <select name="sort" aria-label="Sort by">
      <option value="date" <?= $filters['sort'] === 'date' ? 'selected' : '' ?>>Sort by date</option>
      <option value="popular" <?= $filters['sort'] === 'popular' ? 'selected' : '' ?>>Most popular</option>
    </select>
    <button type="submit" class="btn btn-accent">Search</button>
    <?php if ($isFiltered): ?>
      <a href="events.php" class="filter-clear">Clear</a>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <div class="events-grid events-grid-page">
    <?php if (empty($events)): ?>
      <div class="events-empty">
        <?php if ($mine): ?>
          You haven't registered for any events yet. <a href="events.php">Find an event</a>.
        <?php elseif ($isFiltered): ?>
          No events match your search. <a href="events.php">Clear the filters</a>.
        <?php else: ?>
          No upcoming events yet. Check back soon!
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php foreach ($events as $ev): ?>
      <?php include __DIR__ . '/../includes/event-card.php'; ?>
    <?php endforeach; ?>
  </div>

  <?php if ($page > 1 || $hasMore): ?>
    <nav class="pagination" aria-label="Pages">
      <?php if ($page > 1): ?><a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="btn btn-outline">‹ Previous</a><?php endif; ?>
      <span>Page <?= $page ?></span>
      <?php if ($hasMore): ?><a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="btn btn-outline">Next ›</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<?= presence_script_tag() ?>
</body>
</html>
