<?php
/**
 * Manage Events: create / edit / publish / cancel events.
 * Admins manage every event (and can delete); moderators manage their own
 * organization's events. Events are refused if they duplicate another event
 * (same title, same date) or double-book a venue; they're marked Completed
 * automatically once they end.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_staff(); // admins + moderators; everyone else goes to the dashboard
$isAdmin = ($user['role'] ?? '') === 'admin';

$categories = get_categories();
$organizations = get_event_organizations_for($user);

$editId = get_param('edit');
$editEvent = is_uuid($editId) ? get_event_admin($editId) : null;
if ($editId !== '' && (!$editEvent || !can_manage_event($editEvent, $user))) {
    flash('error', $editEvent ? "Moderators can only edit their own organization's events." : "That event doesn't exist anymore.");
    header('Location: event-organizer.php');
    exit;
}
$errors = [];

// The event a status / delete button acts on, if this user may manage it.
$target = function (string $id) use ($user): ?array {
    $ev = is_uuid($id) ? get_event_admin($id) : null;
    return $ev && can_manage_event($ev, $user) ? $ev : null;
};

// ---- Delete (admins only; moderators cancel instead) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete') {
    $result = $target(post_param('id')) ? delete_event_admin(post_param('id')) : ['ok' => false, 'message' => "You can't delete that event."];
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: event-organizer.php');
    exit;
}

// ---- Status-only changes (Cancel / Mark Completed / Reopen as Draft) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_status') {
    $result = $target(post_param('id')) ? set_event_status_admin(post_param('id'), post_param('status')) : ['ok' => false, 'message' => "You can't change that event."];
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: event-organizer.php');
    exit;
}

// ---- Create / update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save') {
    $existingId = post_param('id');
    $previous = is_uuid($existingId) ? $target($existingId) : null;
    if ($existingId !== '' && !$previous) {
        flash('error', "You can't edit that event.");
        header('Location: event-organizer.php');
        exit;
    }
    $intent = post_param('intent') === 'publish' ? 'publish' : 'draft';
    $sendNotification = isset($_POST['send_notification']);

    $onlineLink = post_param('online_event_link');
    $registrationLimit = post_param('registration_limit');
    $eventType = post_param('event_type', 'other');
    $audience = post_param('audience', 'students');
    $audience = isset(EVENT_AUDIENCES[$audience]) ? $audience : 'students';
    $venueType = post_param('venue_type', 'on_campus');
    $venueType = isset(VENUE_TYPES[$venueType]) ? $venueType : 'on_campus';
    // Year levels that may register (none or all picked = every year level; not used for faculty-only events).
    $yearLevels = $audience === 'faculty' ? [] : parse_year_levels($_POST['year_levels'] ?? []);
    if (count($yearLevels) === count(YEAR_LEVELS)) $yearLevels = [];

    // Collect everything typed FIRST, so a failed save re-shows the form filled in.
    $fields = [
        'title' => post_param('title'),
        'category_id' => post_param('category_id'),
        'organization_id' => $isAdmin ? post_param('organization_id') : (string) $user['organization_id'],
        'event_type' => isset(EVENT_TYPES[$eventType]) ? $eventType : 'other',
        'audience' => $audience,
        'year_levels' => $yearLevels ?: null,
        'venue_type' => $venueType,
        'description' => post_param('description'),
        'start_date' => post_param('start_date'),
        'start_time' => post_param('start_time') ?: null,
        'end_date' => post_param('end_date'),
        'end_time' => post_param('end_time') ?: null,
        'venue' => post_param('venue') ?: null,
        'is_online' => $venueType === 'online',
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

    // Only send year levels when used (or being cleared), so saving still works
    // on a database that doesn't have the column yet.
    if (!$fields['year_levels'] && empty($previous['year_levels'])) unset($fields['year_levels']);

    if ($fields['title'] === '') $errors['title'] = 'Event title is required.';
    if (!in_array($fields['category_id'], array_column($categories, 'id'), true)) $errors['category_id'] = 'Please choose a category.';
    if (!in_array($fields['organization_id'], array_column($organizations, 'id'), true)) $errors['organization_id'] = 'Please choose an organization.';
    if ($fields['description'] === '') $errors['description'] = 'Description is required.';
    if (mb_strlen($fields['description']) > 2000) $errors['description'] = 'Description must be 2000 characters or fewer.';
    if ($fields['start_date'] === '') $errors['start_date'] = 'Start date is required.';
    if ($fields['end_date'] === '') $errors['end_date'] = 'End date is required.';
    if ($fields['start_date'] !== '' && $fields['end_date'] !== '' && $fields['end_date'] < $fields['start_date']) {
        $errors['end_date'] = "End date can't be before the start date.";
    }
    if ($fields['start_date'] === $fields['end_date'] && $fields['start_time'] && $fields['end_time'] && $fields['end_time'] <= $fields['start_time']) {
        $errors['end_time'] = 'End time must be after the start time.';
    }
    if ($venueType === 'online' && $onlineLink === '') $errors['online_event_link'] = 'Online events need the webinar / meeting link.';
    if ($venueType !== 'online' && !$fields['venue']) $errors['venue'] = 'On-campus and off-campus events need a venue.';
    if ($onlineLink !== '' && !preg_match('~^https?://~i', $onlineLink)) $errors['online_event_link'] = 'The online event link must start with http:// or https://.';
    if ($registrationLimit !== '' && (int) $registrationLimit < 1) $errors['registration_limit'] = 'Registration limit must be at least 1 (or leave it empty for no limit).';
    if ($fields['registration_deadline'] && $fields['end_date'] !== '' && $fields['registration_deadline'] > $fields['end_date']) {
        $errors['registration_deadline'] = 'The registration deadline must be on or before the end date.';
    }
    if ($fields['contact_email'] && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) $errors['contact_email'] = 'Contact email is not a valid email address.';

    // No duplicates: the same title on the same date, or a venue booked twice at once.
    // (The database refuses these too, even for events this user can't see.)
    if (!$errors) {
        $clash = find_schedule_conflicts($fields, $previous['id'] ?? null);
        if ($clash['duplicate']) {
            $errors['title'] = '"' . $clash['duplicate']['title'] . '" is already scheduled on ' . format_event_date($clash['duplicate']['start_date']) . '. Change the title or the date — the same event can\'t be entered twice.';
        }
        if ($clash['venue_conflict']) {
            $c = $clash['venue_conflict'];
            $errors['venue'] = $fields['venue'] . ' is already booked for "' . $c['title'] . '" (' . event_when($c) . '). Choose another venue, date or time.';
        }
    }

    // Poster: an uploaded image wins over a pasted URL.
    if (!$errors) {
        $upload = upload_public_image('event-posters', $_FILES['poster_file'] ?? [], date('Y'));
        if ($upload['ok']) {
            $fields['poster_url'] = $upload['url'];
        } elseif (!empty($upload['message'])) {
            $errors['poster_file'] = $upload['message'];
        }
    }

    if (!$errors) {
        $result = $previous ? update_event_admin($previous['id'], $fields) : create_event_admin($fields);
        if ($result['ok']) {
            $eventId = $previous['id'] ?? $result['event']['id'];
            $message = $previous ? 'Event updated.' : ($intent === 'publish' ? 'Event published.' : 'Draft saved.');

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
            // An event that's already over is marked Completed right away.
            if ($fields['status'] === 'published') {
                auto_complete_events(true);
            }
            // Published / saved: back to a clean form.
            flash('success', $message);
            header('Location: event-organizer.php');
            exit;
        }
        $errors[] = $result['message'];
    }

    // Show the form again with what was typed + the errors — once. The
    // redirect means refreshing the page doesn't post it again; refreshing
    // or leaving and coming back shows a clean form.
    if (!empty($_FILES['poster_file']['name'])) {
        $errors['poster_file'] = 'Choose the poster image again (files can\'t be kept after an error).';
    }
    remember_form('event', $fields + ['id' => $previous['id'] ?? null], $errors);
    header('Location: event-organizer.php' . ($previous ? '?edit=' . urlencode($previous['id']) : '') . '#event-form');
    exit;
}

// A form with errors from the save just above (shown once).
$state = recall_form('event');
if ($state && ($state['values']['id'] ?? '') === ($editEvent['id'] ?? '')) {
    $editEvent = $state['values'];
    $errors = $state['errors'];
}

$events = get_all_events_admin();
$counts = get_registration_counts();

// Values to pre-fill the form with (event being edited, or a failed submission).
$f = $editEvent ?? [];
if (!$isAdmin) $f['organization_id'] = $user['organization_id'];
$val = fn(string $key, $default = '') => htmlspecialchars((string) ($f[$key] ?? $default));
$time = fn(string $key) => htmlspecialchars(substr((string) ($f[$key] ?? ''), 0, 5)); // "13:30:00" -> "13:30"
$checked = fn(string $key) => !empty($f[$key]) ? 'checked' : '';
$formLevels = parse_year_levels($f['year_levels'] ?? []);
// Fields the last save rejected: red border + the reason under the field.
$bad = fn(string $key) => isset($errors[$key]) ? ' is-invalid" aria-invalid="true" aria-describedby="' . $key . '-error' : '';
$why = fn(string $key) => isset($errors[$key]) ? '<p class="field-error" id="' . $key . '-error">' . htmlspecialchars($errors[$key]) . '</p>' : '';
$fieldErrorCount = count(array_filter(array_keys($errors), 'is_string'));

$myOrgName = $organizations[0]['name'] ?? '';
$activeNav = 'manage-events';
$breadcrumbs = [$isAdmin ? ['Admin Dashboard', 'dashboard.php'] : ['Dashboard', '../enduser/dashboard.php'], ['Manage Events', $editId ? 'event-organizer.php' : null]];
if ($editId) $breadcrumbs[] = ['Edit Event', null];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editId ? 'Edit Event' : 'Manage Events' ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title"><?= $editId ? 'Edit Event' : 'Create New Event' ?></h1>
    <?php if (!$isAdmin): ?>
      <p class="admin-note">You're a <strong>moderator</strong> for <strong><?= htmlspecialchars($myOrgName) ?></strong>: you can create, publish, edit and cancel its events, and manage their schedules, registrations and attendance. Only admins can delete events.</p>
    <?php endif; ?>

    <?= render_flashes() ?>
    <?php if ($fieldErrorCount): ?>
      <div class="flash flash-error" role="alert">The event wasn't saved — <?= $fieldErrorCount === 1 ? '1 field needs' : $fieldErrorCount . ' fields need' ?> your attention (highlighted in red below).</div>
    <?php endif; ?>
    <?php foreach ($errors as $key => $err): if (is_string($key)) continue; ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
    <!-- Filled by the form check below when fields are missing -->
    <div class="flash flash-error" role="alert" data-form-summary hidden></div>

    <form method="POST" action="event-organizer.php<?= $editId ? '?edit=' . urlencode($editId) : '' ?>" class="admin-form" id="event-form" novalidate enctype="multipart/form-data" autocomplete="off" data-autoreset>
      <input type="hidden" name="action" value="save">
      <?php if ($editId): ?><input type="hidden" name="id" value="<?= htmlspecialchars($editId) ?>"><?php endif; ?>

      <div class="admin-card">
        <h2 class="admin-card-title">Event Details</h2>

        <label class="admin-label" for="title">Event Title <span class="req">*</span></label>
        <input type="text" id="title" name="title" class="admin-input<?= $bad('title') ?>" placeholder="Enter event title" value="<?= $val('title') ?>" required><?= $why('title') ?>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="category_id">Category <span class="req">*</span></label>
            <select id="category_id" name="category_id" class="admin-input<?= $bad('category_id') ?>" required>
              <option value="">Select a category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c['id']) ?>" <?= ($f['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select><?= $why('category_id') ?>
            <?php if (empty($categories)): ?>
              <p class="admin-hint">No categories yet — <a href="admin-taxonomy.php">add one</a> first.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="admin-label" for="organization_id">Organization <span class="req">*</span></label>
            <?php if (!$isAdmin): ?>
              <input type="hidden" name="organization_id" value="<?= htmlspecialchars((string) $user['organization_id']) ?>">
              <input type="text" id="organization_id" class="admin-input" value="<?= htmlspecialchars($myOrgName) ?>" readonly>
              <p class="admin-hint">Moderators create events for their own organization.</p>
            <?php else: ?>
            <select id="organization_id" name="organization_id" class="admin-input<?= $bad('organization_id') ?>" required>
              <option value="">Select an organization</option>
              <?php foreach ($organizations as $o): ?>
                <option value="<?= htmlspecialchars($o['id']) ?>" <?= ($f['organization_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
              <?php endforeach; ?>
            </select><?= $why('organization_id') ?>
            <?php if (empty($organizations)): ?>
              <p class="admin-hint">No organizations yet — <a href="admin-taxonomy.php?tab=organizations">add one</a> first.</p>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <span class="admin-label">Event Type</span>
        <div class="admin-radio-row">
          <?php foreach (EVENT_TYPES as $typeKey => $label): ?>
            <label class="admin-radio"><input type="radio" name="event_type" value="<?= $typeKey ?>" <?= ($f['event_type'] ?? 'other') === $typeKey ? 'checked' : '' ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>

        <span class="admin-label">Audience <span class="req">*</span></span>
        <div class="admin-radio-row">
          <?php foreach (EVENT_AUDIENCES as $key => $label): ?>
            <label class="admin-radio"><input type="radio" name="audience" value="<?= $key ?>" <?= ($f['audience'] ?? 'students') === $key ? 'checked' : '' ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>

        <div data-year-levels>
          <span class="admin-label">Open to Year Levels</span>
          <div class="admin-radio-row">
            <?php foreach (YEAR_LEVELS as $y => $label): ?>
              <label class="admin-radio"><input type="checkbox" name="year_levels[]" value="<?= $y ?>" <?= in_array($y, $formLevels, true) ? 'checked' : '' ?>> <?= $label ?></label>
            <?php endforeach; ?>
          </div>
          <p class="admin-hint">Pick none to open the event to every year level. Students outside the picked year levels can't register; irregular students can if any of their subjects' year levels is picked.</p>
        </div>

        <label class="admin-label" for="description">Description <span class="req">*</span></label>
        <textarea id="description" name="description" class="admin-input<?= $bad('description') ?>" rows="5" maxlength="2000" placeholder="Write event description here…"><?= $val('description') ?></textarea><?= $why('description') ?>

        <div class="admin-section-label">Date, Time &amp; Venue</div>
        <div class="admin-four-col">
          <div>
            <label class="admin-label" for="start_date">Start Date <span class="req">*</span></label>
            <input type="date" id="start_date" name="start_date" class="admin-input<?= $bad('start_date') ?>" value="<?= $val('start_date') ?>" required><?= $why('start_date') ?>
          </div>
          <div>
            <label class="admin-label" for="start_time">Start Time</label>
            <input type="time" id="start_time" name="start_time" class="admin-input" value="<?= $time('start_time') ?>">
          </div>
          <div>
            <label class="admin-label" for="end_date">End Date <span class="req">*</span></label>
            <input type="date" id="end_date" name="end_date" class="admin-input<?= $bad('end_date') ?>" value="<?= $val('end_date') ?>" required><?= $why('end_date') ?>
          </div>
          <div>
            <label class="admin-label" for="end_time">End Time</label>
            <input type="time" id="end_time" name="end_time" class="admin-input<?= $bad('end_time') ?>" value="<?= $time('end_time') ?>"><?= $why('end_time') ?>
          </div>
        </div>
        <!-- Other events on these dates, and clashes (filled by the script below) -->
        <div class="schedule-hint" data-schedule-hint hidden></div>

        <span class="admin-label">Venue Type <span class="req">*</span></span>
        <div class="admin-radio-row">
          <?php foreach (VENUE_TYPES as $key => $label): ?>
            <label class="admin-radio"><input type="radio" name="venue_type" value="<?= $key ?>" <?= ($f['venue_type'] ?? 'on_campus') === $key ? 'checked' : '' ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="venue">Venue <span class="req" data-venue-req>*</span></label>
            <input type="text" id="venue" name="venue" class="admin-input<?= $bad('venue') ?>" placeholder="e.g. AVR 1, CITE Building" value="<?= $val('venue') ?>"><?= $why('venue') ?>
          </div>
          <div>
            <label class="admin-label" for="online_event_link">Online Event Link <span class="req" data-link-req hidden>*</span></label>
            <input type="url" id="online_event_link" name="online_event_link" class="admin-input<?= $bad('online_event_link') ?>" placeholder="https://…" value="<?= $val('online_event_link') ?>"><?= $why('online_event_link') ?>
          </div>
        </div>
        <p class="admin-hint">On-campus and off-campus events need a venue (a livestream link is optional). Online events need the webinar link.</p>

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
          <input type="file" id="poster_file" name="poster_file" class="admin-input<?= $bad('poster_file') ?>" accept="image/jpeg,image/png,image/webp,image/gif"><?= $why('poster_file') ?>
          <p class="admin-hint">JPG, PNG, WEBP or GIF, up to 5 MB. Replaces the current poster.</p>
          <label class="admin-label" for="poster_url">…or paste an image URL</label>
          <input type="text" id="poster_url" name="poster_url" class="admin-input" placeholder="https://…" value="<?= $val('poster_url') ?>">
        </div>

        <div class="admin-card">
          <h2 class="admin-card-title">Registration &amp; Contact</h2>
          <label class="admin-label" for="registration_limit">Registration Limit</label>
          <input type="number" min="1" id="registration_limit" name="registration_limit" class="admin-input<?= $bad('registration_limit') ?>" placeholder="Leave empty for no limit" value="<?= $val('registration_limit') ?>"><?= $why('registration_limit') ?>

          <label class="admin-label" for="registration_deadline">Registration Deadline</label>
          <input type="date" id="registration_deadline" name="registration_deadline" class="admin-input<?= $bad('registration_deadline') ?>" value="<?= $val('registration_deadline') ?>"><?= $why('registration_deadline') ?>

          <label class="admin-label" for="contact_email">Contact Email</label>
          <input type="email" id="contact_email" name="contact_email" class="admin-input<?= $bad('contact_email') ?>" placeholder="Enter email" value="<?= $val('contact_email') ?>"><?= $why('contact_email') ?>

          <label class="admin-label" for="contact_number">Contact Number</label>
          <input type="text" id="contact_number" name="contact_number" class="admin-input" placeholder="Enter no." value="<?= $val('contact_number') ?>">
        </div>

        <?php if ($editId): ?>
          <div class="admin-card admin-card-links">
            <h2 class="admin-card-title">More for this event</h2>
            <a href="event-programs.php?event=<?= urlencode($editId) ?>"><?= icon('schedule', 16) ?> Edit the schedule (programs)</a>
            <a href="event-registrations.php?event=<?= urlencode($editId) ?>"><?= icon('people', 16) ?> See registrations &amp; attendance</a>
            <a href="attendance-print.php?event=<?= urlencode($editId) ?>"><?= icon('print', 16) ?> Print the attendance sheet</a>
            <a href="event-evaluations.php?event=<?= urlencode($editId) ?>"><?= icon('evaluation', 16) ?> See evaluation results</a>
            <a href="../event-details.php?id=<?= urlencode($editId) ?>"><?= icon('view', 16) ?> View the event page</a>
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
      <h2><?= $isAdmin ? 'All Events' : htmlspecialchars($myOrgName) . ' Events' ?> (<?= count($events) ?>)</h2>
    </div>
    <p class="admin-hint admin-hint-block">Published events become <strong>Completed</strong> automatically once they end. Use Mark Completed to close one early.</p>
    <div class="admin-table-wrap admin-table-scroll" data-visible-rows="5">
      <table class="admin-table admin-table-compact">
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
                <div class="admin-table-sub"><?= htmlspecialchars(implode(' · ', array_filter([$ev['categories']['name'] ?? '', label_of(VENUE_TYPES, $ev['venue_type'] ?? null, ''), 'For ' . label_of(EVENT_AUDIENCES, $ev['audience'] ?? null, 'Students')]))) ?></div>
              </td>
              <td>
                <?php if (!empty($ev['organizations']['name'])): ?>
                  <span class="admin-org-cell">
                    <?php if (!empty($ev['organizations']['logo_url'])): ?><img src="<?= htmlspecialchars(asset_url($ev['organizations']['logo_url'])) ?>" alt="" class="admin-org-logo"><?php endif; ?>
                    <?= htmlspecialchars($ev['organizations']['name']) ?>
                  </span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="admin-nowrap"><?= htmlspecialchars(format_event_date($ev['start_date'] ?? null)) ?></td>
              <td><?= (int) ($counts[$ev['id']] ?? 0) ?><?= !empty($ev['registration_limit']) ? ' / ' . (int) $ev['registration_limit'] : '' ?></td>
              <td><span class="admin-status admin-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
              <td>
                <div class="admin-row-actions admin-row-actions-nowrap">
                <?php /* Two menus keep every row on one line:
                         Edit ▾   — change the event itself (details, status, delete)
                         Manage ▾ — what happens around it (schedule, registrations, evaluations) */ ?>
                <details class="row-more">
                  <summary class="btn-sm">Edit <span aria-hidden="true">▾</span></summary>
                  <div class="row-more-menu" role="group" aria-label="Edit <?= htmlspecialchars($ev['title']) ?>">
                    <a href="event-organizer.php?edit=<?= urlencode($ev['id']) ?>" class="btn-sm">Update</a>

                    <?php if (in_array($status, ['draft', 'published'], true)): ?>
                      <form method="POST" action="event-organizer.php" onsubmit="return confirm('Cancel this event? Everyone registered will be notified.');">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="btn-sm btn-sm-danger">Mark Cancelled</button>
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

                    <?php if ($isAdmin): ?>
                      <form method="POST" action="event-organizer.php" class="row-more-danger" onsubmit="return confirm('Delete this event? Its registrations and schedule are deleted too. This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </details>

                <details class="row-more">
                  <summary class="btn-sm">Manage <span aria-hidden="true">▾</span></summary>
                  <div class="row-more-menu" role="group" aria-label="Manage <?= htmlspecialchars($ev['title']) ?>">
                    <a href="event-programs.php?event=<?= urlencode($ev['id']) ?>" class="btn-sm">Schedule (<?= $programCount ?>)</a>
                    <a href="event-registrations.php?event=<?= urlencode($ev['id']) ?>" class="btn-sm">Registrations</a>
                    <?php if ($status !== 'draft'): ?>
                      <a href="event-evaluations.php?event=<?= urlencode($ev['id']) ?>" class="btn-sm">Evaluations</a>
                    <?php endif; ?>
                  </div>
                </details>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>

