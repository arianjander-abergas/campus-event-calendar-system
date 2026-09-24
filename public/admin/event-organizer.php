<?php
require_once __DIR__ . '/config/supabase.php';

$user = require_admin(); // redirects non-admins to dashboard.php

$categories = get_categories();
$organizations = get_organizations();

$editId = $_GET['edit'] ?? null;
$editEvent = $editId ? get_event_admin($editId) : null;

$errors = [];
$flashCreated = isset($_GET['created']);
$flashUpdated = isset($_GET['updated']);
$flashDeleted = isset($_GET['deleted']);
$flashStatusUpdated = isset($_GET['status_updated']);
$flashError = isset($_GET['error']);

// ---- Handle delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    delete_event_admin($_POST['id'] ?? '');
    header('Location: event-organizer.php?deleted=1');
    exit;
}

// ---- Handle status-only changes (Cancel / Mark Completed / Reopen / Unpublish) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $result = set_event_status_admin($_POST['id'] ?? '', $_POST['status'] ?? '');
    header('Location: event-organizer.php?' . ($result['ok'] ? 'status_updated=1' : 'error=1'));
    exit;
}

// ---- Handle create / update (PRG pattern) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $title = trim($_POST['title'] ?? '');
    $categoryId = trim($_POST['category_id'] ?? '');
    $organizationId = trim($_POST['organization_id'] ?? '');
    $eventType = trim($_POST['event_type'] ?? 'other');
    $description = trim($_POST['description'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $onlineLink = trim($_POST['online_event_link'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $posterUrl = trim($_POST['poster_url'] ?? '');
    $registrationLimit = trim($_POST['registration_limit'] ?? '');
    $registrationDeadline = trim($_POST['registration_deadline'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $enableRegistration = isset($_POST['enable_registration']);
    $sendNotification = isset($_POST['send_notification']);
    $intent = $_POST['intent'] ?? 'draft'; // 'draft' or 'publish' — which button was clicked
    $existingId = $_POST['id'] ?? null;

    if ($title === '') $errors[] = 'Event title is required.';
    if ($categoryId === '') $errors[] = 'Category is required.';
    if ($organizationId === '') $errors[] = 'Organization is required.';
    if ($description === '') $errors[] = 'Description is required.';
    if (strlen($description) > 2000) $errors[] = 'Description must be 2000 characters or fewer.';
    if ($startDate === '') $errors[] = 'Start date is required.';
    if ($endDate === '') $errors[] = 'End date is required.';
    if ($venue === '' && $onlineLink === '') $errors[] = 'Provide a venue or an online event link.';

    if (empty($errors)) {
        $fields = [
            'title' => $title,
            'category_id' => $categoryId,
            'organization_id' => $organizationId,
            'event_type' => $eventType,
            'description' => $description,
            'start_date' => $startDate,
            'start_time' => $startTime !== '' ? $startTime : null,
            'end_date' => $endDate,
            'end_time' => $endTime !== '' ? $endTime : null,
            'venue' => $venue !== '' ? $venue : null,
            'is_online' => $onlineLink !== '',
            'online_event_link' => $onlineLink !== '' ? $onlineLink : null,
            'address' => $address !== '' ? $address : null,
            'poster_url' => $posterUrl !== '' ? $posterUrl : null,
            'registration_limit' => $registrationLimit !== '' ? (int)$registrationLimit : null,
            'registration_deadline' => $registrationDeadline !== '' ? $registrationDeadline : null,
            'contact_email' => $contactEmail !== '' ? $contactEmail : null,
            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
            'enable_registration' => $enableRegistration,
            'send_notification' => $sendNotification,
            'is_published' => $intent === 'publish',
            'status' => $intent === 'publish' ? 'published' : 'draft',
        ];

        if ($existingId) {
            $result = update_event_admin($existingId, $fields);
            if ($result['ok']) {
                header('Location: event-organizer.php?updated=1');
                exit;
            }
        } else {
            $result = create_event_admin($fields);
            if ($result['ok']) {
                header('Location: event-organizer.php?created=1');
                exit;
            }
        }
        $errors[] = $result['message'] ?? 'Something went wrong saving the event.';
    }

    // Fall through to re-render the form with entered values + errors.
    $editEvent = $fields ?? [];
    $editEvent['id'] = $existingId;
    $editId = $existingId;
}

$events = get_all_events_admin();

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= substr($p, 0, 1); }
    return strtoupper($out) ?: 'U';
}

// Values to pre-fill the form with (either an event being edited, or a
// failed submission being redisplayed).
$f = $editEvent ?? [];
$val = fn(string $key, $default = '') => htmlspecialchars((string)($f[$key] ?? $default));
$checked = fn(string $key) => !empty($f[$key]) ? 'checked' : '';
$eventTypeChecked = fn(string $type) => (($f['event_type'] ?? '') === $type) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editId ? 'Edit Event' : 'Add New Event' ?> — Campus Event Hub</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/admin.css">
</head>
<body>

<div class="dashboard-shell">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-top">
      <a href="index.php" class="brand">
        <span class="brand-icon">📅</span>
        <span class="brand-text">
          <span class="campus">CAMPUS</span>
          <span class="hub">EVENT HUB</span>
        </span>
      </a>
      <button class="sidebar-toggle" aria-label="Toggle sidebar">☰</button>
    </div>

    <nav class="side-nav">
      <a href="index.php"><span class="icon">🏠</span> Home</a>
      <a href="dashboard.php"><span class="icon">🗂️</span> Dashboard</a>
      <a href="#event"><span class="icon">🎟️</span> Event <span style="margin-left:auto;">⌄</span></a>
    </nav>

    <div style="margin-top:auto; display:flex; flex-direction:column; gap:8px;">
      <a href="event-organizer.php" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:8px; font-weight:600; font-size:0.9rem; color:#fff; background:var(--orange);">
        <span class="icon">🛠️</span> Admin View
      </a>
      <a href="admin-taxonomy.php" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:8px; font-weight:600; font-size:0.9rem; color:#cdd8ef; background:rgba(255,255,255,0.08);">
        <span class="icon">🏷️</span> Categories &amp; Orgs
      </a>
    </div>
  </aside>

  <!-- Top bar -->
  <header class="dash-topbar">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <span class="crumb-sep">›</span>
      <span>Events</span>
      <span class="crumb-sep">›</span>
      <span class="crumb-current"><?= $editId ? 'Edit Event' : 'Add New Event' ?></span>
    </nav>
    <div class="topbar-right">
      <div class="user-chip">
        <div class="avatar"><?= htmlspecialchars(initials($user['full_name'])) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="user-role"><?= htmlspecialchars(role_display($user['role'])) ?></div>
        </div>
        <a href="logout.php" style="margin-left:8px; font-size:0.78rem; color:var(--text-muted);">Log out</a>
      </div>
    </div>
  </header>

  <!-- Main content -->
  <main class="dash-main">
    <h1 style="margin:0 0 18px; font-size:1.4rem;"><?= $editId ? 'Edit Event' : 'Create New Event' ?></h1>

    <?php if ($flashCreated): ?><div class="auth-success">Event created.</div><?php endif; ?>
    <?php if ($flashUpdated): ?><div class="auth-success">Event updated.</div><?php endif; ?>
    <?php if ($flashDeleted): ?><div class="auth-success">Event deleted.</div><?php endif; ?>
    <?php if ($flashStatusUpdated): ?><div class="auth-success">Event status updated.</div><?php endif; ?>
    <?php if ($flashError): ?><div class="auth-alert">Something went wrong. Please try again.</div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="auth-alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <form method="POST" action="event-organizer.php" class="admin-form">
      <input type="hidden" name="action" value="save">
      <?php if ($editId): ?><input type="hidden" name="id" value="<?= htmlspecialchars((string)$editId) ?>"><?php endif; ?>

      <div class="admin-card">
        <h2 class="admin-card-title">Event Details</h2>

        <label class="admin-label">Event Title <span class="req">*</span></label>
        <input type="text" name="title" class="admin-input" placeholder="Enter event title" value="<?= $val('title') ?>" required>

        <div class="admin-two-col">
          <div>
            <label class="admin-label">Category <span class="req">*</span></label>
            <select name="category_id" class="admin-input" required>
              <option value="">Select a category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c['id']) ?>" <?= ($f['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($categories)): ?>
              <p class="admin-hint">No categories yet — <a href="admin-taxonomy.php">add one</a> before publishing.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="admin-label">Organization <span class="req">*</span></label>
            <select name="organization_id" class="admin-input" required>
              <option value="">Select an organization</option>
              <?php foreach ($organizations as $o): ?>
                <option value="<?= htmlspecialchars($o['id']) ?>" <?= ($f['organization_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($organizations)): ?>
              <p class="admin-hint">No organizations yet — <a href="admin-taxonomy.php">add one</a> before publishing.</p>
            <?php endif; ?>
          </div>
        </div>

        <label class="admin-label">Event Type</label>
        <div class="admin-radio-row">
          <?php foreach (['academic' => 'Academic', 'seminar' => 'Seminar', 'workshop' => 'Workshop', 'sports' => 'Sports', 'cultural' => 'Cultural', 'other' => 'Other'] as $val_key => $label): ?>
            <label class="admin-radio"><input type="radio" name="event_type" value="<?= $val_key ?>" <?= $eventTypeChecked($val_key) ?: ($val_key === 'other' && empty($f['event_type']) ? 'checked' : '') ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>

        <label class="admin-label">Description <span class="req">*</span></label>
        <textarea name="description" class="admin-input" rows="5" maxlength="2000" placeholder="Write event description here…"><?= $val('description') ?></textarea>

        <div class="admin-section-label">Date, Time &amp; Venue</div>
        <div class="admin-four-col">
          <div>
            <label class="admin-label">Start Date <span class="req">*</span></label>
            <input type="date" name="start_date" class="admin-input" value="<?= $val('start_date') ?>" required>
          </div>
          <div>
            <label class="admin-label">Start Time</label>
            <input type="time" name="start_time" class="admin-input" value="<?= $val('start_time') ?>">
          </div>
          <div>
            <label class="admin-label">End Date <span class="req">*</span></label>
            <input type="date" name="end_date" class="admin-input" value="<?= $val('end_date') ?>" required>
          </div>
          <div>
            <label class="admin-label">End Time</label>
            <input type="time" name="end_time" class="admin-input" value="<?= $val('end_time') ?>">
          </div>
        </div>

        <div class="admin-two-col">
          <div>
            <label class="admin-label">Venue</label>
            <input type="text" name="venue" class="admin-input" placeholder="Enter venue" value="<?= $val('venue') ?>">
          </div>
          <div>
            <label class="admin-label">Online Event Link</label>
            <input type="text" name="online_event_link" class="admin-input" placeholder="https://…" value="<?= $val('online_event_link') ?>">
          </div>
        </div>
        <p class="admin-hint">Provide a venue, an online link, or both.</p>

        <label class="admin-label">Address (if applicable)</label>
        <input type="text" name="address" class="admin-input" placeholder="Enter full address" value="<?= $val('address') ?>">

        <div class="admin-checkbox-row">
          <label class="admin-checkbox"><input type="checkbox" name="enable_registration" <?= $checked('enable_registration') ?>> Enable Registration</label>
          <label class="admin-checkbox"><input type="checkbox" name="send_notification" <?= $checked('send_notification') ?>> Send Notification to Users</label>
        </div>
      </div>

      <div class="admin-side-cards">
        <div class="admin-card">
          <h2 class="admin-card-title">Event Poster</h2>
          <label class="admin-label">Poster Image URL</label>
          <input type="text" name="poster_url" class="admin-input" placeholder="assets/your-poster.jpg or https://…" value="<?= $val('poster_url') ?>">
          <p class="admin-hint">Direct file upload isn't wired up yet — paste a URL or a path under <code>assets/</code> for now.</p>
        </div>

        <div class="admin-card">
          <h2 class="admin-card-title">Additional Details</h2>
          <label class="admin-label">Registration Limit</label>
          <input type="number" min="1" name="registration_limit" class="admin-input" placeholder="Enter limit (e.g., 100)" value="<?= $val('registration_limit') ?>">

          <label class="admin-label">Registration Deadline</label>
          <input type="date" name="registration_deadline" class="admin-input" value="<?= $val('registration_deadline') ?>">

          <label class="admin-label">Contact Email</label>
          <input type="email" name="contact_email" class="admin-input" placeholder="Enter email" value="<?= $val('contact_email') ?>">

          <label class="admin-label">Contact Number</label>
          <input type="text" name="contact_number" class="admin-input" placeholder="Enter no." value="<?= $val('contact_number') ?>">
        </div>
      </div>

      <div class="admin-actions">
        <a href="event-organizer.php" class="btn btn-outline">Cancel</a>
        <button type="submit" name="intent" value="draft" class="btn" style="background:#e5e7eb; color:var(--text-main);">Save Draft</button>
        <button type="submit" name="intent" value="publish" class="btn btn-primary"><?= $editId ? 'Save & Publish' : 'Publish Event' ?></button>
      </div>
    </form>

    <!-- Manage existing events -->
    <div class="section-head" style="margin-top:36px;">
      <h2>All Events</h2>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Category</th>
            <th>Organization</th>
            <th>Start Date</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
            <tr><td colspan="6" style="color:var(--text-muted); text-align:center; padding:24px;">No events yet — create your first one above.</td></tr>
          <?php endif; ?>
          <?php foreach ($events as $ev):
            $status = $ev['status'] ?? 'draft';
          ?>
            <tr>
              <td><?= htmlspecialchars($ev['title']) ?></td>
              <td><?= htmlspecialchars($ev['categories']['name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($ev['organizations']['name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($ev['start_date'] ?? '—') ?></td>
              <td><span class="admin-status admin-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
              <td class="admin-row-actions">
                <a href="event-organizer.php?edit=<?= urlencode($ev['id']) ?>" class="btn-outline" style="padding:6px 14px; border-radius:6px; font-size:0.78rem;">Edit</a>

                <?php if (in_array($status, ['draft', 'published'], true)): ?>
                  <form method="POST" action="event-organizer.php" onsubmit="return confirm('Cancel this event? Registered users won\'t be automatically notified.');" style="display:inline;">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="cancelled">
                    <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--red); color:var(--red); background:transparent;">Cancel</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'published'): ?>
                  <form method="POST" action="event-organizer.php" style="display:inline;">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="completed">
                    <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--blue-accent); color:var(--blue-accent); background:transparent;">Mark Completed</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'cancelled'): ?>
                  <form method="POST" action="event-organizer.php" style="display:inline;">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="status" value="draft">
                    <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--text-muted); color:var(--text-muted); background:transparent;">Reopen as Draft</button>
                  </form>
                <?php endif; ?>

                <form method="POST" action="event-organizer.php" onsubmit="return confirm('Delete this event? This cannot be undone.');" style="display:inline;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                  <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--red); color:var(--red); background:transparent;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>

</div>

</body>
</html>
