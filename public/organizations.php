<?php
/** Organizations directory: every org with its current officers and upcoming events. */
require_once __DIR__ . '/../config/supabase.php';

$activePage = 'organizations';
$organizations = get_organizations_directory();
$positionLabel = fn(array $o): string => $o['position'] . (!empty($o['course']) || !empty($o['year_level'])
    ? ' · ' . trim(($o['course'] ?? '') . ' ' . (!empty($o['year_level']) ? $o['year_level'] . (['', 'st', 'nd', 'rd'][$o['year_level']] ?? 'th') . ' yr' : ''))
    : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Organizations — Campus Event Hub</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<main class="container page-main">
  <div class="page-head">
    <div>
      <h1>Organizations</h1>
      <p class="page-sub">The student organizations behind campus events — their officers and what they're organizing next.</p>
    </div>
  </div>

  <?php if (empty($organizations)): ?>
    <p class="events-empty">No organizations yet.</p>
  <?php endif; ?>

  <?php if (count($organizations) > 1): ?>
    <nav class="org-jump" aria-label="Jump to organization">
      <?php foreach ($organizations as $org): ?>
        <a href="#org-<?= htmlspecialchars($org['id']) ?>"><?= htmlspecialchars($org['name']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php foreach ($organizations as $org): ?>
    <section class="org-card" id="org-<?= htmlspecialchars($org['id']) ?>">
      <header class="org-card-head">
        <?php if (!empty($org['logo_url'])): ?>
          <img src="<?= htmlspecialchars(asset_url($org['logo_url'])) ?>" alt="" class="org-logo">
        <?php else: ?>
          <span class="org-logo org-logo-initials"><?= htmlspecialchars(initials($org['name'])) ?></span>
        <?php endif; ?>
        <div>
          <h2><?= htmlspecialchars($org['name']) ?></h2>
          <?php if (!empty($org['description'])): ?>
            <p><?= nl2br(htmlspecialchars($org['description'])) ?></p>
          <?php endif; ?>
        </div>
        <a href="events.php?organization=<?= urlencode($org['id']) ?>&amp;when=all" class="btn btn-outline org-events-link">All events</a>
      </header>

      <div class="org-card-body">
        <div>
          <h3>Officers<?= $org['academic_year'] ? ' · ' . htmlspecialchars($org['academic_year']) : '' ?></h3>
          <?php if (empty($org['officers'])): ?>
            <p class="org-empty">No officers listed yet.</p>
          <?php else: ?>
            <ul class="officer-grid">
              <?php foreach ($org['officers'] as $o): ?>
                <li class="officer-card">
                  <?php if (!empty($o['photo_url'])): ?>
                    <img src="<?= htmlspecialchars(asset_url($o['photo_url'])) ?>" alt="" class="officer-photo">
                  <?php else: ?>
                    <span class="officer-photo officer-initials"><?= htmlspecialchars(initials($o['full_name'])) ?></span>
                  <?php endif; ?>
                  <strong><?= htmlspecialchars($o['full_name']) ?></strong>
                  <span><?= htmlspecialchars($positionLabel($o)) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div>
          <h3>Upcoming events</h3>
          <?php if (empty($org['upcoming_events'])): ?>
            <p class="org-empty">No upcoming events.</p>
          <?php else: ?>
            <?php foreach (array_slice($org['upcoming_events'], 0, 4) as $ev): ?>
              <a class="mini-row" href="event-details.php?id=<?= urlencode($ev['id']) ?>">
                <div class="mini-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');"></div>
                <div class="mini-info">
                  <div class="mini-title"><?= htmlspecialchars($ev['title']) ?></div>
                  <div class="mini-meta">📅 <?= htmlspecialchars(format_event_date($ev['start_date'])) ?> &nbsp; 📍 <?= htmlspecialchars(event_place($ev)) ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<?= presence_script_tag() ?>
</body>
</html>
