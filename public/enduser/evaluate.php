<?php
/**
 * Evaluate an event (attendees only, once per event). Uses the one
 * standard evaluation form set in Admin -> Evaluation Form. The database
 * (submit_event_evaluation) checks attendance and saves all answers at once.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login();

$eventId = get_param('event');
$event = is_uuid($eventId) ? get_event($eventId) : null;
if (!$event) {
    flash('error', "That event doesn't exist anymore.");
    header('Location: dashboard.php');
    exit;
}

$myRegistration = get_my_registration($eventId);
$attended = ($myRegistration['status'] ?? '') === 'attended';
$evaluated = has_evaluated($eventId);
$questions = get_evaluation_questions();
$answers = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $attended && !$evaluated && $questions) {
    $posted = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
    foreach ($questions as $q) {
        $value = is_string($posted[$q['id']] ?? null) ? trim($posted[$q['id']]) : '';
        if ($value !== '') {
            $answers[$q['id']] = $value;
        }
    }
    foreach ($questions as $q) {
        if ($q['is_required'] && !isset($answers[$q['id']])) {
            $errors[] = 'Please answer every required question (marked *).';
            break;
        }
    }
    if (!$errors) {
        $result = submit_evaluation($eventId, $answers);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: ../event-details.php?id=' . urlencode($eventId));
            exit;
        }
        $errors[] = $result['message'];
    }
}

$org = $event['organizations'] ?? null;
$activeNav = 'events';
$breadcrumbs = [['Dashboard', 'dashboard.php'], [$event['title'], '../event-details.php?id=' . urlencode($eventId)], ['Evaluation', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title>Evaluate: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">Event Evaluation</h1>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-card" style="max-width:820px">
      <div class="eval-event">
        <div class="eval-event-poster" style="background-image:url('<?= css_url(event_poster_url($event['poster_url'] ?? null)) ?>');"></div>
        <div>
          <h2><?= htmlspecialchars($event['title']) ?></h2>
          <p><?= icon('calendar', 14) ?> <?= htmlspecialchars(event_when($event)) ?></p>
          <p><?= icon('location', 14) ?> <?= htmlspecialchars(event_place($event)) ?><?= !empty($org['name']) ? ' · ' . htmlspecialchars($org['name']) : '' ?></p>
        </div>
      </div>

      <?php if ($evaluated): ?>
        <div class="eval-state">
          <h2>Thank you!</h2>
          <p>You already evaluated this event. Your feedback helps the organizers plan better events.</p>
          <a href="../event-details.php?id=<?= urlencode($eventId) ?>" class="btn btn-outline">Back to the event</a>
        </div>
      <?php elseif (!$attended): ?>
        <div class="eval-state">
          <h2>Only attendees can evaluate</h2>
          <p>The organizers mark attendance during the event. If you attended but can't open the form, ask them to mark your attendance.</p>
          <a href="../event-details.php?id=<?= urlencode($eventId) ?>" class="btn btn-outline">Back to the event</a>
        </div>
      <?php elseif (empty($questions)): ?>
        <div class="eval-state">
          <h2>The evaluation form isn't ready yet</h2>
          <p>Please check back later.</p>
        </div>
      <?php else: ?>
        <p class="eval-scale-legend">Rate each statement from 1 to 5 stars (1 – Needs Improvement, 5 – Excellent). Questions marked <span class="req" style="color:var(--red)">*</span> are required.</p>
        <form method="POST" action="evaluate.php?event=<?= urlencode($eventId) ?>" data-autoreset>
          <?php $preview = false; include __DIR__ . '/../../includes/evaluation-fields.php'; ?>
          <div class="eval-submit">
            <button type="submit" class="btn btn-primary">Submit Evaluation</button>
            <span class="eval-anonymous">Your answers are shown to the organizers without your name. You can submit once.</span>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </main>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
