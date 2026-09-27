<?php
/**
 * Admin: evaluation results of one event — responses, the mean of every
 * rating question with its verbal interpretation, how the ratings were
 * spread, and the written answers. Anonymous (no names). Print-ready and
 * downloadable as CSV (one row per response).
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/charts.php';

$user = require_admin();

$eventId = get_param('event');
$event = is_uuid($eventId) ? get_event_admin($eventId) : null;
if (!$event) {
    flash('error', "That event doesn't exist anymore.");
    header('Location: event-organizer.php');
    exit;
}
$back = 'event-evaluations.php?event=' . urlencode($eventId);

$registrations = get_event_registrations_admin($eventId);
$attendeeIds = array_column(array_filter($registrations, fn($r) => $r['status'] === 'attended'), 'user_id');

// ---- Remind attendees who haven't evaluated yet ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'remind') {
    $sent = request_evaluations($event, $attendeeIds);
    flash($sent > 0 ? 'success' : 'info', $sent > 0
        ? $sent . ' ' . ($sent === 1 ? 'attendee was' : 'attendees were') . ' reminded to evaluate the event.'
        : 'Everyone who attended has already evaluated this event (or no attendance was marked yet).');
    header('Location: ' . $back);
    exit;
}

$questions = get_evaluation_questions(true);
$evaluations = get_event_evaluations_admin($eventId);
$summary = summarize_evaluations($questions, $evaluations);
$responses = $summary['responses'];
$attendedCount = count($attendeeIds);
$pending = max(0, $attendedCount - $responses);
$ratingQuestions = array_values(array_filter($summary['questions'], fn($q) => $q['question_type'] === 'rating'));
$textQuestions = array_values(array_filter($summary['questions'], fn($q) => $q['question_type'] === 'text'));

// ---- CSV: one row per response, one column per question ----
if (get_param('export') === 'csv') {
    $header = array_merge(['Response', 'Submitted'], array_column($summary['questions'], 'question'));
    $rows = [];
    foreach ($evaluations as $i => $ev) {
        $byQuestion = array_column($ev['event_evaluation_answers'] ?? [], null, 'question_id');
        $row = [$i + 1, date('Y-m-d H:i', strtotime($ev['submitted_at']))];
        foreach ($summary['questions'] as $q) {
            $a = $byQuestion[$q['id']] ?? null;
            $row[] = $a === null ? '' : ($q['question_type'] === 'rating' ? $a['rating'] : $a['answer_text']);
        }
        $rows[] = $row;
    }
    send_csv('evaluation-' . slugify($event['title'], 'event') . '.csv', $header, $rows);
}

$org = $event['organizations'] ?? null;
$activeNav = 'admin-events';
$breadcrumbs = [['Manage Events', 'event-organizer.php'], [$event['title'], 'event-organizer.php?edit=' . urlencode($eventId)], ['Evaluation Results', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Evaluation: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <header class="print-head print-only">
      <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="">
      <div class="print-head-text">
        <strong><?= htmlspecialchars(SCHOOL_NAME) ?></strong>
        <span><?= htmlspecialchars($org['name'] ?? 'Campus Event Hub') ?><?= !empty($org['departments']['name']) ? ' · ' . htmlspecialchars($org['departments']['name']) : '' ?></span>
        <h1>Event Evaluation Results</h1>
      </div>
      <?php if (!empty($org['logo_url'])): ?><img src="<?= htmlspecialchars(asset_url($org['logo_url'])) ?>" alt=""><?php else: ?><span style="width:64px"></span><?php endif; ?>
    </header>
    <p class="print-meta print-only">
      <strong>Event:</strong> <?= htmlspecialchars($event['title']) ?><br>
      <strong>Date:</strong> <?= htmlspecialchars(event_when($event)) ?> · <strong>Venue:</strong> <?= htmlspecialchars(event_place($event)) ?> (<?= htmlspecialchars(label_of(VENUE_TYPES, $event['venue_type'] ?? null)) ?>)<br>
      <strong>Respondents:</strong> <?= $responses ?> of <?= $attendedCount ?> attendees · <strong>Printed:</strong> <?= date('F j, Y') ?>
    </p>

    <div class="page-head">
      <div>
        <h1 class="page-title">Evaluation Results</h1>
        <p class="page-sub"><?= htmlspecialchars($event['title']) ?> · <?= htmlspecialchars(event_when($event)) ?></p>
      </div>
      <div class="page-head-actions">
        <?php if ($pending > 0): ?>
          <form method="POST" action="<?= htmlspecialchars($back) ?>">
            <input type="hidden" name="action" value="remind">
            <button type="submit" class="btn btn-outline">Remind <?= $pending ?> attendee<?= $pending === 1 ? '' : 's' ?></button>
          </form>
        <?php endif; ?>
        <?php if ($responses > 0): ?><a href="<?= htmlspecialchars($back) ?>&amp;export=csv" class="btn btn-outline">Download CSV</a><?php endif; ?>
        <button type="button" class="btn btn-primary" data-print>Print results</button>
      </div>
    </div>

    <?= render_flashes() ?>

    <div class="kpi-grid kpi-grid-wide">
      <div class="kpi"><span class="kpi-label">Responses</span><span class="kpi-value"><?= $responses ?></span><span class="kpi-sub">of <?= $attendedCount ?> attendee<?= $attendedCount === 1 ? '' : 's' ?></span></div>
      <div class="kpi"><span class="kpi-label">Response rate</span><span class="kpi-value"><?= format_percent($attendedCount > 0 ? $responses / $attendedCount : null) ?></span><span class="kpi-sub"><?= $pending ?> still to evaluate</span></div>
      <div class="kpi"><span class="kpi-label">Overall mean</span><span class="kpi-value"><?= format_rating($summary['overall_mean']) ?></span><span class="kpi-sub"><?= $summary['overall_mean'] !== null ? htmlspecialchars(rating_interpretation($summary['overall_mean'])) : 'No ratings yet' ?></span></div>
    </div>

    <?php if ($attendedCount === 0): ?>
      <p class="admin-note">Nobody is marked as attended yet. Mark attendance on the <a href="event-registrations.php?event=<?= urlencode($eventId) ?>">Registrations page</a>; attendees are then asked to evaluate the event.</p>
    <?php endif; ?>

    <?php if ($responses === 0): ?>
      <section class="admin-panel"><p class="chart-empty">No evaluations yet.</p></section>
    <?php else: ?>
      <section class="admin-panel admin-panel-flush chart-panel">
        <div class="admin-panel-head">
          <div>
            <h2>Ratings</h2>
            <p>Mean of each statement (5 = Excellent). Interpretation: 4.21–5.00 Excellent · 3.41–4.20 Very Satisfactory · 2.61–3.40 Satisfactory · 1.81–2.60 Fair · 1.00–1.80 Poor.</p>
          </div>
        </div>
        <div style="padding:0 22px 8px">
          <div class="rating-legend">
            <?php foreach (RATING_SCALE as $value => $label): ?>
              <span><span class="chart-swatch" style="background:<?= RATING_COLORS[$value] ?>"></span><?= $value ?> <?= htmlspecialchars($label) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
          <table class="admin-table admin-table-compact">
            <thead>
              <tr>
                <th>Statement</th><th class="num">N</th><th class="num">Mean</th><th>Interpretation</th><th>Spread (Poor ← → Excellent)</th>
                <?php foreach (array_keys(RATING_SCALE) as $value): ?><th class="num"><?= $value ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php $lastSection = null; foreach ($ratingQuestions as $q): ?>
                <?php if ($q['section'] !== $lastSection): $lastSection = $q['section']; ?>
                  <tr><td colspan="10" style="background:#f8f9fb"><strong><?= htmlspecialchars($q['section']) ?></strong></td></tr>
                <?php endif; ?>
                <tr>
                  <td><?= htmlspecialchars($q['question']) ?><?= empty($q['is_active']) ? '<div class="admin-table-sub">No longer on the form</div>' : '' ?></td>
                  <td class="num"><?= $q['count'] ?></td>
                  <td class="num"><strong><?= format_rating($q['mean']) ?></strong></td>
                  <td><?php if ($q['mean'] !== null): ?><span class="rating-pill"><?= htmlspecialchars($q['interpretation']) ?></span><?php else: ?>—<?php endif; ?></td>
                  <td style="min-width:200px"><?= chart_rating_bar($q['distribution']) ?></td>
                  <?php foreach (array_keys(RATING_SCALE) as $value): ?><td class="num"><?= $q['distribution'][$value] ?></td><?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td>Overall (all rating statements)</td><td></td>
                <td class="num"><?= format_rating($summary['overall_mean']) ?></td>
                <td colspan="7"><?= $summary['overall_mean'] !== null ? '<span class="rating-pill">' . htmlspecialchars(rating_interpretation($summary['overall_mean'])) . '</span>' : '' ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <?php if ($textQuestions): ?>
        <div class="admin-grid-halves">
          <?php foreach ($textQuestions as $q): ?>
            <section class="admin-panel">
              <div class="admin-panel-head"><div><h2><?= htmlspecialchars($q['question']) ?></h2><p><?= $q['count'] ?> answer<?= $q['count'] === 1 ? '' : 's' ?></p></div></div>
              <?php if (empty($q['answers'])): ?>
                <p class="admin-muted">No answers.</p>
              <?php else: ?>
                <ul class="eval-comments">
                  <?php foreach ($q['answers'] as $answer): ?><li><?= nl2br(htmlspecialchars($answer)) ?></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="print-signatures print-only">
      <div><strong><?= htmlspecialchars($user['full_name']) ?></strong><small>Prepared by</small></div>
      <div><strong>&nbsp;</strong><small>Noted by</small></div>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/charts.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
