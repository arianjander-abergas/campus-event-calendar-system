<?php
/** Admin: create / edit / publish / cancel / delete events. */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin(); // non-admins are sent to the dashboard

$categories = get_categories();
$organizations = get_organizations();

$editId = get_param('edit');
$editEvent = is_uuid($editId) ? get_event_admin($editId) : null;
if ($editId !== '' && !$editEvent) {
    flash('error', "That event doesn't exist anymore.");
    header('Location: event-organizer.php');
    exit;
}
$errors = [];

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete') {
    $result = delete_event_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: event-organizer.php');
    exit;
}

// ---- Status-only changes (Cancel / Mark Completed / Reopen as Draft) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_status') {
    $result = set_event_status_admin(post_param('id'), post_param('status'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: event-organizer.php');
    exit;
}

// ---- Create / update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save') {
    $existingId = post_param('id');
    $previous = is_uuid($existingId) ? get_event_admin($existingId) : null;
    $intent = post_param('intent') === 'publish' ? 'publish' : 'draft';
    $sendNotification = isset($_POST['send_notification']);

    $onlineLink = post_param('online_event_link');
    $registrationLimit = post_param('registration_limit');
    $eventType = post_param('event_type', 'other');

    // Collect everything typed FIRST, so a failed save re-shows the form filled in.
    $fields = [
        'title' => post_param('title'),
        'category_id' => post_param('category_id'),
        'organization_id' => post_param('organization_id'),
        'event_type' => isset(EVENT_TYPES[$eventType]) ? $eventType : 'other',
        'description' => post_param('description'),
        'start_date' => post_param('start_date'),
        'start_time' => post_param('start_time') ?: null,
        'end_date' => post_param('end_date'),
        'end_time' => post_param('end_time') ?: null,
        'venue' => post_param('venue') ?: null,
        'is_online' => $onlineLink !== '',
        'online_event_link' => $onlineLink ?: null,
        'address' => post_param('address') ?: null,
        'poster_url' => post_param('poster_url') ?: null,
        'registration_limit' => $registrationLimit !== '' ? (int) $registrationLimit : null,
        'registration_deadline' => post_param('registration_deadline') ?: null,
        'contact_email' => post_param('contact_email') ?: null,
        'contact_number' => post_param('contact_number') ?: null,
        'enable_registration' => isset($_POST['enable_registration']),
        'send_notification' => $sendNotification,
        'is_published' => $intent === 'publish',
        'status' => $intent === 'publish' ? 'published' : 'draft',
    ];

    if ($fields['title'] === '') $errors[] = 'Event title is required.';
    if (!in_array($fields['category_id'], array_column($categories, 'id'), true)) $errors[] = 'Please choose a category.';
    if (!in_array($fields['organization_id'], array_column($organizations, 'id'), true)) $errors[] = 'Please choose an organization.';
    if ($fields['description'] === '') $errors[] = 'Description is required.';
    if (mb_strlen($fields['description']) > 2000) $errors[] = 'Description must be 2000 characters or fewer.';
    if ($fields['start_date'] === '') $errors[] = 'Start date is required.';
    if ($fields['end_date'] === '') $errors[] = 'End date is required.';
    if ($fields['start_date'] !== '' && $fields['end_date'] !== '' && $fields['end_date'] < $fields['start_date']) {
        $errors[] = "End date can't be before the start date.";
    }
    if ($fields['start_date'] === $fields['end_date'] && $fields['start_time'] && $fields['end_time'] && $fields['end_time'] <= $fields['start_time']) {
        $errors[] = 'End time must be after the start time.';
    }
    if (!$fields['venue'] && !$onlineLink) $errors[] = 'Provide a venue or an online event link.';
    if ($onlineLink !== '' && !preg_match('~^https?://~i', $onlineLink)) $errors[] = 'The online event link must start with http:// or https://.';
    if ($registrationLimit !== '' && (int) $registrationLimit < 1) $errors[] = 'Registration limit must be at least 1 (or leave it empty for no limit).';
    if ($fields['registration_deadline'] && $fields['end_date'] !== '' && $fields['registration_deadline'] > $fields['end_date']) {
        $errors[] = 'The registration deadline must be on or before the end date.';
    }
    if ($fields['contact_email'] && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Contact email is not a valid email address.';

    // Poster: an uploaded image wins over a pasted URL.
    if (!$errors) {
        $upload = upload_public_image('event-posters', $_FILES['poster_file'] ?? [], date('Y'));
        if ($upload['ok']) {
            $fields['poster_url'] = $upload['url'];
        } elseif (!empty($upload['message'])) {
            $errors[] = $upload['message'];
        }
    }

    if (!$errors) {
        $result = $previous ? update_event_admin($previous['id'], $fields) : create_event_admin($fields);
        if ($result['ok']) {
            $eventId = $previous['id'] ?? $result['event']['id'];
            $message = $previous ? 'Event updated.' : 'Event created.';

            // "Send Notification to Users": a newly published event goes to
            // everyone; changes to an already-published one go to registrants.
            if ($fields['status'] === 'published' && $sendNotification) {
                $wasPublished = ($previous['status'] ?? '') === 'published';
                $where = event_when($fields) . ' · ' . event_place($fields);
                $sent = $wasPublished
                    ? notify_event($eventId, 'registered', 'Event updated: ' . $fields['title'], 'Details changed — ' . $where . '. Check the event page for the latest info.')
                    : notify_event($eventId, 'all', 'New event: ' . $fields['title'], $where);
                if ($sent >= 0) {
                    $message .= ' ' . $sent . ' ' . ($sent === 1 ? 'person was' : 'people were') . ' notified.';
                } else {
                    $message .= ' (Notifications could not be sent.)';
                }
            }
            flash('success', $message);
            header('Location: event-organizer.php');
            exit;
        }
        $errors[] = $result['message'];
    }

    // Fall through: re-show the form with what was typed + the errors.
    $editEvent = $fields + ['id' => $previous['id'] ?? null];
    $editId = $previous['id'] ?? '';
}

$events = get_all_events_admin();
$counts = get_registration_counts();

// Values to pre-fill the form with (event being edited, or a failed submission).
$f = $editEvent ?? [];
$val = fn(string $key, $default = '') => htmlspecialchars((string) ($f[$key] ?? $default));
$time = fn(string $key) => htmlspecialchars(substr((string) ($f[$key] ?? ''), 0, 5)); // "13:30:00" -> "13:30"
$checked = fn(string $key) => !empty($f[$key]) ? 'checked' : '';

$activeNav = 'admin-events';
$breadcrumbs = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['Manage Events', $editId ? 'event-organizer.php' : null]];
if ($editId) $breadcrumbs[] = ['Edit Event', null];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editId ? 'Edit Event' : 'Manage Events' ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title"><?= $editId ? 'Edit Event' : 'Create New Event' ?></h1>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <form method="POST" action="event-organizer.php<?= $editId ? '?edit=' . urlencode($editId) : '' ?>" class="admin-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <?php if ($editId): ?><input type="hidden" name="id" value="<?= htmlspecialchars($editId) ?>"><?php endif; ?>

      <div class="admin-card">
        <h2 class="admin-card-title">Event Details</h2>

        <label class="admin-label" for="title">Event Title <span class="req">*</span></label>
        <input type="text" id="title" name="title" class="admin-input" placeholder="Enter event title" value="<?= $val('title') ?>" required>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="category_id">Category <span class="req">*</span></label>
            <select id="category_id" name="category_id" class="admin-input" required>
              <option value="">Select a category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c['id']) ?>" <?= ($f['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($categories)): ?>
              <p class="admin-hint">No categories yet — <a href="admin-taxonomy.php">add one</a> first.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="admin-label" for="organization_id">Organization <span class="req">*</span></label>
            <select id="organization_id" name="organization_id" class="admin-input" required>
              <option value="">Select an organization</option>
              <?php foreach ($organizations as $o): ?>
                <option value="<?= htmlspecialchars($o['id']) ?>" <?= ($f['organization_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($organizations)): ?>
              <p class="admin-hint">No organizations yet — <a href="admin-taxonomy.php?tab=organizations">add one</a> first.</p>
            <?php endif; ?>
          </div>
        </div>

        <span class="admin-label">Event Type</span>
        <div class="admin-radio-row">
          <?php foreach (EVENT_TYPES as $typeKey => $label): ?>
            <label class="admin-radio"><input type="radio" name="event_type" value="<?= $typeKey ?>" <?= ($f['event_type'] ?? 'other') === $typeKey ? 'checked' : '' ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>

        <label class="admin-label" for="description">Description <span class="req">*</span></label>
        <textarea id="description" name="description" class="admin-input" rows="5" maxlength="2000" placeholder="Write event description here…"><?= $val('description') ?></textarea>

        <div class="admin-section-label">Date, Time &amp; Venue</div>
        <div class="admin-four-col">
          <div>
            <label class="admin-label" for="start_date">Start Date <span class="req">*</span></label>
            <input type="date" id="start_date" name="start_date" class="admin-input" value="<?= $val('start_date') ?>" required>
          </div>
          <div>
            <label class="admin-label" for="start_time">Start Time</label>
            <input type="time" id="start_time" name="start_time" class="admin-input" value="<?= $time('start_time') ?>">
          </div>
          <div>
            <label class="admin-label" for="end_date">End Date <span class="req">*</span></label>
            <input type="date" id="end_date" name="end_date" class="admin-input" value="<?= $val('end_date') ?>" required>
          </div>
          <div>
            <label class="admin-label" for="end_time">End Time</label>
            <input type="time" id="end_time" name="end_time" class="admin-input" value="<?= $time('end_time') ?>">
          </div>
        </div>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="venue">Venue</label>
            <input type="text" id="venue" name="venue" class="admin-input" placeholder="e.g. AVR 1, CITE Building" value="<?= $val('venue') ?>">
          </div>
          <div>
            <label class="admin-label" for="online_event_link">Online Event Link</label>
            <input type="url" id="online_event_link" name="online_event_link" class="admin-input" placeholder="https://…" value="<?= $val('online_event_link') ?>">
          </div>
        </div>
        <p class="admin-hint">Provide a venue, an online link, or both.</p>

        <label class="admin-label" for="address">Address (if applicable)</label>
        <input type="text" id="address" name="address" class="admin-input" placeholder="Enter full address" value="<?= $val('address') ?>">

        <div class="admin-checkbox-row">
          <label class="admin-checkbox"><input type="checkbox" name="enable_registration" <?= $checked('enable_registration') ?>> Enable Registration</label>
          <label class="admin-checkbox"><input type="checkbox" name="send_notification" <?= $checked('send_notification') ?>> Send Notification to Users</label>
        </div>
        <p class="admin-hint">With "Send Notification" on: publishing a new event notifies every user; saving changes to a published event notifies the people registered for it.</p>
      </div>

      <div class="admin-side-cards">
        <div class="admin-card">
          <h2 class="admin-card-title">Event Poster</h2>
          <?php if (!empty($f['poster_url'])): ?>
            <img src="<?= htmlspecialchars(asset_url($f['poster_url'])) ?>" alt="Current poster" class="admin-image-preview">
          <?php endif; ?>
          <label class="admin-label" for="poster_file">Upload an image</label>
          <input type="file" id="poster_file" name="poster_file" class="admin-input" accept="image/jpeg,image/png,image/webp,image/gif">
          <p class="admin-hint">JPG, PNG, WEBP or GIF, up to 5 MB. Replaces the current poster.</p>
          <label class="admin-label" for="poster_url">…or paste an image URL</label>
          <input type="text" id="poster_url" name="poster_url" class="admin-input" placeholder="https://…" value="<?= $val('poster_url') ?>">
        </div>

        <div class="admin-card">
          <h2 class="admin-card-title">Registration &amp; Contact</h2>
          <label class="admin-label" for="registration_limit">Registration Limit</label>
          <input type="number" min="1" id="registration_limit" name="registration_limit" class="admin-input" placeholder="Leave empty for no limit" value="<?= $val('registration_limit') ?>">

          <label class="admin-label" for="registration_deadline">Registration Deadline</label>
          <input type="date" id="registration_deadline" name="registration_deadline" class="admin-input" value="<?= $val('registration_deadline') ?>">

          <label class="admin-label" for="contact_email">Contact Email</label>
          <input type="email" id="contact_email" name="contact_email" class="admin-input" placeholder="Enter email" value="<?= $val('contact_email') ?>">

          <label class="admin-label" for="contact_number">Contact Number</label>
          <input type="text" id="contact_number" name="contact_number" class="admin-input" placeholder="Enter no." value="<?= $val('contact_number') ?>">
        </div>

        <?php if ($editId): ?>
          <div class="admin-card admin-card-links">
            <h2 class="admin-card-title">More for this event</h2>
            <a href="event-programs.php?event=<?= urlencode($editId) ?>">🗓️ Edit the schedule (programs)</a>
            <a href="event-registrations.php?event=<?= urlencode($editId) ?>">👥 See registrations &amp; attendance</a>
            <a href="../event-details.php?id=<?= urlencode($editId) ?>">👁️ View the event page</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="admin-actions">
        <a href="event-organizer.php" class="btn btn-outline">Cancel</a>
        <button type="submit" name="intent" value="draft" class="btn btn-muted">Save Draft</button>
        <button type="submit" name="intent" value="publish" class="btn btn-primary"><?= $editId ? 'Save &amp; Publish' : 'Publish Event' ?></button>
      </div>
    </form>

    <!-- Manage existing events -->
    <div class="section-head section-head-spaced">
      <h2>All Events (<?= count($events) ?>)</h2>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Organization</th>
            <th>Date</th>
            <th>Going</th>
            <th>Status</th>
            <th><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
            <tr><td colspan="6" class="admin-table-empty">No events yet — create your first one above.</td></tr>
          <?php endif; ?>
          <?php foreach ($events as $ev):
            $status = $ev['status'] ?? 'draft';
            $programCount = (int) ($ev['event_programs'][0]['count'] ?? 0);
          ?>
            <tr>
              <td>
                <a href="../event-details.php?id=<?= urlencode($ev['id']) ?>" class="admin-table-title"><?= htmlspecialchars($ev['title']) ?></a>
                <div class="admin-table-sub"><?= htmlspecialchars($ev['categories']['name'] ?? '—') ?></div>
              </td>
              <td><?= htmlspecialchars($ev['organizations']['name'] ?? '—') ?></td>
              <td><?= htmlspecialchars(format_event_date($ev['start_date'] ?? null)) ?></td>
              <td><?= (int) ($counts[$ev['id']] ?? 0) ?><?= !empty($ev['registration_limit']) ? ' / ' . (int) $ev['registration_limit'] : '' ?></td>
              <td><span class="admin-status admin-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
              <td class="admin-row-actions">
                <a href="event-organizer.php?edit=<?= urlencode($ev['id']) ?>" class="btn-sm">Edit</a>
                <a href="event-programs.php?event=<?= urlencode($ev['id']) ?>" class="btn-sm">Schedule (<?= $programCount ?>)</a>
                <a href="event-registrations.php?event=<?= urlencode($ev['id']) ?>" class="btn-sm">Registrations</a>

                <?php if (in_array($status, ['draft', 'published'], true)): ?>
                  <form method="POST" action="event-organizer.php" onsubmit="return confirm('Cancel this event? Everyone registered will be notified.');">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="cancelled">
                    <button type="submit" class="btn-sm btn-sm-danger">Cancel</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'published'): ?>
                  <form method="POST" action="event-organizer.php">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" class="btn-sm btn-sm-accent">Mark Completed</button>
                  </form>
                <?php endif; ?>

                <?php if (in_array($status, ['cancelled', 'completed'], true)): ?>
                  <form method="POST" action="event-organizer.php">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="draft">
                    <button type="submit" class="btn-sm">Reopen as Draft</button>
                  </form>
                <?php endif; ?>

                <form method="POST" action="event-organizer.php" onsubmit="return confirm('Delete this event? Its registrations and schedule are deleted too. This cannot be undone.');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                  <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
