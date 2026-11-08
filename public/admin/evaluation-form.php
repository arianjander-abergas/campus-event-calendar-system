<?php
/**
 * Super Admins and Admins: the event evaluation form — ONE form used for
 * every event. Add, edit, reorder, hide or delete questions (questions people
 * already answered can only be hidden, so past results stay complete).
 * Replace the default questions with the school's official evaluation form.
 * "Results by event" lists the latest events' evaluations (an Admin: their
 * organization's events) with a link to each event's full results.
 * Admins editing it needs migration 20261018150000_audiences_and_evaluation_form.sql.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_staff();
$isSuper = is_super_admin_user($user);

$editId = get_param('edit');
$editQuestion = $editId !== '' ? get_evaluation_question_admin($editId) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    $id = post_param('id');

    if ($action === 'save_question') {
        $fields = [
            'section' => post_param('section') ?: 'General',
            'question' => post_param('question'),
            'question_type' => post_param('question_type') === 'text' ? 'text' : 'rating',
            'is_required' => isset($_POST['is_required']),
        ];
        if ($fields['question'] === '') $errors[] = 'Please type the question.';
        if (mb_strlen($fields['question']) > 500) $errors[] = 'Keep the question under 500 characters.';
        if (mb_strlen($fields['section']) > 80) $errors[] = 'Keep the section name under 80 characters.';
        if (!$errors) {
            $result = save_question_admin(is_uuid($id) ? $id : null, $fields);
            if ($result['ok']) {
                flash('success', $result['message']);
                header('Location: evaluation-form.php');
                exit;
            }
            $errors[] = $result['message'];
        }
        $editQuestion = $fields + ['id' => $id];
        $editId = $id;
    } else {
        if ($action === 'delete_question') $result = delete_question_admin($id);
        elseif ($action === 'hide_question') $result = set_question_active_admin($id, false);
        elseif ($action === 'show_question') $result = set_question_active_admin($id, true);
        elseif ($action === 'move_up' || $action === 'move_down') $result = move_question_admin($id, $action === 'move_up' ? -1 : 1);
        else $result = ['ok' => false, 'message' => 'Unknown action.'];
        if ($result['message'] !== '') flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: evaluation-form.php');
        exit;
    }
}

// Every question with how many answers it has (answered questions can't be deleted).
$questions = supabase_user_request('evaluation_questions', 'select=*,event_evaluation_answers(count)&order=sort_order.asc,created_at.asc');
$activeQuestions = array_values(array_filter($questions, fn($q) => $q['is_active']));
$answeredById = array_column(array_map(fn($q) => ['id' => $q['id'], 'n' => (int) ($q['event_evaluation_answers'][0]['count'] ?? 0)], $questions), 'n', 'id');
$sections = array_values(array_unique(array_column($questions, 'section')));
$lastIndex = count($questions) - 1;

$f = $editQuestion ?? ['question_type' => 'rating', 'is_required' => true, 'section' => end($sections) ?: 'General'];
$val = fn(string $key) => htmlspecialchars((string) ($f[$key] ?? ''));
$isEditing = !empty($f['id']);

// Monitoring: the latest events that have evaluations (an Admin: their organization's).
$results = array_values(array_filter(
    get_event_report(['school_year' => 'all'] + ($isSuper ? [] : ['organization' => (string) ($user['organization_id'] ?? '')])),
    fn($r) => (int) ($r['evaluation_count'] ?? 0) > 0
));
usort($results, fn($a, $b) => strcmp($b['start_date'], $a['start_date']));
$results = array_slice($results, 0, 10);
$canEdit = $isSuper || audiences_ready(); // Admins: once the database update is in

$activeNav = 'admin-evaluation';
$breadcrumbs = [$isSuper ? ['Admin Dashboard', 'dashboard.php'] : ['Dashboard', '../enduser/dashboard.php'], ['Evaluation Form', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title>Evaluation Form — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Evaluation Form</h1>
        <p class="page-sub">One standard form for every event. Attendees answer it after the event; results are anonymous.</p>
      </div>
      <div class="page-head-actions">
        <a href="#preview" class="btn btn-outline">Preview the form</a>
      </div>
    </div>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
    <?php if (!$canEdit): ?>
      <div class="admin-note"><strong>View only for now.</strong> Admins can edit the evaluation form once a Super Admin runs the latest database update (Database update page).</div>
    <?php endif; ?>

    <p class="admin-note">The school's evaluation form, in parts (Part I, Part II…): questions with the same <strong>Section</strong> are shown together.
      Rating questions are 1 to 5 stars: <strong>1 – Needs Improvement · 2 – Fair · 3 – Satisfactory · 4 – Very Satisfactory · 5 – Excellent</strong>.
      Once someone has answered a question it can't be deleted, only hidden, so past results stay complete.</p>

    <div class="admin-split">
      <form method="POST" action="evaluation-form.php<?= $isEditing ? '?edit=' . urlencode($f['id']) : '' ?>" class="admin-card" data-autoreset>
        <input type="hidden" name="action" value="save_question">
        <?php if ($isEditing): ?><input type="hidden" name="id" value="<?= $val('id') ?>"><?php endif; ?>
        <h2 class="admin-card-title"><?= $isEditing ? 'Edit Question' : 'Add Question' ?></h2>

        <label class="admin-label" for="section">Section</label>
        <input type="text" id="section" name="section" class="admin-input" list="sectionList" maxlength="80" placeholder="e.g. Part I: Activity Experience" value="<?= $val('section') ?>">
        <datalist id="sectionList">
          <?php foreach ($sections as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>

        <label class="admin-label" for="question">Question <span class="req">*</span></label>
        <textarea id="question" name="question" class="admin-input" rows="3" maxlength="500" placeholder="e.g. The speaker explained the topic clearly." required><?= $val('question') ?></textarea>

        <span class="admin-label">Answer type</span>
        <div class="admin-radio-row">
          <label class="admin-radio"><input type="radio" name="question_type" value="rating" <?= ($f['question_type'] ?? 'rating') === 'rating' ? 'checked' : '' ?>> Rating (1 to 5 stars)</label>
          <label class="admin-radio"><input type="radio" name="question_type" value="text" <?= ($f['question_type'] ?? '') === 'text' ? 'checked' : '' ?>> Written answer</label>
        </div>
        <?php if ($isEditing && ($answeredById[$f['id']] ?? 0) > 0): ?>
          <p class="admin-hint">People have answered this question. Fixing its wording is fine; for a different question, hide this one and add a new one.</p>
        <?php endif; ?>

        <label class="admin-checkbox admin-checkbox-spaced"><input type="checkbox" name="is_required" <?= !empty($f['is_required']) ? 'checked' : '' ?>> Required</label>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Save Changes' : 'Add to Form' ?></button>
          <?php if ($isEditing): ?><a href="evaluation-form.php" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr><th>Order</th><th>Question</th><th>Type</th><th class="num">Answers</th><th><span class="visually-hidden">Actions</span></th></tr>
          </thead>
          <tbody>
            <?php if (empty($questions)): ?>
              <tr><td colspan="5" class="admin-table-empty">No questions yet — add the first one.</td></tr>
            <?php endif; ?>
            <?php $lastSection = null; foreach ($questions as $i => $q): $answered = (int) ($q['event_evaluation_answers'][0]['count'] ?? 0); ?>
              <?php if ($q['section'] !== $lastSection): $lastSection = $q['section']; ?>
                <tr class="admin-section-row"><td colspan="5"><strong><?= htmlspecialchars($q['section']) ?></strong></td></tr>
              <?php endif; ?>
              <tr class="<?= $q['is_active'] ? '' : 'admin-row-hidden' ?>">
                <td>
                  <span class="question-order">
                    <form method="POST" action="evaluation-form.php"><input type="hidden" name="action" value="move_up"><input type="hidden" name="id" value="<?= htmlspecialchars($q['id']) ?>"><button type="submit" class="btn-sm" aria-label="Move up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
                    <form method="POST" action="evaluation-form.php"><input type="hidden" name="action" value="move_down"><input type="hidden" name="id" value="<?= htmlspecialchars($q['id']) ?>"><button type="submit" class="btn-sm" aria-label="Move down" <?= $i === $lastIndex ? 'disabled' : '' ?>>↓</button></form>
                  </span>
                </td>
                <td>
                  <?= htmlspecialchars($q['question']) ?>
                  <div class="admin-table-sub"><?= $q['is_required'] ? 'Required' : 'Optional' ?><?= $q['is_active'] ? '' : ' · Hidden from the form' ?></div>
                </td>
                <td class="nowrap"><?= $q['question_type'] === 'rating' ? 'Rating 1–5' : 'Written' ?></td>
                <td class="num"><?= $answered ?></td>
                <td class="admin-row-actions">
                  <a href="evaluation-form.php?edit=<?= urlencode($q['id']) ?>" class="btn-sm">Edit</a>
                  <form method="POST" action="evaluation-form.php">
                    <input type="hidden" name="action" value="<?= $q['is_active'] ? 'hide_question' : 'show_question' ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($q['id']) ?>">
                    <button type="submit" class="btn-sm<?= $q['is_active'] ? '' : ' btn-sm-accent' ?>"><?= $q['is_active'] ? 'Hide' : 'Show' ?></button>
                  </form>
                  <form method="POST" action="evaluation-form.php" data-confirm="Delete this question from the evaluation form?" data-confirm-title="Delete question" data-confirm-ok="Delete" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="delete_question">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($q['id']) ?>">
                    <button type="submit" class="btn-sm btn-sm-danger" <?= $answered ? 'disabled title="Already answered — hide it instead"' : '' ?>>Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <section class="admin-panel" id="results" style="margin-top:24px">
      <div class="admin-panel-head">
        <div>
          <h2>Results by event</h2>
          <p><?= $isSuper ? 'The latest events with evaluations.' : 'Your organization\'s latest events with evaluations.' ?> Open one to see every answer.</p>
        </div>
      </div>
      <?php if (!$results): ?>
        <p class="admin-muted">No evaluations yet. Attendees can answer the form once they're marked Attended.</p>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table admin-table-compact">
            <thead><tr><th>Event</th><th>Date</th><th>Attended</th><th>Evaluations</th><th>Average rating</th><th><span class="visually-hidden">Results</span></th></tr></thead>
            <tbody>
              <?php foreach ($results as $r): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($r['title']) ?></strong><div class="admin-table-sub"><?= htmlspecialchars($r['organization_name'] ?? '') ?></div></td>
                  <td class="nowrap"><?= htmlspecialchars(format_event_date($r['start_date'])) ?></td>
                  <td><?= (int) ($r['attended_count'] ?? 0) ?></td>
                  <td><?= (int) $r['evaluation_count'] ?><?= (int) ($r['attended_count'] ?? 0) > 0 ? ' <span class="admin-table-sub">(' . htmlspecialchars(format_percent((int) $r['evaluation_count'] / max(1, (int) $r['attended_count']))) . ')</span>' : '' ?></td>
                  <td><?= $r['average_rating'] !== null ? htmlspecialchars(format_rating((float) $r['average_rating']) . ' · ' . rating_interpretation((float) $r['average_rating'])) : '—' ?></td>
                  <td><a href="event-evaluations.php?event=<?= urlencode($r['id'] ?? $r['event_id'] ?? '') ?>" class="btn-sm">See results</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel" id="preview" style="margin-top:24px">
      <div class="admin-panel-head">
        <div>
          <h2>Preview</h2>
          <p>What attendees see after the event (<?= count($activeQuestions) ?> question<?= count($activeQuestions) === 1 ? '' : 's' ?>; hidden ones are left out).</p>
        </div>
      </div>
      <?php if (empty($activeQuestions)): ?>
        <p class="admin-muted">No questions are shown on the form yet.</p>
      <?php else: ?>
        <?php $preview = true; $questions = $activeQuestions; include __DIR__ . '/../../includes/evaluation-fields.php'; ?>
      <?php endif; ?>
    </section>
  </main>

</div>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