</div>

<script>
(function () {
  var form = document.getElementById('event-form');

  // Venue type decides which field is required: the venue (on/off-campus) or the link (online).
  var venue = document.getElementById('venue');
  var link = document.getElementById('online_event_link');
  function syncVenue() {
    var checked = document.querySelector('input[name="venue_type"]:checked');
    var online = checked && checked.value === 'online';
    venue.required = !online;
    link.required = online;
    document.querySelector('[data-venue-req]').hidden = online;
    document.querySelector('[data-link-req]').hidden = !online;
  }

  // Year levels only apply to events students can join.
  var levels = document.querySelector('[data-year-levels]');
  function syncAudience() {
    var checked = document.querySelector('input[name="audience"]:checked');
    levels.hidden = checked && checked.value === 'faculty';
  }

  // Other events on the chosen dates, and whether this one would clash
  // (same title on the same date, or the same venue at the same time).
  var hint = document.querySelector('[data-schedule-hint]');
  var timer = null;
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
  function checkSchedule() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var data = new FormData(form);
      if (!data.get('start_date')) { hint.hidden = true; return; }
      var params = new URLSearchParams();
      ['title', 'start_date', 'end_date', 'start_time', 'end_time', 'venue', 'venue_type', 'id'].forEach(function (k) { params.set(k, data.get(k) || ''); });
      fetch('../api/schedule-check.php?' + params.toString(), { cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          if (!res) { hint.hidden = true; return; }
          var html = '';
          if (res.duplicate) html += '<p class="schedule-clash"><strong>Duplicate:</strong> "' + esc(res.duplicate.title) + '" is already on ' + esc(res.duplicate.when) + '. Change the title or the date.</p>';
          if (res.venue_conflict) html += '<p class="schedule-clash"><strong>Venue taken:</strong> ' + esc(res.venue_conflict.place) + ' is booked for "' + esc(res.venue_conflict.title) + '" (' + esc(res.venue_conflict.when) + ').</p>';
          if (res.same_dates.length) {
            html += '<p class="schedule-others">' + res.same_dates.length + ' other event' + (res.same_dates.length === 1 ? '' : 's') + ' on these dates:</p><ul>'
              + res.same_dates.slice(0, 6).map(function (e) { return '<li><strong>' + esc(e.title) + '</strong> · ' + esc(e.when) + ' · ' + esc(e.place) + (e.status === 'draft' ? ' (draft)' : '') + '</li>'; }).join('') + '</ul>';
          } else if (!html) {
            html = '<p class="schedule-ok">No other events on these dates.</p>';
          }
          hint.innerHTML = html;
          hint.hidden = false;
        })
        .catch(function () { hint.hidden = true; });
    }, 350);
  }

  // ---- Fields that still need information ----
  // Checked before the form is sent (the same rules the server applies):
  // every field with a problem gets a red border and the reason under it,
  // and the page scrolls to the first one.
  var summary = document.querySelector('[data-form-summary]');
  function byId(id) { return document.getElementById(id); }
  function markInvalid(el, message) {
    el.classList.add('is-invalid');
    el.setAttribute('aria-invalid', 'true');
    var note = byId(el.id + '-error');
    if (!note) {
      note = document.createElement('p');
      note.className = 'field-error';
      note.id = el.id + '-error';
      el.insertAdjacentElement('afterend', note);
    }
    note.textContent = message;
    el.setAttribute('aria-describedby', note.id);
  }
  function clearInvalid(el) {
    el.classList.remove('is-invalid');
    el.removeAttribute('aria-invalid');
    el.removeAttribute('aria-describedby');
    var note = byId(el.id + '-error');
    if (note) note.remove();
    if (summary && !form.querySelector('.is-invalid')) summary.hidden = true;
  }
  function findProblems() {
    var problems = [];
    function add(id, message) {
      var el = byId(id);
      if (el && !problems.some(function (p) { return p[0] === el; })) problems.push([el, message]);
    }
    function empty(id) { var el = byId(id); return el && !el.readOnly && el.value.trim() === ''; }
    var v = function (id) { return (byId(id) && byId(id).value.trim()) || ''; };

    if (empty('title')) add('title', 'Enter the event title.');
    if (empty('category_id')) add('category_id', 'Choose a category.');
    if (empty('organization_id')) add('organization_id', 'Choose an organization.');
    if (empty('description')) add('description', 'Write a short description of the event.');
    else if (v('description').length > 2000) add('description', 'Description must be 2000 characters or fewer.');
    if (empty('start_date')) add('start_date', 'Choose the start date.');
    if (empty('end_date')) add('end_date', 'Choose the end date.');
    else if (v('start_date') && v('end_date') < v('start_date')) add('end_date', "End date can't be before the start date.");
    if (v('start_date') && v('start_date') === v('end_date') && v('start_time') && v('end_time') && v('end_time') <= v('start_time')) {
      add('end_time', 'End time must be after the start time.');
    }
    var type = document.querySelector('input[name="venue_type"]:checked');
    if (type && type.value === 'online') {
      if (empty('online_event_link')) add('online_event_link', 'Online events need the webinar / meeting link.');
    } else if (empty('venue')) {
      add('venue', 'Enter where the event will be held.');
    }
    if (v('online_event_link') && !/^https?:\/\//i.test(v('online_event_link'))) add('online_event_link', 'The link must start with http:// or https://.');
    if (v('registration_limit') && !(parseInt(v('registration_limit'), 10) >= 1)) add('registration_limit', 'Must be at least 1 (or leave it empty for no limit).');
    if (v('registration_deadline') && v('end_date') && v('registration_deadline') > v('end_date')) add('registration_deadline', 'Must be on or before the end date.');
    if (v('contact_email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('contact_email'))) add('contact_email', 'Enter a valid email address.');
    return problems;
  }
  function focusFirstInvalid() {
    var first = form.querySelector('.is-invalid');
    if (!first) return;
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    first.focus({ preventScroll: true });
  }
  form.addEventListener('submit', function (e) {
    form.querySelectorAll('.is-invalid').forEach(clearInvalid);
    var problems = findProblems();
    if (!problems.length) return;
    e.preventDefault();
    problems.forEach(function (p) { markInvalid(p[0], p[1]); });
    if (summary) {
      summary.textContent = problems.length === 1
        ? '1 field needs your attention before the event can be saved (highlighted in red below).'
        : problems.length + ' fields need your attention before the event can be saved (highlighted in red below).';
      summary.hidden = false;
    }
    focusFirstInvalid();
  });
  // Fixing a field takes its red border away.
  ['input', 'change'].forEach(function (type) {
    form.addEventListener(type, function (e) {
      if (e.target.classList && e.target.classList.contains('is-invalid')) clearInvalid(e.target);
    });
  });
  // Back from the server with errors: go straight to the first highlighted field.
  if (form.querySelector('.is-invalid')) focusFirstInvalid();

  form.addEventListener('change', function () { syncVenue(); syncAudience(); checkSchedule(); });
  ['title', 'venue'].forEach(function (id) { document.getElementById(id).addEventListener('input', checkSchedule); });
  syncVenue();
  syncAudience();
  checkSchedule();
})();
</script>
<script>
// Events table: show the first five events; more than that scroll inside the card.
(function () {
  var box = document.querySelector('[data-visible-rows]');
  if (!box) return;
  var limit = parseInt(box.getAttribute('data-visible-rows'), 10) || 5;
  function fit() {
    var rows = box.querySelectorAll('tbody tr');
    if (rows.length <= limit) { box.style.maxHeight = 'none'; return; }
    var head = box.querySelector('thead');
    var height = head ? head.offsetHeight : 0;
    for (var i = 0; i < limit; i++) height += rows[i].offsetHeight;
    box.style.maxHeight = height + 'px';
  }
  fit();
  window.addEventListener('resize', fit);
})();
</script>
<script>
// Events table "Edit ▾" / "Manage ▾" menus: one open at a time; a click outside or Escape closes it.
(function () {
  var menus = document.querySelectorAll('details.row-more');
  menus.forEach(function (menu) {
    menu.addEventListener('toggle', function () {
      if (!menu.open) return;
      menus.forEach(function (m) { if (m !== menu) m.open = false; });
      // Near the bottom of the scrolling table, open the menu upward so it isn't cut off.
      menu.classList.remove('row-more-up');
      var box = menu.closest('.admin-table-scroll');
      var list = menu.querySelector('.row-more-menu');
      if (box && list && list.getBoundingClientRect().bottom > box.getBoundingClientRect().bottom) {
        menu.classList.add('row-more-up');
      }
    });
  });
  document.addEventListener('click', function (e) {
    menus.forEach(function (m) { if (m.open && !m.contains(e.target)) m.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    menus.forEach(function (m) {
      if (m.open) { m.open = false; m.querySelector('summary').focus(); }
    });
  });
})();
</script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/ui.js"></script>
<script src="../assets/js/forms.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
