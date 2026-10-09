<?php
/**
 * Admin: categories, departments and organizations (with logos, their
 * department and a link to each org's officers).
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$tab = in_array(get_param('tab'), ['departments', 'organizations'], true) ? get_param('tab') : 'categories';
$editCategoryId = get_param('edit_category');
$editDeptId = get_param('edit_department');
$editOrgId = get_param('edit_org');
$editCategory = $editCategoryId !== '' ? get_category_admin($editCategoryId) : null;
$editDept = $editDeptId !== '' ? get_department_admin($editDeptId) : null;
$editOrg = $editOrgId !== '' ? get_organization_admin($editOrgId) : null;
$departments = get_departments();
$errors = [];

// ---- Department save (create or update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_department') {
    $existingId = post_param('id');
    $fields = [
        'name' => post_param('name'),
        'code' => post_param('code') ?: null,
        'description' => post_param('description') ?: null,
    ];

    if ($fields['name'] === '') {
        $errors[] = 'Department name is required.';
    } elseif ($fields['code'] !== null && mb_strlen($fields['code']) > 12) {
        $errors[] = 'Keep the code short (12 characters or fewer), e.g. IT.';
    } else {
        $result = save_department_admin(is_uuid($existingId) ? $existingId : null, $fields);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: admin-taxonomy.php?tab=departments');
            exit;
        }
        $errors[] = $result['message'];
    }
    $editDept = $fields + ['id' => $existingId];
    $editDeptId = $existingId;
    $tab = 'departments';
}

// ---- Department delete (refused while organizations belong to it) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete_department') {
    $result = delete_department_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: admin-taxonomy.php?tab=departments');
    exit;
}

// ---- Category save (create or update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_category') {
    $existingId = post_param('id');
    $fields = ['name' => post_param('name'), 'description' => post_param('description') ?: null];

    if ($fields['name'] === '') {
        $errors[] = 'Category name is required.';
    } else {
        $result = is_uuid($existingId) ? update_category_admin($existingId, $fields) : create_category_admin($fields);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: admin-taxonomy.php?tab=categories');
            exit;
        }
        $errors[] = $result['message'];
    }
    $editCategory = $fields + ['id' => $existingId];
    $editCategoryId = $existingId;
    $tab = 'categories';
}

// ---- Category delete (refused while events use it) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete_category') {
    $result = delete_category_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: admin-taxonomy.php?tab=categories');
    exit;
}

// ---- Organization save (create or update, optional logo upload) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_organization') {
    $existingId = post_param('id');
    $fields = [
        'name' => post_param('name'),
        'description' => post_param('description') ?: null,
        'logo_url' => post_param('logo_url') ?: null,
        'department_id' => post_param('department_id') ?: null,
    ];

    if ($fields['name'] === '') {
        $errors[] = 'Organization name is required.';
    } elseif ($fields['department_id'] !== null && !in_array($fields['department_id'], array_column($departments, 'id'), true)) {
        $errors[] = 'Please choose a department from the list.';
    } else {
        $upload = upload_public_image('org-logos', $_FILES['logo_file'] ?? []);
        if ($upload['ok']) {
            $fields['logo_url'] = $upload['url'];
        } elseif (!empty($upload['message'])) {
            $errors[] = $upload['message'];
        }
    }
    if (!$errors) {
        $result = is_uuid($existingId) ? update_organization_admin($existingId, $fields) : create_organization_admin($fields);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: admin-taxonomy.php?tab=organizations');
            exit;
        }
        $errors[] = $result['message'];
    }
    $editOrg = $fields + ['id' => $existingId];
    $editOrgId = $existingId;
    $tab = 'organizations';
}

// ---- Organization delete (refused while events use it) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete_organization') {
    $result = delete_organization_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: admin-taxonomy.php?tab=organizations');
    exit;
}

$categories = get_all_categories_admin();
$allDepartments = get_all_departments_admin();
$organizations = get_all_organizations_admin();
$count = fn(array $row, string $rel): int => (int) ($row[$rel][0]['count'] ?? 0);

$cf = $editCategory ?? [];
$catVal = fn(string $key) => htmlspecialchars((string) ($cf[$key] ?? ''));
$df = $editDept ?? [];
$deptVal = fn(string $key) => htmlspecialchars((string) ($df[$key] ?? ''));
$of = $editOrg ?? [];
$orgVal = fn(string $key) => htmlspecialchars((string) ($of[$key] ?? ''));

$activeNav = 'admin-taxonomy';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Categories & Organizations', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories, Departments &amp; Organizations — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">Categories, Departments &amp; Organizations</h1>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-tabs" role="tablist">
      <a href="admin-taxonomy.php?tab=categories" class="admin-tab<?= $tab === 'categories' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'categories' ? 'true' : 'false' ?>">Categories (<?= count($categories) ?>)</a>
      <a href="admin-taxonomy.php?tab=departments" class="admin-tab<?= $tab === 'departments' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'departments' ? 'true' : 'false' ?>">Departments (<?= count($allDepartments) ?>)</a>
      <a href="admin-taxonomy.php?tab=organizations" class="admin-tab<?= $tab === 'organizations' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'organizations' ? 'true' : 'false' ?>">Organizations (<?= count($organizations) ?>)</a>
    </div>

    <?php if ($tab === 'categories'): ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=categories" class="admin-card" data-autoreset>
          <input type="hidden" name="action" value="save_category">
          <?php if (!empty($cf['id'])): ?><input type="hidden" name="id" value="<?= $catVal('id') ?>"><?php endif; ?>
          <h2 class="admin-card-title"><?= !empty($cf['id']) ? 'Edit Category' : 'Add Category' ?></h2>

          <label class="admin-label" for="cat_name">Name <span class="req">*</span></label>
          <input type="text" id="cat_name" name="name" class="admin-input" placeholder="e.g. Seminar" value="<?= $catVal('name') ?>" required>

          <label class="admin-label" for="cat_description">Description</label>
          <textarea id="cat_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $catVal('description') ?></textarea>

          <div class="admin-actions admin-actions-start">
            <button type="submit" class="btn btn-primary"><?= !empty($cf['id']) ? 'Save Changes' : 'Add Category' ?></button>
            <?php if (!empty($cf['id'])): ?><a href="admin-taxonomy.php?tab=categories" class="btn btn-outline">Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Description</th><th>Events</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($categories)): ?>
                <tr><td colspan="4" class="admin-table-empty">No categories yet — add your first one.</td></tr>
              <?php endif; ?>
              <?php foreach ($categories as $c): $used = $count($c, 'events'); ?>
                <tr>
                  <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                  <td><?= htmlspecialchars($c['description'] ?? '—') ?></td>
                  <td><a href="../events.php?category=<?= urlencode($c['id']) ?>"><?= $used ?></a></td>
                  <td class="admin-row-actions">
                    <a href="admin-taxonomy.php?tab=categories&amp;edit_category=<?= urlencode($c['id']) ?>" class="btn-sm">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=categories" onsubmit="return confirm('Delete the category &quot;<?= htmlspecialchars(addslashes($c['name'])) ?>&quot;?');">
                      <input type="hidden" name="action" value="delete_category">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                      <button type="submit" class="btn-sm btn-sm-danger" <?= $used ? 'disabled title="Used by ' . $used . ' event(s) — change their category first"' : '' ?>>Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="admin-hint admin-table-note">A category can only be deleted when no event uses it.</p>
        </div>
      </div>
    <?php elseif ($tab === 'departments'): ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=departments" class="admin-card" data-autoreset>
          <input type="hidden" name="action" value="save_department">
          <?php if (!empty($df['id'])): ?><input type="hidden" name="id" value="<?= $deptVal('id') ?>"><?php endif; ?>
          <h2 class="admin-card-title"><?= !empty($df['id']) ? 'Edit Department' : 'Add Department' ?></h2>

          <label class="admin-label" for="dept_name">Name <span class="req">*</span></label>
          <input type="text" id="dept_name" name="name" class="admin-input" placeholder="e.g. Information Technology" value="<?= $deptVal('name') ?>" required>

          <label class="admin-label" for="dept_code">Short code</label>
          <input type="text" id="dept_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. IT" value="<?= $deptVal('code') ?>">

          <label class="admin-label" for="dept_description">Description</label>
          <textarea id="dept_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $deptVal('description') ?></textarea>

          <div class="admin-actions admin-actions-start">
            <button type="submit" class="btn btn-primary"><?= !empty($df['id']) ? 'Save Changes' : 'Add Department' ?></button>
            <?php if (!empty($df['id'])): ?><a href="admin-taxonomy.php?tab=departments" class="btn btn-outline">Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Code</th><th>Organizations</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($allDepartments)): ?>
                <tr><td colspan="4" class="admin-table-empty">No departments yet — add your first one.</td></tr>
              <?php endif; ?>
              <?php foreach ($allDepartments as $d): $used = $count($d, 'organizations'); ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($d['name']) ?></strong>
                    <?php if (!empty($d['description'])): ?><div class="admin-table-sub"><?= htmlspecialchars(excerpt($d['description'], 70)) ?></div><?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($d['code'] ?? '—') ?></td>
                  <td><a href="reports.php?department=<?= urlencode($d['id']) ?>"><?= $used ?></a></td>
                  <td class="admin-row-actions">
                    <a href="admin-taxonomy.php?tab=departments&amp;edit_department=<?= urlencode($d['id']) ?>" class="btn-sm">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=departments" onsubmit="return confirm('Delete the department &quot;<?= htmlspecialchars(addslashes($d['name'])) ?>&quot;?');">
                      <input type="hidden" name="action" value="delete_department">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($d['id']) ?>">
                      <button type="submit" class="btn-sm btn-sm-danger" <?= $used ? 'disabled title="' . $used . ' organization(s) belong to it — move them first"' : '' ?>>Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="admin-hint admin-table-note">Each organization belongs to a department, so reports can be filtered by department. A department can only be deleted when no organization belongs to it.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=organizations" class="admin-card" data-autoreset enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_organization">
          <?php if (!empty($of['id'])): ?><input type="hidden" name="id" value="<?= $orgVal('id') ?>"><?php endif; ?>
          <h2 class="admin-card-title"><?= !empty($of['id']) ? 'Edit Organization' : 'Add Organization' ?></h2>

          <label class="admin-label" for="org_name">Name <span class="req">*</span></label>
          <input type="text" id="org_name" name="name" class="admin-input" placeholder="e.g. JPSSITE" value="<?= $orgVal('name') ?>" required>

          <label class="admin-label" for="org_department">Department</label>
          <select id="org_department" name="department_id" class="admin-input">
            <option value="">No department</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= htmlspecialchars($d['id']) ?>" <?= ($of['department_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars(department_label($d)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($departments)): ?>
            <p class="admin-hint">No departments yet — <a href="admin-taxonomy.php?tab=departments">add one</a> first.</p>
          <?php endif; ?>

          <label class="admin-label" for="org_description">Description</label>
          <textarea id="org_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $orgVal('description') ?></textarea>

          <?php if (!empty($of['logo_url'])): ?>
            <img src="<?= htmlspecialchars(asset_url($of['logo_url'])) ?>" alt="Current logo" class="admin-logo-preview">
          <?php endif; ?>
          <label class="admin-label" for="logo_file">Logo image</label>
          <input type="file" id="logo_file" name="logo_file" class="admin-input" accept="image/jpeg,image/png,image/webp,image/gif">
          <label class="admin-label" for="logo_url">…or paste a logo URL</label>
          <input type="text" id="logo_url" name="logo_url" class="admin-input" placeholder="https://…" value="<?= $orgVal('logo_url') ?>">

          <div class="admin-actions admin-actions-start">
            <button type="submit" class="btn btn-primary"><?= !empty($of['id']) ? 'Save Changes' : 'Add Organization' ?></button>
            <?php if (!empty($of['id'])): ?><a href="admin-taxonomy.php?tab=organizations" class="btn btn-outline">Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Logo</th><th>Name</th><th>Department</th><th>Events</th><th>Officers</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($organizations)): ?>
                <tr><td colspan="6" class="admin-table-empty">No organizations yet — add your first one.</td></tr>
              <?php endif; ?>
              <?php foreach ($organizations as $o): $used = $count($o, 'events'); ?>
                <tr>
                  <td>
                    <?php if (!empty($o['logo_url'])): ?>
                      <img src="<?= htmlspecialchars(asset_url($o['logo_url'])) ?>" alt="" class="admin-table-logo">
                    <?php else: ?>
                      <span class="admin-table-logo admin-table-logo-initials"><?= htmlspecialchars(initials($o['name'])) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($o['name']) ?></strong>
                    <?php if (!empty($o['description'])): ?><div class="admin-table-sub"><?= htmlspecialchars(excerpt($o['description'], 70)) ?></div><?php endif; ?>
                  </td>
                  <td><?= !empty($o['departments']) ? htmlspecialchars($o['departments']['code'] ?: $o['departments']['name']) : '<span class="admin-table-sub">None</span>' ?></td>
                  <td><a href="../events.php?organization=<?= urlencode($o['id']) ?>"><?= $used ?></a></td>
                  <td><?= $count($o, 'organization_officers') ?></td>
                  <td class="admin-row-actions">
                    <a href="organization-officers.php?org=<?= urlencode($o['id']) ?>" class="btn-sm btn-sm-accent">Officers</a>
                    <a href="admin-taxonomy.php?tab=organizations&amp;edit_org=<?= urlencode($o['id']) ?>" class="btn-sm">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=organizations" onsubmit="return confirm('Delete the organization &quot;<?= htmlspecialchars(addslashes($o['name'])) ?>&quot; and its officer list?');">
                      <input type="hidden" name="action" value="delete_organization">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($o['id']) ?>">
                      <button type="submit" class="btn-sm btn-sm-danger" <?= $used ? 'disabled title="Used by ' . $used . ' event(s) — change their organization first"' : '' ?>>Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="admin-hint admin-table-note">An organization can only be deleted when no event uses it. Deleting it also removes its officer list.</p>
        </div>
      </div>
    <?php endif; ?>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/forms.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
