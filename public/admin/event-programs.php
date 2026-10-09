<?php
/** Admins and the event's moderators: an event's schedule (event_programs) — add, edit, reorder, delete. */
require_once __DIR__ . '/../../config/supabase.php';

$eventId = get_param('event');
[$user, $event] = require_event_manager($eventId); // admins, or moderators of the event's organization
$back = 'event-programs.php?event=' . urlencode($eventId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    $programId = post_param('id');

    if ($action === 'save') {
        $fields = [
            'title' => post_param('title'),
            'speaker' => post_param('speaker') ?: null,
            'description' => post_param('description') ?: null,
        ];
        if ($fields['title'] === '') {
            $errors[] = 'Program title is required.';
        } else {
            $result = is_uuid($programId) ? update_program_admin($programId, $fields) : create_program_admin($eventId, $fields);
            if ($result['ok']) {
                flash('success', $result['message']);
                header('Location: ' . $back);
                exit;
            }
            $errors[] = $result['message'];
        }
    } elseif ($action === 'delete' && is_uuid($programId)) {
        $result = delete_program_admin($programId);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . $back);
        exit;
    } elseif (in_array($action, ['up', 'down'], true) && is_uuid($programId)) {
        move_program_admin($eventId, $programId, $action === 'up' ? -1 : 1);
        header('Location: ' . $back . '#program-' . urlencode($programId));
        exit;
    }
}

$programs = get_event_programs($eventId);
$editId = get_param('edit');
$editing = null;
foreach ($programs as $p) {
    if ($p['id'] === $editId) $editing = $p;
}
if ($errors) {
    // Re-show what was typed.
    $editing = ['id' => post_param('id'), 'title' => post_param('title'), 'speaker' => post_param('speaker'), 'description' => post_param('description')];
}
$val = fn(string $key) => htmlspecialchars((string) ($editing[$key] ?? ''));

$activeNav = 'manage-events';
$breadcrumbs = [['Manage Events', 'event-organizer.php'], [$event['title'], 'event-organizer.php?edit=' . urlencode($eventId)], ['Schedule', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedule: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
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
        <h1 class="page-title">Schedule</h1>
        <p class="page-sub"><?= htmlspecialchars($event['title']) ?> · <?= htmlspecialchars(event_when($event)) ?></p>
      </div>
      <a href="../event-details.php?id=<?= urlencode($eventId) ?>" class="btn btn-outline">View event page</a>
    </div>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-split">
      <form method="POST" action="<?= htmlspecialchars($back) ?>" class="admin-card" data-autoreset>
        <input type="hidden" name="action" value="save">
        <?php if (!empty($editing['id'])): ?><input type="hidden" name="id" value="<?= $val('id') ?>"><?php endif; ?>
        <h2 class="admin-card-title"><?= !empty($editing['id']) ? 'Edit program' : 'Add a program' ?></h2>

        <label class="admin-label" for="title">Title <span class="req">*</span></label>
        <input type="text" id="title" name="title" class="admin-input" placeholder="e.g. Opening Prayer, Keynote Talk, Awarding" value="<?= $val('title') ?>" required>

        <label class="admin-label" for="speaker">Speaker / In charge</label>
        <input type="text" id="speaker" name="speaker" class="admin-input" placeholder="Optional" value="<?= $val('speaker') ?>">

        <label class="admin-label" for="description">Description</label>
        <textarea id="description" name="description" class="admin-input" rows="3" placeholder="Optional — shown when hovering the item"><?= $val('description') ?></textarea>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary"><?= !empty($editing['id']) ? 'Save changes' : 'Add program' ?></button>
          <?php if (!empty($editing['id'])): ?><a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
        <p class="admin-hint">New programs are added at the end. Use ↑ ↓ to change the running order.</p>
      </form>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr><th>#</th><th>Program</th><th>Speaker</th><th><span class="visually-hidden">Actions</span></th></tr>
          </thead>
          <tbody>
            <?php if (empty($programs)): ?>
              <tr><td colspan="4" class="admin-table-empty">No programs yet — add the first one (e.g. Registration, Opening Prayer…).</td></tr>
            <?php endif; ?>
            <?php foreach ($programs as $i => $p): ?>
              <tr id="program-<?= htmlspecialchars($p['id']) ?>">
                <td><?= $i + 1 ?></td>
                <td>
                  <strong><?= htmlspecialchars($p['title']) ?></strong>
                  <?php if (!empty($p['description'])): ?><div class="admin-table-sub"><?= htmlspecialchars(excerpt($p['description'], 80)) ?></div><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['speaker'] ?? '—') ?></td>
                <td class="admin-row-actions">
                  <form method="POST" action="<?= htmlspecialchars($back) ?>">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <button type="submit" name="action" value="up" class="btn-sm" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up">↑</button>
                    <button type="submit" name="action" value="down" class="btn-sm" <?= $i === count($programs) - 1 ? 'disabled' : '' ?> aria-label="Move down">↓</button>
                  </form>
                  <a href="<?= htmlspecialchars($back) ?>&amp;edit=<?= urlencode($p['id']) ?>" class="btn-sm">Edit</a>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>" onsubmit="return confirm('Delete this program?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/forms.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
