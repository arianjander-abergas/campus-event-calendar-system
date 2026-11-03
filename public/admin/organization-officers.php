<?php
/**
 * An organization's officers (organization_officers), with photos.
 * Admins: any organization. Moderators: their own organization only.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_staff();
$isAdmin = ($user['role'] ?? '') === 'admin';

$orgId = get_param('org');
if (!$isAdmin && $orgId !== ($user['organization_id'] ?? '')) {
    flash('error', "Admins can only manage their own organization's officers.");
    header('Location: organization-officers.php?org=' . urlencode((string) $user['organization_id']));
    exit;
}
$org = is_uuid($orgId) ? get_organization_admin($orgId) : null;
if (!$org) {
    flash('error', "That organization doesn't exist anymore.");
    header('Location: admin-taxonomy.php?tab=organizations');
    exit;
}
$back = 'organization-officers.php?org=' . urlencode($orgId);
$errors = [];

// Current academic year, e.g. "2026-2027" (the school year starts in August).
$startYear = (int) date('Y') - ((int) date('n') < 8 ? 1 : 0);
$defaultYear = $startYear . '-' . ($startYear + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    $officerId = post_param('id');

    if ($action === 'save') {
        $position = post_param('position') === 'other' ? post_param('position_other') : post_param('position');
        $yearLevel = post_param('year_level');
        $fields = [
            'organization_id' => $orgId,
            'full_name' => post_param('full_name'),
            'position' => $position,
            'academic_year' => post_param('academic_year'),
            'year_level' => in_array($yearLevel, ['1', '2', '3', '4', '5'], true) ? (int) $yearLevel : null,
            'course' => post_param('course') ?: null,
            'sort_order' => post_param('sort_order') !== '' ? (int) post_param('sort_order') : (OFFICER_POSITIONS[$position] ?? 20),
            'is_active' => isset($_POST['is_active']),
            'photo_url' => post_param('photo_url') ?: null,
        ];

        if ($fields['full_name'] === '') $errors[] = "Officer's name is required.";
        if ($fields['position'] === '') $errors[] = 'Position is required.';
        if (!preg_match('/^\d{4}-\d{4}$/', $fields['academic_year'])) $errors[] = 'Academic year must look like 2026-2027.';

        if (!$errors) {
            $upload = upload_public_image('officer-photos', $_FILES['photo_file'] ?? [], $orgId);
            if ($upload['ok']) {
                $fields['photo_url'] = $upload['url'];
            } elseif (!empty($upload['message'])) {
                $errors[] = $upload['message'];
            }
        }
        if (!$errors) {
            $result = save_officer_admin(is_uuid($officerId) ? $officerId : null, $fields);
            if ($result['ok']) {
                flash('success', $result['message']);
                header('Location: ' . $back);
                exit;
            }
            $errors[] = $result['message'];
        }
        $editing = $fields + ['id' => $officerId, 'position_choice' => post_param('position')];
    } elseif ($action === 'delete' && is_uuid($officerId)) {
        $result = delete_officer_admin($officerId);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: ' . $back);
        exit;
    }
}

$officers = get_org_officers_admin($orgId);
if (!isset($editing)) {
    $editing = null;
    foreach ($officers as $o) {
        if ($o['id'] === get_param('edit')) $editing = $o;
    }
}
$val = fn(string $key, string $default = '') => htmlspecialchars((string) ($editing[$key] ?? $default));
$positionChoice = $editing['position_choice'] ?? (isset(OFFICER_POSITIONS[$editing['position'] ?? '']) ? $editing['position'] : (!empty($editing['position']) ? 'other' : 'President'));

// Group the list by academic year (newest first).
$byYear = [];
foreach ($officers as $o) {
    $byYear[$o['academic_year']][] = $o;
}
krsort($byYear);

$activeNav = $isAdmin ? 'admin-taxonomy' : 'my-officers';
$breadcrumbs = [$isAdmin ? ['Categories & Orgs', 'admin-taxonomy.php?tab=organizations'] : ['Dashboard', '../enduser/dashboard.php'], [$org['name'], null], ['Officers', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title>Officers: <?= htmlspecialchars($org['name']) ?> — Campus Event Hub</title>
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
        <h1 class="page-title"><?= htmlspecialchars($org['name']) ?> — Officers</h1>
        <p class="page-sub">Shown on the public Organizations page (active officers of the latest academic year).</p>
      </div>
      <a href="../organizations.php#org-<?= htmlspecialchars($orgId) ?>" class="btn btn-outline">View public page</a>
    </div>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-split">
      <form method="POST" action="<?= htmlspecialchars($back) ?>" class="admin-card" data-autoreset enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <?php if (!empty($editing['id'])): ?><input type="hidden" name="id" value="<?= $val('id') ?>"><?php endif; ?>
        <h2 class="admin-card-title"><?= !empty($editing['id']) ? 'Edit officer' : 'Add an officer' ?></h2>

        <label class="admin-label" for="full_name">Full Name <span class="req">*</span></label>
        <input type="text" id="full_name" name="full_name" class="admin-input" value="<?= $val('full_name') ?>" required>

        <label class="admin-label" for="position">Position <span class="req">*</span></label>
        <select id="position" name="position" class="admin-input">
          <?php foreach (OFFICER_POSITIONS as $pos => $order): ?>
            <option value="<?= htmlspecialchars($pos) ?>" <?= $positionChoice === $pos ? 'selected' : '' ?>><?= htmlspecialchars($pos) ?></option>
          <?php endforeach; ?>
          <option value="other" <?= $positionChoice === 'other' ? 'selected' : '' ?>>Other (type below)</option>
        </select>
        <input type="text" name="position_other" class="admin-input admin-input-follow" placeholder="Other position, e.g. Business Manager" value="<?= $positionChoice === 'other' ? $val('position') : '' ?>">

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="academic_year">Academic Year <span class="req">*</span></label>
            <input type="text" id="academic_year" name="academic_year" class="admin-input" placeholder="2026-2027" pattern="\d{4}-\d{4}" value="<?= $val('academic_year', $defaultYear) ?>" required>
          </div>
          <div>
            <label class="admin-label" for="sort_order">Display Order</label>
            <input type="number" id="sort_order" name="sort_order" class="admin-input" min="0" placeholder="Auto from position" value="<?= $val('sort_order') ?>">
          </div>
        </div>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="year_level">Year Level</label>
            <select id="year_level" name="year_level" class="admin-input">
              <option value="">—</option>
              <?php for ($y = 1; $y <= 5; $y++): ?>
                <option value="<?= $y ?>" <?= $val('year_level') === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label class="admin-label" for="course">Course</label>
            <input type="text" id="course" name="course" class="admin-input" placeholder="e.g. BSIT" value="<?= $val('course') ?>">
          </div>
        </div>

        <?php if (!empty($editing['photo_url'])): ?>
          <img src="<?= htmlspecialchars(asset_url($editing['photo_url'])) ?>" alt="Current photo" class="admin-logo-preview">
        <?php endif; ?>
        <label class="admin-label" for="photo_file">Photo</label>
        <input type="file" id="photo_file" name="photo_file" class="admin-input" accept="image/jpeg,image/png,image/webp,image/gif">
        <input type="hidden" name="photo_url" value="<?= $val('photo_url') ?>">

        <label class="admin-checkbox admin-checkbox-spaced"><input type="checkbox" name="is_active" <?= ($editing === null || !empty($editing['is_active'])) ? 'checked' : '' ?>> Active (shown on the public page)</label>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary"><?= !empty($editing['id']) ? 'Save changes' : 'Add officer' ?></button>
          <?php if (!empty($editing['id'])): ?><a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>

      <div>
        <?php if (empty($officers)): ?>
          <div class="admin-table-wrap"><p class="admin-table-empty">No officers yet — add the President first.</p></div>
        <?php endif; ?>
        <?php foreach ($byYear as $year => $list): ?>
          <h2 class="admin-year-heading">Academic Year <?= htmlspecialchars($year) ?></h2>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Photo</th><th>Name</th><th>Position</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
              <tbody>
                <?php foreach ($list as $o): ?>
                  <tr>
                    <td>
                      <?php if (!empty($o['photo_url'])): ?>
                        <img src="<?= htmlspecialchars(asset_url($o['photo_url'])) ?>" alt="" class="admin-table-logo admin-table-avatar">
                      <?php else: ?>
                        <span class="admin-table-logo admin-table-avatar admin-table-logo-initials"><?= htmlspecialchars(initials($o['full_name'])) ?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong><?= htmlspecialchars($o['full_name']) ?></strong>
                      <?php if (!empty($o['course']) || !empty($o['year_level'])): ?>
                        <div class="admin-table-sub"><?= htmlspecialchars(trim(($o['course'] ?? '') . (!empty($o['year_level']) ? ' · Year ' . $o['year_level'] : ''), ' ·')) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($o['position']) ?></td>
                    <td><span class="admin-status admin-status-<?= !empty($o['is_active']) ? 'published' : 'draft' ?>"><?= !empty($o['is_active']) ? 'Active' : 'Inactive' ?></span></td>
                    <td class="admin-row-actions">
                      <a href="<?= htmlspecialchars($back) ?>&amp;edit=<?= urlencode($o['id']) ?>" class="btn-sm">Edit</a>
                      <form method="POST" action="<?= htmlspecialchars($back) ?>" data-confirm="Remove this officer from the list?" data-confirm-title="Remove officer" data-confirm-ok="Remove" data-confirm-tone="danger">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($o['id']) ?>">
                        <button type="submit" class="btn-sm btn-sm-danger">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
