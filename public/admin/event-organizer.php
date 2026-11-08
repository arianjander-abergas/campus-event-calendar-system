<?php
/**
 * Manage Events: create / edit / publish / cancel events.
 * Admins manage every event (and can delete); moderators manage their own
 * organization's events. Events are refused if they duplicate another event
 * (same title, same date) or double-book a venue; they're marked Completed
 * automatically once they end.
 *
 * Three views (sidebar: Manage Events ▾ Create Event / Update Events):
 *   event-organizer.php              Create Event — a blank form
 *   event-organizer.php?view=update  Update Events — the events list, with
 *                                    search, filters and 5 rows per page
 *   event-organizer.php?edit=<id>    Edit one event (from the list)
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_staff(); // admins + moderators; everyone else goes to the dashboard
$isAdmin = ($user['role'] ?? '') === 'admin';

$categories = get_categories();
$organizations = get_event_organizations_for($user); // who may be the main organizer
$allOrganizations = $isAdmin ? $organizations : get_organizations(); // who may co-organize
$departments = get_departments(); // the clickable headings in the Organization picker

$editId = get_param('edit');
$editEvent = is_uuid($editId) ? get_event_admin($editId) : null;
if ($editId !== '' && (!$editEvent || !can_manage_event($editEvent, $user))) {
    flash('error', $editEvent ? "Admins can only edit their own organization's events." : "That event doesn't exist anymore.");
    header('Location: event-organizer.php');
    exit;
}
$errors = [];
$view = $editId !== '' ? 'edit' : (get_param('view') === 'update' ? 'update' : 'create');

// Where the list's buttons return to: the same search, filters and page.
$listUrl = 'event-organizer.php?view=update';
$back = post_param('back');
if (!preg_match('~^event-organizer\.php\?view=update(&[\w%.+\-=&]*)?$~', $back)) $back = $listUrl;

// The event a status / delete button acts on, if this user may manage it.
$target = function (string $id) use ($user): ?array {
    $ev = is_uuid($id) ? get_event_admin($id) : null;
    return $ev && can_manage_event($ev, $user) ? $ev : null;
};

// ---- Delete (admins only; moderators cancel instead) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete') {
    $result = $target(post_param('id')) ? delete_event_admin(post_param('id')) : ['ok' => false, 'message' => "You can't delete that event."];
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $back);
    exit;
}

// ---- Status-only changes (Cancel / Mark Completed / Reopen as Draft) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_status') {
    $result = $target(post_param('id')) ? set_event_status_admin(post_param('id'), post_param('status')) : ['ok' => false, 'message' => "You can't change that event."];
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $back);
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
    // No separate Event Type on the form: it follows the category's name
    // (e.g. "Seminar"), anything else is 'other'.
    $categoryName = (string) (array_column($categories, 'name', 'id')[post_param('category_id')] ?? '');
    $eventType = strtolower(trim($categoryName));
    $categoryOther = is_other_category($categoryName) ? post_param('category_other') : '';
    // Who it's for: any of Students, Faculty, Outsiders (events.audiences);
    // events.audience keeps the older single value for the reports.
    $audiences = array_values(array_intersect(array_keys(AUDIENCE_GROUPS), array_filter((array) ($_POST['audiences'] ?? []), 'is_string')));
    $audience = audience_value($audiences);
    $venueType = post_param('venue_type', 'on_campus');
    $venueType = isset(VENUE_TYPES[$venueType]) ? $venueType : 'on_campus';
    // Year levels that may register (none or all picked = every year level; only for events open to students).
    $yearLevels = in_array('students', $audiences, true) ? parse_year_levels($_POST['year_levels'] ?? []) : [];
    if (count($yearLevels) === count(YEAR_LEVELS)) $yearLevels = [];
    // Required attendance: who must attend (among the people it's open to).
    $requiredFor = isset(REQUIRED_FOR[post_param('required_for')]) ? post_param('required_for') : '';

    // Organizations: one, or two or more for a multi-organization event. The
    // main organizer (events.organization_id) runs the event — a moderator's
    // own organization; the rest are saved as co-organizers.
    $multiOrg = isset($_POST['multi_org']);
    $picked = array_values(array_unique(array_intersect(
        array_filter((array) ($_POST['organization_ids'] ?? []), 'is_string'),
        array_column($allOrganizations, 'id')
    )));
    if (!$isAdmin) {
        $leadOrg = (string) $user['organization_id'];
        $picked = array_values(array_unique(array_merge([$leadOrg], $picked)));
    } else {
        $leadOrg = in_array(post_param('organization_id'), $picked, true) ? post_param('organization_id') : ($picked[0] ?? '');
    }
    if (!$multiOrg) $picked = $leadOrg !== '' ? [$leadOrg] : [];
    $partnerOrgs = array_values(array_diff($picked, [$leadOrg]));

    // Collect everything typed FIRST, so a failed save re-shows the form filled in.
    $fields = [
        'title' => post_param('title'),
        'category_id' => post_param('category_id'),
        'category_other' => $categoryOther !== '' ? $categoryOther : null,
        'organization_id' => $leadOrg,
        'event_type' => isset(EVENT_TYPES[$eventType]) ? $eventType : 'other',
        'audience' => $audience,
        'audiences' => $audiences ?: null,
        'year_levels' => $yearLevels ?: null,
        'required_for' => $requiredFor !== '' ? $requiredFor : null,
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

    // 9. Program Flow: the parts typed, in order (completely empty rows are skipped).
    $pf = (array) ($_POST['programs'] ?? []);
    $pfText = fn(string $col, $i): string => is_string($pf[$col][$i] ?? null) ? trim($pf[$col][$i]) : '';
    $programs = [];
    foreach (array_keys((array) ($pf['title'] ?? [])) as $i) {
        $row = ['id' => $pfText('id', $i), 'title' => $pfText('title', $i), 'speaker' => $pfText('speaker', $i), 'description' => $pfText('description', $i)];
        if ($row['title'] !== '' || $row['speaker'] !== '' || $row['description'] !== '') $programs[] = $row;
    }

    // Saving as a draft takes back a request for a Super Admin's approval
    // (publishing sends it again; the database turns that into a request).
    if ($intent === 'draft' && ($previous['approval_status'] ?? null) === 'pending') {
        $fields['approval_status'] = 'needed';
    }

    // Only send year levels when used (or being cleared), so saving still works
    // on a database that doesn't have the column yet.
    if (!$fields['year_levels'] && empty($previous['year_levels'])) unset($fields['year_levels']);
    // Same for the "Other" event type and required attendance.
    if (!$fields['category_other'] && empty($previous['category_other'])) unset($fields['category_other']);
    if (!$fields['required_for'] && empty($previous['required_for'])) unset($fields['required_for']);

    // Errors are keyed by the field they belong to, so the form can highlight
    // that field and show the reason under it (the first problem per field wins).
    $fail = function (string $field, string $message) use (&$errors): void {
        if (!isset($errors[$field])) $errors[$field] = $message;
    };
    if ($fields['title'] === '') $fail('title', 'Event title is required.');
    if (!in_array($fields['category_id'], array_column($categories, 'id'), true)) $fail('category_id', 'Please choose a category.');
    if (!$audiences) $fail('audiences', 'Choose who the event is for: Students, Faculty, Outsiders, or any of them together.');
    if (is_other_category($categoryName) && $categoryOther === '') $fail('category_other', 'For the "Other" category, type what kind of event it is.');
    if (mb_strlen($categoryOther) > 60) $fail('category_other', 'The event type must be 60 characters or fewer.');
    if (!in_array($fields['organization_id'], array_column($organizations, 'id'), true)) $fail('organizations', 'Please choose an organization.');
    elseif ($multiOrg && count($picked) < 2) $fail('organizations', 'A multi-organization event needs two or more organizations — pick the others, or unpick "Multi-organization event".');
    if ($fields['description'] === '') $fail('description', 'Description is required.');
    if (mb_strlen($fields['description']) > 2000) $fail('description', 'Description must be 2000 characters or fewer.');
    if ($fields['start_date'] === '') $fail('start_date', 'Start date is required.');
    if ($fields['end_date'] === '') $fail('end_date', 'End date is required.');
    // No events in the past: a new event (or a date being changed) can't be before today.
    // An existing event's own dates may stay as they are.
    $today = date('Y-m-d');
    foreach (['start_date' => 'start date', 'end_date' => 'end date', 'registration_deadline' => 'registration deadline'] as $key => $label) {
        $date = (string) ($fields[$key] ?? '');
        if ($date !== '' && $date < $today && $date !== (string) ($previous[$key] ?? '')) {
            $fail($key, 'The ' . $label . ' can\'t be before today (' . format_event_date($today) . ').');
        }
    }
    if ($fields['start_date'] !== '' && $fields['end_date'] !== '' && $fields['end_date'] < $fields['start_date']) {
        $fail('end_date', "End date can't be before the start date.");
    }
    if ($fields['start_date'] === $fields['end_date'] && $fields['start_time'] && $fields['end_time'] && $fields['end_time'] <= $fields['start_time']) {
        $fail('end_time', 'End time must be after the start time.');
    }
    if ($venueType === 'online' && $onlineLink === '') $fail('online_event_link', 'Online events need the webinar / meeting link.');
    if ($venueType !== 'online' && !$fields['venue']) $fail('venue', 'On-campus and off-campus events need a venue.');
    if ($onlineLink !== '' && !preg_match('~^https?://~i', $onlineLink)) $fail('online_event_link', 'The online event link must start with http:// or https://.');
    if ($registrationLimit !== '' && (int) $registrationLimit < 1) $fail('registration_limit', 'Registration limit must be at least 1 (or leave it empty for no limit).');
    if ($fields['registration_deadline'] && $fields['end_date'] !== '' && $fields['registration_deadline'] > $fields['end_date']) {
        $fail('registration_deadline', 'The registration deadline must be on or before the end date.');
    }
    if ($fields['contact_email'] && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) $fail('contact_email', 'Contact email is not a valid email address.');
    foreach ($programs as $p) {
        if ($p['title'] === '') $fail('programs', 'Each part of the program flow needs a name (what happens) — fill it in or remove that part.');
        if (mb_strlen($p['title']) > 200 || mb_strlen($p['speaker']) > 120 || mb_strlen($p['description']) > 500) $fail('programs', 'A part of the program flow is too long (name: 200 characters, speaker: 120, notes: 500).');
    }
    if (count($programs) > 60) $fail('programs', 'The program flow can have up to 60 parts.');

    // No duplicates: the same title on the same date, or a venue booked twice at once.
    // (The database refuses these too, even for events this user can't see.)
    if (!$errors) {
        $clash = find_schedule_conflicts($fields, $previous['id'] ?? null);
        if ($clash['duplicate']) {
            $fail('title', '"' . $clash['duplicate']['title'] . '" is already scheduled on ' . format_event_date($clash['duplicate']['start_date']) . '. Change the title or the date — the same event can\'t be entered twice.');
        }
        if ($clash['venue_conflict']) {
            $c = $clash['venue_conflict'];
            $fail('venue', $fields['venue'] . ' is already booked for "' . $c['title'] . '" (' . event_when($c) . '). Choose another venue, date or time.');
        }
    }

    // Poster: an uploaded image wins over a pasted URL.
    if (!$errors) {
        $upload = upload_public_image('event-posters', $_FILES['poster_file'] ?? [], date('Y'));
        if ($upload['ok']) {
            $fields['poster_url'] = $upload['url'];
        } elseif (!empty($upload['message'])) {
            $fail('poster_file', $upload['message']);
        }
    }

    if (!$errors) {
        $result = $previous ? update_event_admin($previous['id'], $fields) : create_event_admin($fields);
        if ($result['ok']) {
            $eventId = $previous['id'] ?? $result['event']['id'];
            // An Admin's (moderator role) event is published once a Super Admin approves it (the database keeps it a draft till then).
            $waiting = $intent === 'publish' && ($result['event']['approval_status'] ?? null) === 'pending';
            $fields['status'] = $result['event']['status'] ?? $fields['status'];
            $message = $waiting
                ? 'Sent to a Super Admin for approval. It stays a draft until it\'s approved' . ($sendNotification ? '; everyone is notified when it\'s published.' : '.')
                : ($previous ? 'Event updated.' : ($intent === 'publish' ? 'Event published.' : 'Draft saved.'));
            if (($previous['approval_status'] ?? null) === 'pending' && ($fields['approval_status'] ?? null) === 'needed') {
                $message = 'Draft saved. It was taken back from approval — publish it to send it again.';
            }

            // What the database couldn't store yet (a database update not run): the event
            // is saved without it, and the message says what's missing.
            $labels = ['category_other' => 'the type of event' . ($categoryOther !== '' ? ' ("' . $categoryOther . '")' : ''), 'year_levels' => 'the year levels', 'audiences' => 'Outsiders in the audience', 'required_for' => 'the required attendance'];
            // Without the audiences column, Students / Faculty still go in the older audience field; only Outsiders is lost.
            $dropped = array_filter($result['dropped'] ?? [], fn($c) => $c !== 'audiences' || in_array('outsiders', $audiences, true));
            $notSaved = array_map(fn($c) => $labels[$c] ?? $c, $dropped);
            // Co-organizers (also cleared when an event stops being multi-organization).
            if (($partnerOrgs || $previous) && !set_event_partner_orgs($eventId, $partnerOrgs) && $partnerOrgs) {
                $notSaved[] = 'the other organizations';
            }
            // The program flow (section 9): kept, added, reordered and removed parts.
            if (($programs || $previous) && !save_event_programs($eventId, $programs)) {
                $notSaved[] = 'the program flow';
            }
            if ($notSaved) {
                flash('error', 'Not saved yet: ' . implode(' and ', $notSaved) . '. The database is missing an update — a Super Admin can run it from Database update (link in the red banner), then edit the event to add these.');
            }

            // Notifications (in the site and by email):
            //  - newly published: the people required to attend, always; with
            //    "Send Notification", everyone else the event is open to;
            //  - already published: people who just became required; with
            //    "Send Notification", the registrants and required attendees.
            if ($fields['status'] === 'published') {
                $wasPublished = ($previous['status'] ?? '') === 'published';
                $where = event_when($fields) . ' · ' . event_place($fields);
                $savedRequired = in_array('required_for', $dropped, true) ? null : ($requiredFor !== '' ? $requiredFor : null);
                $ev = ['id' => $eventId, 'required_for' => $savedRequired] + $fields;
                $sent = null;
                if (!$wasPublished) {
                    if ($savedRequired || $sendNotification) $sent = announce_published_event($ev, $sendNotification);
                } else {
                    if ($savedRequired && $savedRequired !== ($previous['required_for'] ?? null)) {
                        $sent = max(0, notify_event($eventId, 'required', 'Now required: ' . $fields['title'], 'Your attendance is required. ' . $where));
                    }
                    if ($sendNotification) {
                        $n = notify_event($eventId, 'registered', 'Event updated: ' . $fields['title'], 'Details changed — ' . $where . '. Check the event page for the latest info.');
                        $sent = $n >= 0 ? (int) $sent + $n : ($sent ?? -1);
                    }
                }
                if ($sent !== null && $sent >= 0) {
                    $message .= ' ' . $sent . ' ' . ($sent === 1 ? 'person was' : 'people were') . ' notified' . (mail_enabled() ? ' (in the site and by email).' : '.');
                } elseif ($sent !== null) {
                    $message .= ' (Notifications could not be sent.)';
                }
            }
            // An event that's already over is marked Completed right away.
            if ($fields['status'] === 'published') {
                auto_complete_events(true);
            }
            // New event: back to a clean form. Edited event: back to the list.
            flash('success', $message);
            header('Location: ' . ($previous ? $listUrl : 'event-organizer.php'));
            exit;
        }
        $errors[] = $result['message'];
    }

    // Show the form again with what was typed + the errors — once. The
    // redirect means refreshing the page doesn't post it again; refreshing
    // or leaving and coming back shows a clean form.
    if (!empty($_FILES['poster_file']['name'])) {
        $fail('poster_file', 'Choose the poster image again (files can\'t be kept after an error).');
    }
    remember_form('event', $fields + ['id' => $previous['id'] ?? null, 'multi_org' => $multiOrg, 'partner_ids' => $partnerOrgs, 'programs' => $programs], $errors);
    header('Location: event-organizer.php' . ($previous ? '?edit=' . urlencode($previous['id']) : '') . '#event-form');
    exit;
}

// The event's co-organizers, when editing one.
if ($editEvent) {
    $editEvent['partner_ids'] = array_column(get_event_partner_orgs([$editEvent['id']])[$editEvent['id']] ?? [], 'id');
    $editEvent['multi_org'] = (bool) $editEvent['partner_ids'];
    $editEvent['programs'] = get_event_programs($editEvent['id']); // section 9
}

// Will publishing wait for a Super Admin? (Admins' — moderator role — new events do.)
$savedEvent = $editEvent;
$needsApproval = $view !== 'update' && event_needs_approval($savedEvent, $user);

// A form with errors from the save just above (shown once).
$state = recall_form('event');
if ($state && ($state['values']['id'] ?? '') === ($editEvent['id'] ?? '')) {
    $editEvent = $state['values'];
    $errors = $state['errors'];
}

// ---- Update Events list: search, filters, 5 rows per page ----
if ($view === 'update') {
    $allEvents = get_all_events_admin();
    $counts = get_registration_counts();
    $partners = get_event_partner_orgs(); // co-organizers of multi-organization events

    $statuses = ['draft' => 'Draft', 'published' => 'Published', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $timeframes = ['upcoming' => 'Upcoming & ongoing', 'past' => 'Past'];
    $q = get_param('q');
    $statusFilter = isset($statuses[get_param('status')]) ? get_param('status') : '';
    $categoryFilter = in_array(get_param('category'), array_column($categories, 'id'), true) ? get_param('category') : '';
    $orgFilter = $isAdmin && in_array(get_param('org'), array_column($organizations, 'id'), true) ? get_param('org') : '';
    $whenFilter = isset($timeframes[get_param('when')]) ? get_param('when') : '';
    $filters = array_filter(['q' => $q, 'status' => $statusFilter, 'category' => $categoryFilter, 'org' => $orgFilter, 'when' => $whenFilter], 'strlen');

    $today = date('Y-m-d');
    $events = array_values(array_filter($allEvents, function ($ev) use ($q, $statusFilter, $categoryFilter, $orgFilter, $whenFilter, $today, $partners) {
        $evPartners = $partners[$ev['id']] ?? [];
        if ($statusFilter !== '' && ($ev['status'] ?? 'draft') !== $statusFilter) return false;
        if ($categoryFilter !== '' && ($ev['category_id'] ?? '') !== $categoryFilter) return false;
        if ($orgFilter !== '' && ($ev['organization_id'] ?? '') !== $orgFilter && !in_array($orgFilter, array_column($evPartners, 'id'), true)) return false;
        $ends = (string) ($ev['end_date'] ?? $ev['start_date'] ?? '');
        if ($whenFilter === 'upcoming' && $ends < $today) return false;
        if ($whenFilter === 'past' && $ends >= $today) return false;
        return matches_search($q, [$ev['title'] ?? '', event_organizers_label($ev, $evPartners, ''), event_category_label($ev, ''), $ev['venue'] ?? '']);
    }));

    $pager = paginate($events, 10, (int) get_param('page'));
    $events = $pager['rows'];
    $total = $pager['total'];
    $pageUrl = fn(int $p): string => $listUrl . (($qs = http_build_query($filters + ($p > 1 ? ['page' => $p] : []))) !== '' ? '&' . $qs : '');
    $back = $pageUrl($pager['page']);
}

// Values to pre-fill the form with (event being edited, or a failed submission).
$f = $editEvent ?? [];
if (!$isAdmin) $f['organization_id'] = $user['organization_id'];
$val = fn(string $key, $default = '') => htmlspecialchars((string) ($f[$key] ?? $default));
$time = fn(string $key) => htmlspecialchars(substr((string) ($f[$key] ?? ''), 0, 5)); // "13:30:00" -> "13:30"
$checked = fn(string $key) => !empty($f[$key]) ? 'checked' : '';
// Fields the last save rejected: red border + the reason under the field.
// Errors without a field of their own (e.g. the database said no) show as banners.
$fieldKeys = ['title', 'category_id', 'category_other', 'description', 'start_date', 'end_date', 'end_time', 'venue', 'online_event_link',
    'poster_file', 'registration_limit', 'registration_deadline', 'contact_email', 'audiences', 'organizations', 'programs'];
$bad = fn(string $key) => isset($errors[$key]) ? ' is-invalid" aria-invalid="true" aria-describedby="' . $key . '-error' : '';
$why = fn(string $key) => isset($errors[$key]) ? '<p class="field-error" id="' . $key . '-error">' . htmlspecialchars($errors[$key]) . '</p>' : '';
$fieldErrorCount = count(array_intersect(array_keys($errors), $fieldKeys));
// Sections 1–9 fold open and shut; they start closed, except one with a field the last save rejected.
$openIf = fn(string ...$keys): string => array_intersect($keys, array_keys($errors)) ? ' open' : '';
$fold = '<span class="form-fold-chevron" aria-hidden="true">' . icon('chevron-down', 18) . '</span>';
// Earliest date the pickers allow: today — or an existing event's own earlier date, so it can be kept.
$minDate = function (string $key) use ($savedEvent): string {
    $saved = (string) ($savedEvent[$key] ?? '');
    return $saved !== '' && $saved < date('Y-m-d') ? $saved : date('Y-m-d');
};
$formLevels = parse_year_levels($f['year_levels'] ?? []);

$myOrgName = $organizations[0]['name'] ?? '';
$activeNav = $view === 'create' ? 'manage-create' : 'manage-update';
$pageTitle = ['create' => 'Create Event', 'update' => 'Update Events', 'edit' => 'Edit Event'][$view];
$breadcrumbs = [$isAdmin ? ['Admin Dashboard', 'dashboard.php'] : ['Dashboard', '../enduser/dashboard.php'], ['Manage Events', null]];
if ($view === 'edit') $breadcrumbs[] = ['Update Events', $listUrl];
$breadcrumbs[] = [$pageTitle, null];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= $pageTitle ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title"><?= $view === 'create' ? 'Create New Event' : $pageTitle ?></h1>
    <?php if (!$isAdmin): ?>
      <p class="admin-note">You're an <strong>Admin</strong> for <strong><?= htmlspecialchars($myOrgName) ?></strong>: you can create, publish, edit and cancel its events, and manage their schedules, registrations and attendance. Only admins can delete events.</p>
    <?php endif; ?>

    <?= render_flashes() ?>
    <?php if ($fieldErrorCount): ?>
      <div class="flash flash-error" role="alert">The event wasn't saved — <?= $fieldErrorCount === 1 ? '1 field needs' : $fieldErrorCount . ' fields need' ?> your attention (highlighted in red below).</div>
    <?php endif; ?>
    <?php foreach ($errors as $key => $err): if (in_array($key, $fieldKeys, true)) continue; ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
    <!-- Filled by the form check below when fields are missing -->
    <div class="flash flash-error" role="alert" data-form-summary hidden></div>
    <?php if ($view !== 'update' && ($savedEvent['approval_status'] ?? null) === 'pending' && ($savedEvent['status'] ?? '') === 'draft'): ?>
      <div class="admin-note approval-banner"><strong>Waiting for approval</strong> since <?= htmlspecialchars(format_timestamp($savedEvent['approval_requested_at'] ?? null)) ?>. A Super Admin publishes it once it's approved. Saving it as a draft takes the request back.<?php if (!empty($savedEvent['approval_note'])): ?><br><strong>Note from a Super Admin:</strong> “<?= htmlspecialchars($savedEvent['approval_note']) ?>”<?php endif; ?></div>
    <?php elseif ($view !== 'update' && ($savedEvent['approval_status'] ?? null) === 'rejected' && ($savedEvent['status'] ?? '') === 'draft'): ?>
      <div class="flash flash-error approval-banner" role="status"><strong>Not approved</strong> (<?= htmlspecialchars(format_timestamp($savedEvent['approval_reviewed_at'] ?? null)) ?>)<?= !empty($savedEvent['approval_note']) ? ': “' . htmlspecialchars($savedEvent['approval_note']) . '”' : '.' ?> Change what's needed and send it again.</div>
    <?php elseif ($needsApproval && $view === 'create'): ?>
      <div class="admin-note approval-banner">New events by Admins are published once a Super Admin approves them — <em>Send for Approval</em> sends this one.</div>
    <?php endif; ?>

    <?php if ($view !== 'update'): ?>
    <form method="POST" action="event-organizer.php<?= $editId ? '?edit=' . urlencode($editId) : '' ?>" class="admin-form" id="event-form" novalidate enctype="multipart/form-data" autocomplete="off" data-autoreset<?= $needsApproval ? ' data-needs-approval' : '' ?>>
      <input type="hidden" name="action" value="save">
      <?php if ($editId): ?><input type="hidden" name="id" value="<?= htmlspecialchars($editId) ?>"><?php endif; ?>

      <div class="admin-card">
        <details class="form-section form-fold"<?= $openIf('title', 'category_id', 'category_other', 'description') ?>>
          <summary><h2 class="form-section-head" id="sec-basic"><span class="form-section-num">1</span><span>Basic Information<small>Title, type of event and description</small></span><?= $fold ?></h2></summary>
          <label class="admin-label" for="title">Event Title <span class="req">*</span></label>
          <input type="text" id="title" name="title" class="admin-input<?= $bad('title') ?>" placeholder="Enter event title" value="<?= $val('title') ?>" required><?= $why('title') ?>

          <div>
            <div>
              <label class="admin-label" for="category_id">Category <span class="req">*</span></label>
              <select id="category_id" name="category_id" class="admin-input<?= $bad('category_id') ?>" required>
                <option value="">Select a category</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= htmlspecialchars($c['id']) ?>" <?= ($f['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?><?= is_other_category($c['name']) ? ' data-other' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select><?= $why('category_id') ?>
              <?php if (empty($categories)): ?>
                <p class="admin-hint">No categories yet — <a href="admin-taxonomy.php">add one</a> first.</p>
              <?php endif; ?>
              <div data-category-other hidden>
                <label class="admin-label" for="category_other">Type of Event <span class="req">*</span></label>
                <input type="text" id="category_other" name="category_other" class="admin-input<?= $bad('category_other') ?>" maxlength="60" placeholder="e.g. Hackathon, Outreach, Film Showing" value="<?= $val('category_other') ?>"><?= $why('category_other') ?>
              </div>
            </div>
          </div>

          <label class="admin-label" for="description">Description <span class="req">*</span></label>
          <textarea id="description" name="description" class="admin-input<?= $bad('description') ?>" rows="5" maxlength="2000" placeholder="Write event description here…"><?= $val('description') ?></textarea><?= $why('description') ?>
        </details>

        <details class="form-section form-fold"<?= $openIf('organizations') ?>>
          <summary><h2 class="form-section-head" id="sec-org"><span class="form-section-num">2</span><span>Organizers<small>The organization holding the event, and any co-organizers</small></span><?= $fold ?></h2></summary>
          <?php /* Organization picker: departments from Categories & Orgs (e.g. CITE) are
                   clickable and open to show their organizations (JPSSITE, SIES, ACpES…).
                   One organization, or two or more with "Multi-organization event". */
          $pickedIds = array_values(array_filter(array_merge([(string) ($f['organization_id'] ?? '')], $f['partner_ids'] ?? [])));
          $isMulti = !empty($f['multi_org']);
          $orgGroups = [];
          foreach ($departments as $d) {
              $members = array_values(array_filter($allOrganizations, fn($o) => ($o['department_id'] ?? '') === $d['id']));
              if ($members) $orgGroups[] = [$d['code'] ?: $d['name'], $d['name'], $members];
          }
          $ungrouped = array_values(array_filter($allOrganizations, fn($o) => !in_array($o['department_id'] ?? '', array_column($departments, 'id'), true)));
          if ($ungrouped) $orgGroups[] = ['Other organizations', '', $ungrouped];
          $ownOrg = $isAdmin ? '' : (string) $user['organization_id'];
          ?>
          <div class="org-field" data-org-field>
            <span class="admin-label" id="org-label">Organization <span class="req">*</span></span>
            <label class="admin-checkbox org-multi-toggle">
              <input type="checkbox" name="multi_org" data-multi-org <?= $isMulti ? 'checked' : '' ?>>
              Multi-organization event <span class="admin-muted">— two or more organizations hold it together</span>
            </label>
            <?php if (!$isAdmin): ?>
              <input type="hidden" name="organization_ids[]" value="<?= htmlspecialchars($ownOrg) ?>">
            <?php endif; ?>

            <div class="org-picker" role="group" aria-labelledby="org-label">
              <?php foreach ($orgGroups as [$heading, $fullName, $members]):
                $groupPicked = count(array_intersect(array_column($members, 'id'), $pickedIds)); ?>
                <details class="org-dept"<?= $groupPicked ? ' open' : '' ?>>
                  <summary>
                    <span class="org-dept-name"><?= htmlspecialchars($heading) ?></span>
                    <?php if ($fullName !== '' && $fullName !== $heading): ?><span class="org-dept-full"><?= htmlspecialchars($fullName) ?></span><?php endif; ?>
                    <span class="org-dept-count" data-dept-count><?= $groupPicked ? $groupPicked . ' selected' : count($members) . ' org' . (count($members) === 1 ? '' : 's') ?></span>
                  </summary>
                  <div class="org-dept-list">
                    <?php foreach ($members as $o):
                      $isOwn = $o['id'] === $ownOrg;
                      $isPicked = in_array($o['id'], $pickedIds, true) || $isOwn; ?>
                      <label class="org-option<?= $isOwn ? ' is-locked' : '' ?>">
                        <input type="checkbox" <?= $isOwn ? 'disabled data-own' : 'name="organization_ids[]"' ?> value="<?= htmlspecialchars($o['id']) ?>" data-org-name="<?= htmlspecialchars($o['name']) ?>" <?= $isPicked ? 'checked' : '' ?>>
                        <?php if (!empty($o['logo_url'])): ?><img src="<?= htmlspecialchars(asset_url($o['logo_url'])) ?>" alt="" class="admin-org-logo"><?php endif; ?>
                        <span><?= htmlspecialchars(organization_label($o)) ?><?= $isOwn ? ' <em>(your organization)</em>' : '' ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endforeach; ?>
              <?php if (empty($allOrganizations)): ?>
                <p class="admin-hint">No organizations yet — <a href="admin-taxonomy.php?tab=organizations">add one</a> first.</p>
              <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
              <div class="org-lead" data-org-lead<?= $isMulti ? '' : ' hidden' ?>>
                <label class="admin-label" for="organization_id">Main Organizer <span class="req">*</span></label>
                <select id="organization_id" name="organization_id" class="admin-input" data-lead-select>
                  <?php foreach ($allOrganizations as $o): ?>
                    <option value="<?= htmlspecialchars($o['id']) ?>" <?= ($f['organization_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars(organization_label($o)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <p class="admin-hint" data-org-hint>
              <?= $isAdmin
                ? 'Click a department to see its organizations. For a multi-organization event, the main organizer\'s Admins run the event; the others are shown as co-organizers.'
                : 'Your organization runs the event. Pick "Multi-organization event" to add the organizations you\'re holding it with — they\'re shown as co-organizers.' ?>
            </p><?= $why('organizations') ?>
          </div>
        </details>

        <details class="form-section form-fold"<?= $openIf('audiences') ?>>
          <summary><h2 class="form-section-head" id="sec-audience"><span class="form-section-num">3</span><span>Audience<small>Who the event is for</small></span><?= $fold ?></h2></summary>
          <span class="admin-label" id="audience-label">Open to <span class="req">*</span> <span class="admin-muted">— pick everyone it's for</span></span>
          <?php $formAudiences = isset($f['audiences']) || isset($f['audience']) ? event_audiences($f) : ['students']; ?>
          <div class="admin-radio-row" role="group" aria-labelledby="audience-label" data-audiences>
            <?php foreach (AUDIENCE_GROUPS as $key => $label): ?>
              <label class="admin-radio"><input type="checkbox" name="audiences[]" value="<?= $key ?>" <?= in_array($key, $formAudiences, true) ? 'checked' : '' ?>> <?= $label ?></label>
            <?php endforeach; ?>
          </div>
          <p class="admin-hint">Outsiders: guests from outside LCUP. Students and faculty register on the site; outsiders are shown the event as open to them.</p>
          <p class="admin-hint admin-hint-error" data-audience-error hidden>Pick at least one: Students, Faculty or Outsiders.</p><?= $why('audiences') ?>

          <div data-year-levels>
            <span class="admin-label">Open to Year Levels</span>
            <div class="admin-radio-row">
              <?php foreach (YEAR_LEVELS as $y => $label): ?>
                <label class="admin-radio"><input type="checkbox" name="year_levels[]" value="<?= $y ?>" <?= in_array($y, $formLevels, true) ? 'checked' : '' ?>> <?= $label ?></label>
              <?php endforeach; ?>
            </div>
            <p class="admin-hint">Pick none to open the event to every year level. Students outside the picked year levels can't register; irregular students can if any of their subjects' year levels is picked.</p>
          </div>

          <label class="admin-label" for="required_for">Required Attendance</label>
          <select id="required_for" name="required_for" class="admin-input">
            <option value="">Not required — anyone it's open to may join</option>
            <?php foreach (REQUIRED_FOR as $key => $label): ?>
              <option value="<?= $key ?>" <?= ($f['required_for'] ?? '') === $key ? 'selected' : '' ?>>Required for <?= htmlspecialchars(lcfirst($label)) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="admin-hint">Required attendees are always notified when the event is published — in the site and by email — and see it marked <strong>Required</strong>. Only people the event is open to (the audience and year levels above) can be required.</p>
        </details>

        <details class="form-section form-fold"<?= $openIf('start_date', 'end_date', 'end_time') ?>>
          <summary><h2 class="form-section-head" id="sec-when"><span class="form-section-num">4</span><span>Date &amp; Time<small>When it starts and ends</small></span><?= $fold ?></h2></summary>
          <div class="admin-four-col">
            <div>
              <label class="admin-label" for="start_date">Start Date <span class="req">*</span></label>
              <input type="date" id="start_date" name="start_date" class="admin-input<?= $bad('start_date') ?>" value="<?= $val('start_date') ?>" min="<?= $minDate('start_date') ?>" required><?= $why('start_date') ?>
            </div>
            <div>
              <label class="admin-label" for="start_time">Start Time</label>
              <input type="time" id="start_time" name="start_time" class="admin-input" value="<?= $time('start_time') ?>">
            </div>
            <div>
              <label class="admin-label" for="end_date">End Date <span class="req">*</span></label>
              <input type="date" id="end_date" name="end_date" class="admin-input<?= $bad('end_date') ?>" value="<?= $val('end_date') ?>" min="<?= $minDate('end_date') ?>" data-min="<?= $minDate('end_date') ?>" required><?= $why('end_date') ?>
            </div>
            <div>
              <label class="admin-label" for="end_time">End Time</label>
              <input type="time" id="end_time" name="end_time" class="admin-input<?= $bad('end_time') ?>" value="<?= $time('end_time') ?>"><?= $why('end_time') ?>
            </div>
          </div>
          <!-- Other events on these dates, and clashes (filled by the script below) -->
          <div class="schedule-hint" data-schedule-hint hidden></div>
        </details>

        <details class="form-section form-fold"<?= $openIf('venue', 'online_event_link') ?>>
          <summary><h2 class="form-section-head" id="sec-venue"><span class="form-section-num">5</span><span>Venue<small>Where it happens: on campus, off campus or online</small></span><?= $fold ?></h2></summary>
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
        </details>

        <details class="form-section form-fold">
          <summary><h2 class="form-section-head" id="sec-options"><span class="form-section-num">6</span><span>Publishing Options<small>Registration and notifications</small></span><?= $fold ?></h2></summary>
          <div class="admin-checkbox-row">
            <label class="admin-checkbox"><input type="checkbox" name="enable_registration" <?= $checked('enable_registration') ?>> Enable Registration</label>
            <label class="admin-checkbox"><input type="checkbox" name="send_notification" <?= $checked('send_notification') ?>> Send Notification to Users</label>
          </div>
          <p class="admin-hint">With "Send Notification" on: publishing a new event notifies everyone it's open to (the audience and year levels above); saving changes to a published event notifies the people registered for it and the required attendees. Notifications show in the site and are emailed too<?= mail_enabled() ? '' : ' (once email is set up — a Super Admin can check Database update › Site setup)' ?>.</p>
        </details>
      </div>

      <div class="admin-side-cards">
        <div class="admin-card">
          <details class="form-fold"<?= $openIf('poster_file') ?>>
          <summary><h2 class="form-section-head" id="sec-poster"><span class="form-section-num">7</span><span>Event Poster<small>Shown on the event page and the event cards</small></span><?= $fold ?></h2></summary>
          <?php if (!empty($f['poster_url'])): ?>
            <img src="<?= htmlspecialchars(asset_url($f['poster_url'])) ?>" alt="Current poster" class="admin-image-preview">
          <?php endif; ?>
          <label class="admin-label" for="poster_file">Upload an image</label>
          <input type="file" id="poster_file" name="poster_file" class="admin-input<?= $bad('poster_file') ?>" accept="image/jpeg,image/png,image/webp,image/gif"><?= $why('poster_file') ?>
          <p class="admin-hint">JPG, PNG, WEBP or GIF, up to 5 MB. Replaces the current poster.</p>
          <label class="admin-label" for="poster_url">…or paste an image URL</label>
          <input type="text" id="poster_url" name="poster_url" class="admin-input" placeholder="https://…" value="<?= $val('poster_url') ?>">
          </details>
        </div>

        <div class="admin-card">
          <details class="form-fold"<?= $openIf('registration_limit', 'registration_deadline', 'contact_email') ?>>
          <summary><h2 class="form-section-head" id="sec-contact"><span class="form-section-num">8</span><span>Registration &amp; Contact<small>Limits, deadline and who to ask</small></span><?= $fold ?></h2></summary>
          <label class="admin-label" for="registration_limit">Registration Limit</label>
          <input type="number" min="1" id="registration_limit" name="registration_limit" class="admin-input<?= $bad('registration_limit') ?>" placeholder="Leave empty for no limit" value="<?= $val('registration_limit') ?>"><?= $why('registration_limit') ?>

          <label class="admin-label" for="registration_deadline">Registration Deadline</label>
          <input type="date" id="registration_deadline" name="registration_deadline" class="admin-input<?= $bad('registration_deadline') ?>" value="<?= $val('registration_deadline') ?>" min="<?= $minDate('registration_deadline') ?>"><?= $why('registration_deadline') ?>

          <label class="admin-label" for="contact_email">Contact Email</label>
          <input type="email" id="contact_email" name="contact_email" class="admin-input<?= $bad('contact_email') ?>" placeholder="Enter email" value="<?= $val('contact_email') ?>"><?= $why('contact_email') ?>

          <label class="admin-label" for="contact_number">Contact Number</label>
          <input type="text" id="contact_number" name="contact_number" class="admin-input" placeholder="Enter no." value="<?= $val('contact_number') ?>">
          </details>
        </div>

        <?php if ($editId): ?>
          <div class="admin-card admin-card-links">
            <h2 class="admin-card-title">More for this event</h2>
            <a href="event-registrations.php?event=<?= urlencode($editId) ?>"><?= icon('people', 16) ?> See registrations &amp; attendance</a>
            <a href="attendance-print.php?event=<?= urlencode($editId) ?>"><?= icon('print', 16) ?> Print the attendance sheet</a>
            <a href="event-evaluations.php?event=<?= urlencode($editId) ?>"><?= icon('evaluation', 16) ?> See evaluation results</a>
            <a href="../event-details.php?id=<?= urlencode($editId) ?>"><?= icon('view', 16) ?> View the event page</a>
          </div>
        <?php endif; ?>
      </div>

      <?php /* 9. Program Flow: the event's parts in running order (event_programs),
               saved with the event. Rows are added / moved / removed by the script below. */
      $programRows = $f['programs'] ?? [];
      $programRow = fn(array $p): string =>
          '<li class="pf-row" data-pf-row>'
          . '<input type="hidden" name="programs[id][]" value="' . htmlspecialchars((string) ($p['id'] ?? '')) . '">'
          . '<span class="pf-num" aria-hidden="true"></span>'
          . '<div class="pf-fields">'
          . '<input type="text" name="programs[title][]" class="admin-input" maxlength="200" placeholder="What happens, e.g. Opening Prayer" aria-label="Part of the program" value="' . htmlspecialchars((string) ($p['title'] ?? '')) . '">'
          . '<input type="text" name="programs[speaker][]" class="admin-input" maxlength="120" placeholder="Speaker / in charge (optional)" aria-label="Speaker or person in charge" value="' . htmlspecialchars((string) ($p['speaker'] ?? '')) . '">'
          . '<input type="text" name="programs[description][]" class="admin-input pf-notes" maxlength="500" placeholder="Notes, e.g. time or room (optional)" aria-label="Notes" value="' . htmlspecialchars((string) ($p['description'] ?? '')) . '">'
          . '</div>'
          . '<div class="pf-tools">'
          . '<button type="button" class="btn-sm" data-pf-up aria-label="Move up" title="Move up">↑</button>'
          . '<button type="button" class="btn-sm" data-pf-down aria-label="Move down" title="Move down">↓</button>'
          . '<button type="button" class="btn-sm btn-sm-danger" data-pf-remove aria-label="Remove" title="Remove">✕</button>'
          . '</div></li>';
      ?>
      <div class="admin-card admin-card-wide">
        <details class="form-fold"<?= $openIf('programs') ?>>
          <summary><h2 class="form-section-head" id="sec-program"><span class="form-section-num">9</span><span>Program Flow<small>The parts of the event in order — shown on the event page</small></span><?= $fold ?></h2></summary>
          <ol class="pf-list" data-pf-list>
            <?php foreach ($programRows as $p) echo $programRow($p); ?>
          </ol>
          <p class="admin-hint" data-pf-empty<?= $programRows ? ' hidden' : '' ?>>No program flow yet. Add the parts of the event in the order they happen.</p>
          <?= $why('programs') ?>
          <button type="button" class="btn btn-outline pf-add" data-pf-add>+ Add a part</button>
          <template data-pf-template><?= $programRow([]) ?></template>
        </details>
      </div>

      <div class="admin-actions">
        <a href="<?= $editId ? $listUrl : 'event-organizer.php' ?>" class="btn btn-outline">Cancel</a>
        <button type="submit" name="intent" value="draft" class="btn btn-muted">Save Draft</button>
        <button type="submit" name="intent" value="publish" class="btn btn-primary"><?= $needsApproval ? ($editId ? 'Save &amp; Send for Approval' : 'Send for Approval') : ($editId ? 'Save &amp; Publish' : 'Publish Event') ?></button>
      </div>
    </form>
    <?php else: ?>

    <!-- Update existing events: search + filters, 10 per page -->
    <form method="get" action="event-organizer.php" class="admin-search events-filter" role="search">
      <input type="hidden" name="view" value="update">
      <input type="search" name="q" class="admin-input" placeholder="Title, organization, category or venue" value="<?= htmlspecialchars($q) ?>" aria-label="Search events">
      <select name="status" class="admin-input" aria-label="Status">
        <option value="">Every status</option>
        <?php foreach ($statuses as $key => $label): ?>
          <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <select name="category" class="admin-input" aria-label="Category">
        <option value="">Every category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>" <?= $categoryFilter === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($isAdmin): ?>
        <select name="org" class="admin-input" aria-label="Organization">
          <option value="">Every organization</option>
          <?php foreach ($organizations as $o): ?>
            <option value="<?= htmlspecialchars($o['id']) ?>" <?= $orgFilter === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars(organization_label($o)) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="when" class="admin-input" aria-label="Date">
        <option value="">Any date</option>
        <?php foreach ($timeframes as $key => $label): ?>
          <option value="<?= $key ?>" <?= $whenFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline">Filter</button>
      <?php if ($filters): ?><a href="<?= $listUrl ?>" class="btn btn-muted">Clear</a><?php endif; ?>
    </form>

    <div class="section-head section-head-spaced">
      <h2><?= $isAdmin ? 'All Events' : htmlspecialchars($myOrgName) . ' Events' ?> (<?= $total ?><?= $filters ? ' of ' . count($allEvents) : '' ?>)</h2>
      <a href="event-organizer.php" class="btn btn-primary">+ Create Event</a>
    </div>
    <p class="admin-hint admin-hint-block">Published events become <strong>Completed</strong> automatically once they end. Use Mark Completed to close one early.</p>
    <div class="admin-table-wrap admin-table-scroll">
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
            <tr><td colspan="6" class="admin-table-empty">
              <?php if ($allEvents): ?>No events match your search. <a href="<?= $listUrl ?>">Clear the filters</a>.
              <?php else: ?>No events yet — <a href="event-organizer.php">create your first one</a>.<?php endif; ?>
            </td></tr>
          <?php endif; ?>
          <?php foreach ($events as $ev):
            $status = $ev['status'] ?? 'draft';
            $programCount = (int) ($ev['event_programs'][0]['count'] ?? 0);
          ?>
            <tr>
              <td>
                <a href="../event-details.php?id=<?= urlencode($ev['id']) ?>" class="admin-table-title"><?= htmlspecialchars($ev['title']) ?></a>
                <div class="admin-table-sub"><?= htmlspecialchars(implode(' · ', array_filter([event_category_label($ev, ''), label_of(VENUE_TYPES, $ev['venue_type'] ?? null, ''), 'For ' . event_audience_label($ev)]))) ?></div>
                <?php if (($requiredLabel = required_for_label($ev)) !== ''): ?><div class="admin-table-sub"><span class="required-tag">Required</span> <?= htmlspecialchars(substr($requiredLabel, strlen('Required '))) ?></div><?php endif; ?>
              </td>
              <td>
                <?php if (!empty($ev['organizations']['name'])): ?>
                  <span class="admin-org-cell">
                    <?php if (!empty($ev['organizations']['logo_url'])): ?><img src="<?= htmlspecialchars(asset_url($ev['organizations']['logo_url'])) ?>" alt="" class="admin-org-logo"><?php endif; ?>
                    <?= htmlspecialchars($ev['organizations']['name']) ?>
                  </span>
                  <?php if (!empty($partners[$ev['id']])): ?>
                    <div class="admin-table-sub">with <?= htmlspecialchars(implode(', ', array_column($partners[$ev['id']], 'name'))) ?></div>
                  <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="admin-nowrap"><?= htmlspecialchars(format_event_date($ev['start_date'] ?? null)) ?></td>
              <td><?= (int) ($counts[$ev['id']] ?? 0) ?><?= !empty($ev['registration_limit']) ? ' / ' . (int) $ev['registration_limit'] : '' ?></td>
              <td>
                <?php if ($status === 'draft' && ($ev['approval_status'] ?? null) === 'pending'): ?>
                  <span class="admin-status admin-status-pending" title="Waiting for a Super Admin's approval">Awaiting approval</span>
                  <?php if (!empty($ev['approval_note'])): ?><div class="admin-table-sub" title="<?= htmlspecialchars($ev['approval_note']) ?>">Note: <?= htmlspecialchars(excerpt($ev['approval_note'], 40)) ?></div><?php endif; ?>
                <?php elseif ($status === 'draft' && ($ev['approval_status'] ?? null) === 'rejected'): ?>
                  <span class="admin-status admin-status-cancelled" title="<?= htmlspecialchars($ev['approval_note'] ?? '') ?>">Not approved</span>
                <?php else: ?>
                  <span class="admin-status admin-status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                <?php endif; ?>
              </td>
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
                      <form method="POST" action="event-organizer.php" data-confirm="Cancel this event? Everyone registered, and anyone required to attend, will be notified." data-confirm-title="Cancel event" data-confirm-ok="Cancel event" data-confirm-cancel="Keep it" data-confirm-tone="danger">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="btn-sm btn-sm-danger">Mark Cancelled</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($status === 'published'): ?>
                      <form method="POST" action="event-organizer.php">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn-sm btn-sm-accent">Mark Completed</button>
                      </form>
                    <?php endif; ?>

                    <?php if (in_array($status, ['cancelled', 'completed'], true)): ?>
                      <form method="POST" action="event-organizer.php">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                        <input type="hidden" name="status" value="draft">
                        <button type="submit" class="btn-sm">Reopen as Draft</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                      <form method="POST" action="event-organizer.php" class="row-more-danger" data-confirm="Delete this event? Its registrations and schedule are deleted too. This can't be undone." data-confirm-title="Delete event" data-confirm-ok="Delete" data-confirm-tone="danger">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                        <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </details>

                <details class="row-more">
                  <summary class="btn-sm">Manage <span aria-hidden="true">▾</span></summary>
                  <div class="row-more-menu" role="group" aria-label="Manage <?= htmlspecialchars($ev['title']) ?>">
                    <a href="event-organizer.php?edit=<?= urlencode($ev['id']) ?>#sec-program" class="btn-sm">Program Flow (<?= $programCount ?>)</a>
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

    <?= render_pagination($pager, $pageUrl, 'event', 'events') ?>
    <?php endif; ?>
  </main>

</div>

<script>
(function () {
  var form = document.getElementById('event-form');
  if (!form) return; // Update Events list: no form on the page

  // Dates: nothing before today (the pickers' min); the end date can't be before the start date.
  var startDate = document.getElementById('start_date');
  var endDate = document.getElementById('end_date');
  function syncEndMin() {
    var base = endDate.getAttribute('data-min') || '';
    endDate.min = startDate.value && startDate.value > base ? startDate.value : base;
    if (endDate.value && endDate.value < endDate.min) endDate.value = endDate.min;
  }
  startDate.addEventListener('change', syncEndMin);
  syncEndMin();

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

  // Organization picker. Single: one organization (picking one unpicks the
  // rest). Multi: two or more, and admins choose which one is the main organizer.
  var multi = form.querySelector('[data-multi-org]');
  var orgBoxes = Array.prototype.slice.call(form.querySelectorAll('.org-option input[type="checkbox"]:not([data-own])'));
  var ownBox = form.querySelector('.org-option input[data-own]');
  var leadBox = form.querySelector('[data-org-lead]');
  var leadSelect = form.querySelector('[data-lead-select]');
  function pickedOrgs() { return orgBoxes.filter(function (b) { return b.checked; }); }
  function syncOrgs(changed) {
    var isMulti = multi.checked;
    if (!isMulti) {
      if (ownBox) {
        orgBoxes.forEach(function (b) { b.checked = false; }); // moderators: just their own organization
      } else {
        var keep = changed && changed.checked ? changed : pickedOrgs()[0];
        orgBoxes.forEach(function (b) { if (b !== keep) b.checked = false; });
      }
    }
    orgBoxes.forEach(function (b) { b.disabled = !isMulti && !!ownBox; });
    if (leadSelect) {
      var picked = pickedOrgs().map(function (b) { return b.value; });
      leadBox.hidden = !isMulti;
      Array.prototype.forEach.call(leadSelect.options, function (o) { o.hidden = o.disabled = picked.indexOf(o.value) === -1; });
      if (picked.indexOf(leadSelect.value) === -1 && picked.length) leadSelect.value = picked[0];
    }
    form.querySelectorAll('.org-dept').forEach(function (dept) {
      var all = dept.querySelectorAll('.org-option input');
      var n = dept.querySelectorAll('.org-option input:checked').length;
      dept.querySelector('[data-dept-count]').textContent = n ? n + ' selected' : all.length + (all.length === 1 ? ' org' : ' orgs');
      dept.classList.toggle('has-picked', n > 0);
    });
  }
  multi.addEventListener('change', function () { syncOrgs(null); });
  orgBoxes.forEach(function (b) { b.addEventListener('change', function () { syncOrgs(b); }); });
  form.addEventListener('submit', function (e) {
    var n = pickedOrgs().length + (ownBox ? 1 : 0);
    var need = multi.checked ? 2 : 1;
    if (n < need) {
      e.preventDefault();
      var hint = form.querySelector('[data-org-hint]');
      hint.textContent = multi.checked ? 'Pick two or more organizations for a multi-organization event (click a department to see its organizations).' : 'Pick the organization holding this event (click a department to see its organizations).';
      hint.classList.add('admin-hint-error');
      var firstDept = form.querySelector('.org-dept');
      openFold(hint);
      if (firstDept) firstDept.open = true;
      form.querySelector('[data-org-field]').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, true);
  syncOrgs(null);

  // "Other" category: say what kind of event it is.
  var category = document.getElementById('category_id');
  var otherBox = document.querySelector('[data-category-other]');
  var otherInput = document.getElementById('category_other');
  function syncCategory() {
    var opt = category.options[category.selectedIndex];
    var isOther = !!(opt && opt.hasAttribute('data-other'));
    otherBox.hidden = !isOther;
    otherInput.required = isOther;
  }

  // Year levels only apply to events students can join.
  var levels = document.querySelector('[data-year-levels]');
  // Year levels only when Students is picked; at least one audience is needed.
  var audienceBoxes = form.querySelectorAll('input[name="audiences[]"]');
  var audienceError = form.querySelector('[data-audience-error]');
  function syncAudience() {
    var students = form.querySelector('input[name="audiences[]"][value="students"]');
    levels.hidden = !(students && students.checked);
    if (form.querySelector('input[name="audiences[]"]:checked')) audienceError.hidden = true;
  }
  form.addEventListener('submit', function (e) {
    if (form.querySelector('input[name="audiences[]"]:checked')) return;
    e.preventDefault();
    audienceError.hidden = false;
    openFold(audienceBoxes[0]);
    audienceBoxes[0].focus();
    audienceBoxes[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, true);

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

  form.addEventListener('change', function () { syncCategory(); syncVenue(); syncAudience(); checkSchedule(); });
  ['title', 'venue'].forEach(function (id) { document.getElementById(id).addEventListener('input', checkSchedule); });

  // ---- Sections 1–9 fold open and shut ----
  // A field that needs fixing opens the section it's in.
  function openFold(el) {
    for (var d = el && el.closest('details.form-fold'); d; d = d.parentElement && d.parentElement.closest('details.form-fold')) d.open = true;
  }
  // A link to a section (e.g. "Edit program flow" → #sec-program) opens it.
  var linked = location.hash && document.getElementById(location.hash.slice(1));
  if (linked && linked.closest('details.form-fold')) {
    openFold(linked);
    linked.scrollIntoView({ block: 'start' });
  }

  // ---- 9. Program Flow: add, move and remove parts ----
  var pfList = form.querySelector('[data-pf-list]');
  var pfTemplate = form.querySelector('[data-pf-template]');
  var pfEmpty = form.querySelector('[data-pf-empty]');
  var pfCount = 0;
  function pfSync() {
    var rows = pfList.querySelectorAll('[data-pf-row]');
    rows.forEach(function (row, i) {
      row.querySelector('.pf-num').textContent = i + 1;
      var name = row.querySelector('input[name="programs[title][]"]');
      if (!name.id) name.id = 'pf-title-' + (++pfCount); // so a missing name can be highlighted
      row.querySelector('[data-pf-up]').disabled = i === 0;
      row.querySelector('[data-pf-down]').disabled = i === rows.length - 1;
    });
    pfEmpty.hidden = rows.length > 0;
  }
  form.querySelector('[data-pf-add]').addEventListener('click', function () {
    pfList.appendChild(pfTemplate.content.firstElementChild.cloneNode(true));
    pfSync();
    pfList.lastElementChild.querySelector('input[type="text"]').focus();
  });
  pfList.addEventListener('click', function (e) {
    var btn = e.target.closest('button');
    if (!btn) return;
    var row = btn.closest('[data-pf-row]');
    if (btn.hasAttribute('data-pf-remove')) row.remove();
    else if (btn.hasAttribute('data-pf-up') && row.previousElementSibling) pfList.insertBefore(row, row.previousElementSibling);
    else if (btn.hasAttribute('data-pf-down') && row.nextElementSibling) pfList.insertBefore(row.nextElementSibling, row);
    pfSync();
  });
  pfSync();

  // ---- Fields that still need information ----
  // Checked before the form is sent (the same rules the server applies):
  // every field with a problem gets a red border and the reason under it,
  // and the page scrolls to the first one. (The organization and audience
  // pickers have their own checks above.)
  var summary = document.querySelector('[data-form-summary]');
  function byId(id) { return document.getElementById(id); }
  function markInvalid(el, message) {
    openFold(el);
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
    function shown(id) { var el = byId(id); return el && !el.closest('[hidden]'); }
    function empty(id) { var el = byId(id); return shown(id) && !el.readOnly && el.value.trim() === ''; }
    var v = function (id) { return (byId(id) && byId(id).value.trim()) || ''; };

    if (empty('title')) add('title', 'Enter the event title.');
    if (empty('category_id')) add('category_id', 'Choose a category.');
    if (empty('category_other')) add('category_other', 'Type what kind of event it is.');
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
    pfList.querySelectorAll('[data-pf-row]').forEach(function (row) {
      var boxes = row.querySelectorAll('input[type="text"]');
      if (boxes[0].value.trim() === '' && (boxes[1].value.trim() || boxes[2].value.trim())) add(boxes[0].id, 'Give this part a name (what happens), or remove it.');
    });
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
    var alreadyStopped = e.defaultPrevented; // the organization / audience checks scrolled already
    e.preventDefault();
    problems.forEach(function (p) { markInvalid(p[0], p[1]); });
    if (summary) {
      summary.textContent = problems.length === 1
        ? '1 field needs your attention before the event can be saved (highlighted in red below).'
        : problems.length + ' fields need your attention before the event can be saved (highlighted in red below).';
      summary.hidden = false;
    }
    if (!alreadyStopped) focusFirstInvalid();
  }, true);
  // Fixing a field takes its red border away.
  ['input', 'change'].forEach(function (type) {
    form.addEventListener(type, function (e) {
      if (e.target.classList && e.target.classList.contains('is-invalid')) clearInvalid(e.target);
    });
  });
  // Back from the server with errors: go straight to the first highlighted field.
  if (form.querySelector('.is-invalid')) focusFirstInvalid();

  // Saving: ask first (the site's pop-up, assets/js/confirm.js), then send it
  // once — the buttons lock so a second click can't save the event twice, and
  // a new event's form is cleared as soon as it's sent (the server fills it
  // in again only if something needs fixing).
  var isNew = !form.querySelector('input[name="id"]') || !form.querySelector('input[name="id"]').value;
  var sending = false;
  form.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return; // another check (organizations) stopped it
    e.preventDefault();
    if (sending) return;
    var submitter = e.submitter && e.submitter.name === 'intent' ? e.submitter : form.querySelector('button[name="intent"][value="publish"]');
    var intent = submitter.value;
    var data = new FormData(form);
    var title = (data.get('title') || '').trim();
    var start = data.get('start_date');
    var when = start ? new Date(start + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
    var venueType = data.get('venue_type');
    var place = venueType === 'online' ? 'Online' : (data.get('venue') || '').trim();
    var needsApproval = form.hasAttribute('data-needs-approval');
    var what = intent === 'draft'
      ? 'It\'s saved as a draft — only admins can see it until it\'s published.'
      : needsApproval
        ? 'It goes to a Super Admin for approval and is published once approved.'
        : 'It will be published on the Event Calendar' + (data.get('send_notification') ? ' and everyone it\'s open to will be notified (in the site and by email).' : '.') + (data.get('required_for') ? ' Required attendees are notified either way.' : '');
    var message = '“' + title + '”' + (when ? ' — ' + when : '') + (place ? ' · ' + place : '') + '\n\n' + what
      + (isNew ? '\n\nThe form is cleared once it\'s sent.' : '');
    var options = {
      title: intent === 'draft' ? 'Save as draft?' : (needsApproval ? 'Send for approval?' : (isNew ? 'Publish this event?' : 'Save and publish?')),
      ok: submitter.textContent.trim(),
      cancel: 'Keep editing',
    };
    var ask = window.cehConfirm ? window.cehConfirm(message, options) : Promise.resolve(window.confirm(options.title + '\n\n' + message));
    ask.then(function (yes) {
      if (!yes || sending) return;
      sending = true;
      // The pressed button's choice travels in a hidden field (disabled buttons aren't sent).
      var keep = document.createElement('input');
      keep.type = 'hidden'; keep.name = 'intent'; keep.value = intent; keep.setAttribute('data-sending', '');
      submitter.setAttribute('data-label', submitter.textContent);
      form.appendChild(keep);
      clearTimeout(timer);
      form.querySelectorAll('button[name="intent"]').forEach(function (b) { b.disabled = true; });
      submitter.textContent = 'Saving…';
      form.setAttribute('aria-busy', 'true');
      form.submit(); // what's typed is captured here, so the form can be cleared right after
      if (isNew) {
        setTimeout(function () {
          form.reset();
          keep.value = intent; // a reset doesn't touch hidden fields' values, but be sure
          hint.hidden = true;
        }, 0);
      }
    });
  });
  // Back to this page from the browser's history after sending: ready to use again.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    sending = false;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('input[data-sending]').forEach(function (i) { i.remove(); });
    form.querySelectorAll('button[name="intent"]').forEach(function (b) {
      b.disabled = false;
      if (b.hasAttribute('data-label')) { b.textContent = b.getAttribute('data-label'); b.removeAttribute('data-label'); }
    });
  });
  syncCategory();
  syncVenue();
  syncAudience();
  checkSchedule();
})();
</script>
<script>
// Events table "Edit ▾" / "Manage ▾" menus: one open at a time; a click outside or Escape closes it.
(function () {
  var menus = document.querySelectorAll('details.row-more');
  menus.forEach(function (menu) {
    menu.addEventListener('toggle', function () {
      if (menu.open) menus.forEach(function (m) { if (m !== menu) m.open = false; });
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
<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
