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

// After a delete: back to the same search and page (only links to this page's own tab are accepted).
$returnTo = function (string $tab): string {
    $back = post_param('back');
    return preg_match('~^admin-taxonomy\.php\?tab=' . $tab . '(&[\w%.+\-=&]*)?$~', $back) ? $back : 'admin-taxonomy.php?tab=' . $tab;
};

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
            header('Location: ' . (is_uuid($existingId) ? $returnTo('departments') : 'admin-taxonomy.php?tab=departments'));
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
    header('Location: ' . $returnTo('departments'));
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
    header('Location: ' . $returnTo('categories'));
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
            header('Location: ' . (is_uuid($existingId) ? $returnTo('organizations') : 'admin-taxonomy.php?tab=organizations'));
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
    header('Location: ' . $returnTo('organizations'));
    exit;
}

$categories = get_all_categories_admin();
$allDepartments = get_all_departments_admin();
$organizations = get_all_organizations_admin();
$count = fn(array $row, string $rel): int => (int) ($row[$rel][0]['count'] ?? 0);

// ---- The list on each tab: search, department filter (Organizations), 10 rows per page ----
$q = get_param('q');
$deptFilter = $tab === 'organizations' ? get_param('dept') : '';
if ($deptFilter !== 'none' && !in_array($deptFilter, array_column($departments, 'id'), true)) $deptFilter = '';
$listFilters = array_filter(['q' => $q, 'dept' => $deptFilter], 'strlen');
$tabUrl = 'admin-taxonomy.php?tab=' . $tab;
$pageUrl = fn(int $p): string => $tabUrl . (($qs = http_build_query($listFilters + ($p > 1 ? ['page' => $p] : []))) !== '' ? '&' . $qs : '');
$listRows = match ($tab) {
    'categories' => array_filter($categories, fn($c) => matches_search($q, [$c['name'], $c['description'] ?? ''])),
    'departments' => array_filter($allDepartments, fn($d) => matches_search($q, [$d['name'], $d['code'] ?? '', $d['description'] ?? ''])),
    default => array_filter($organizations, fn($o) =>
        ($deptFilter === '' || ($deptFilter === 'none' ? empty($o['department_id']) : ($o['department_id'] ?? '') === $deptFilter))
        && matches_search($q, [$o['name'], $o['description'] ?? '', $o['departments']['name'] ?? '', $o['departments']['code'] ?? ''])),
};
$pager = paginate($listRows, 10, (int) get_param('page'));
$back = $pageUrl($pager['page']); // Edit / Cancel / Delete come back to the same search and page
$noun = ['categories' => ['category', 'categories'], 'departments' => ['department', 'departments'], 'organizations' => ['organization', 'organizations']][$tab];
$emptyText = $listFilters
    ? 'No ' . $noun[1] . ' match your search. <a href="' . $tabUrl . '">Clear the search</a>.'
    : 'No ' . $noun[1] . ' yet — add your first one.';

/** The search bar above a tab's table (plus the department filter on Organizations). */
$searchBar = function (string $placeholder) use ($tab, $q, $deptFilter, $departments, $listFilters, $tabUrl): string {
    $html = '<form method="get" action="admin-taxonomy.php" class="admin-search taxonomy-filter" role="search">'
        . '<input type="hidden" name="tab" value="' . $tab . '">'
        . '<input type="search" name="q" class="admin-input" placeholder="' . htmlspecialchars($placeholder) . '" value="' . htmlspecialchars($q) . '" aria-label="Search">';
    if ($tab === 'organizations') {
        $html .= '<select name="dept" class="admin-input" aria-label="Department"><option value="">Every department</option>';
        foreach ($departments as $d) {
            $html .= '<option value="' . htmlspecialchars($d['id']) . '"' . ($deptFilter === $d['id'] ? ' selected' : '') . '>' . htmlspecialchars(department_label($d)) . '</option>';
        }
        $html .= '<option value="none"' . ($deptFilter === 'none' ? ' selected' : '') . '>No department</option></select>';
    }
    $html .= '<button type="submit" class="btn btn-outline">Search</button>';
    if ($listFilters) $html .= '<a href="' . $tabUrl . '" class="btn btn-muted">Clear</a>';
    return $html . '</form>';
};

$cf = $editCategory ?? [];
$catVal = fn(string $key) => htmlspecialchars((string) ($cf[$key] ?? ''));
// Departments and organizations are edited in a pop-up; the card on the left only adds.
// $deptEdit / $orgEdit: the one to open the pop-up with right away (an ?edit_… link,
// or a save that failed).
$deptEdit = !empty($editDept['id']) ? $editDept : null;
$df = $deptEdit ? [] : ($editDept ?? []);
$deptVal = fn(string $key) => htmlspecialchars((string) ($df[$key] ?? ''));
$orgEdit = !empty($editOrg['id']) ? $editOrg : null;
$of = $orgEdit ? [] : ($editOrg ?? []);
$orgVal = fn(string $key) => htmlspecialchars((string) ($of[$key] ?? ''));
$editVal = fn(?array $row, string $key) => htmlspecialchars((string) ($row[$key] ?? ''));
/** What an Edit button fills its pop-up with. */
$editData = fn(array $fields) => htmlspecialchars(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$activeNav = 'admin-taxonomy';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Categories & Organizations', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
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
            <?php if (!empty($cf['id'])): ?><a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline">Cancel</a><?php endif; ?>
          </div>
        </form>

        <div class="taxonomy-list">
        <?= $searchBar('Search categories by name or description') ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Description</th><th>Events</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($pager['rows'])): ?>
                <tr><td colspan="4" class="admin-table-empty"><?= $emptyText ?></td></tr>
              <?php endif; ?>
              <?php foreach ($pager['rows'] as $c): $used = $count($c, 'events'); ?>
                <tr>
                  <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                  <td><?= htmlspecialchars($c['description'] ?? '—') ?></td>
                  <td><a href="../events.php?category=<?= urlencode($c['id']) ?>"><?= $used ?></a></td>
                  <td class="admin-row-actions">
                    <a href="<?= htmlspecialchars($back) ?>&amp;edit_category=<?= urlencode($c['id']) ?>" class="btn-sm">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=categories" data-confirm="Delete the category “<?= htmlspecialchars($c['name']) ?>”?" data-confirm-title="Delete category" data-confirm-ok="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="delete_category">
                      <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
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
        <?= render_pagination($pager, $pageUrl, ...$noun) ?>
        </div>
      </div>
    <?php elseif ($tab === 'departments'): ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=departments" class="admin-card" data-autoreset>
          <input type="hidden" name="action" value="save_department">
          <h2 class="admin-card-title">Add Department</h2>

          <label class="admin-label" for="dept_name">Name <span class="req">*</span></label>
          <input type="text" id="dept_name" name="name" class="admin-input" placeholder="e.g. Information Technology" value="<?= $deptVal('name') ?>" required>

          <label class="admin-label" for="dept_code">Short code</label>
          <input type="text" id="dept_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. IT" value="<?= $deptVal('code') ?>">

          <label class="admin-label" for="dept_description">Description</label>
          <textarea id="dept_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $deptVal('description') ?></textarea>

          <div class="admin-actions admin-actions-start">
            <button type="submit" class="btn btn-primary">Add Department</button>
          </div>
        </form>

        <div class="taxonomy-list">
        <?= $searchBar('Search departments by name or code') ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Code</th><th>Organizations</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($pager['rows'])): ?>
                <tr><td colspan="4" class="admin-table-empty"><?= $emptyText ?></td></tr>
              <?php endif; ?>
              <?php foreach ($pager['rows'] as $d): $used = $count($d, 'organizations'); ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($d['name']) ?></strong>
                    <?php if (!empty($d['description'])): ?><div class="admin-table-sub"><?= htmlspecialchars(excerpt($d['description'], 70)) ?></div><?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($d['code'] ?? '—') ?></td>
                  <td><a href="reports.php?department=<?= urlencode($d['id']) ?>"><?= $used ?></a></td>
                  <td class="admin-row-actions">
                    <a href="<?= htmlspecialchars($back) ?>&amp;edit_department=<?= urlencode($d['id']) ?>" class="btn-sm" data-edit-open="dept-modal"
                       data-edit="<?= $editData(['id' => $d['id'], 'name' => $d['name'], 'code' => $d['code'] ?? '', 'description' => $d['description'] ?? '']) ?>">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=departments" data-confirm="Delete the department “<?= htmlspecialchars($d['name']) ?>”?" data-confirm-title="Delete department" data-confirm-ok="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="delete_department">
                      <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
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
        <?= render_pagination($pager, $pageUrl, ...$noun) ?>
        </div>
      </div>

      <!-- Edit a department: pop-up, filled by the row's Edit button -->
      <dialog class="acct-modal admin-modal" id="dept-modal" aria-labelledby="dept-modal-title"<?= $deptEdit ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=departments" autocomplete="off">
          <input type="hidden" name="action" value="save_department">
          <input type="hidden" name="id" value="<?= $editVal($deptEdit, 'id') ?>">
          <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
          <div class="admin-modal-head">
            <h2 id="dept-modal-title">Edit Department</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?php if ($deptEdit): foreach ($errors as $err): ?><p class="acct-modal-error" role="alert"><?= htmlspecialchars($err) ?></p><?php endforeach; endif; ?>

          <label class="admin-label" for="edit_dept_name">Name <span class="req">*</span></label>
          <input type="text" id="edit_dept_name" name="name" class="admin-input" value="<?= $editVal($deptEdit, 'name') ?>" required>

          <label class="admin-label" for="edit_dept_code">Short code</label>
          <input type="text" id="edit_dept_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. IT" value="<?= $editVal($deptEdit, 'code') ?>">

          <label class="admin-label" for="edit_dept_description">Description</label>
          <textarea id="edit_dept_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($deptEdit, 'description') ?></textarea>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </dialog>
    <?php else: ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=organizations" class="admin-card" data-autoreset enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_organization">
          <h2 class="admin-card-title">Add Organization</h2>

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
            <button type="submit" class="btn btn-primary">Add Organization</button>
          </div>
        </form>

        <div class="taxonomy-list">
        <?= $searchBar('Search organizations by name, description or department') ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Logo</th><th>Name</th><th>Department</th><th>Events</th><th>Officers</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($pager['rows'])): ?>
                <tr><td colspan="6" class="admin-table-empty"><?= $emptyText ?></td></tr>
              <?php endif; ?>
              <?php foreach ($pager['rows'] as $o): $used = $count($o, 'events'); ?>
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
                    <a href="<?= htmlspecialchars($back) ?>&amp;edit_org=<?= urlencode($o['id']) ?>" class="btn-sm" data-edit-open="org-modal"
                       data-edit="<?= $editData(['id' => $o['id'], 'name' => $o['name'], 'department_id' => $o['department_id'] ?? '', 'description' => $o['description'] ?? '', 'logo_url' => $o['logo_url'] ?? '', 'logo_preview' => !empty($o['logo_url']) ? asset_url($o['logo_url']) : '']) ?>">Edit</a>
                    <form method="POST" action="admin-taxonomy.php?tab=organizations" data-confirm="Delete the organization “<?= htmlspecialchars($o['name']) ?>” and its officer list?" data-confirm-title="Delete organization" data-confirm-ok="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="delete_organization">
                      <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
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
        <?= render_pagination($pager, $pageUrl, ...$noun) ?>
        </div>
      </div>

      <!-- Edit an organization: pop-up, filled by the row's Edit button -->
      <dialog class="acct-modal admin-modal" id="org-modal" aria-labelledby="org-modal-title"<?= $orgEdit ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=organizations" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="action" value="save_organization">
          <input type="hidden" name="id" value="<?= $editVal($orgEdit, 'id') ?>">
          <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
          <div class="admin-modal-head">
            <h2 id="org-modal-title">Edit Organization</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?php if ($orgEdit): foreach ($errors as $err): ?><p class="acct-modal-error" role="alert"><?= htmlspecialchars($err) ?></p><?php endforeach; endif; ?>

          <label class="admin-label" for="edit_org_name">Name <span class="req">*</span></label>
          <input type="text" id="edit_org_name" name="name" class="admin-input" value="<?= $editVal($orgEdit, 'name') ?>" required>

          <label class="admin-label" for="edit_org_department">Department</label>
          <select id="edit_org_department" name="department_id" class="admin-input">
            <option value="">No department</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= htmlspecialchars($d['id']) ?>" <?= ($orgEdit['department_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars(department_label($d)) ?></option>
            <?php endforeach; ?>
          </select>

          <label class="admin-label" for="edit_org_description">Description</label>
          <textarea id="edit_org_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($orgEdit, 'description') ?></textarea>

          <div class="admin-modal-logo">
            <img src="<?= !empty($orgEdit['logo_url']) ? htmlspecialchars(asset_url($orgEdit['logo_url'])) : '' ?>" alt="Current logo" class="admin-logo-preview" data-field="logo_preview"<?= empty($orgEdit['logo_url']) ? ' hidden' : '' ?>>
            <div>
              <label class="admin-label" for="edit_logo_file">Replace the logo</label>
              <input type="file" id="edit_logo_file" name="logo_file" class="admin-input" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
          </div>
          <label class="admin-label" for="edit_logo_url">…or paste a logo URL</label>
          <input type="text" id="edit_logo_url" name="logo_url" class="admin-input" placeholder="https://…" value="<?= $editVal($orgEdit, 'logo_url') ?>">

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </dialog>
    <?php endif; ?>
  </main>

</div>

<script>
// Departments / Organizations: Edit opens a pop-up filled from the row (no page load).
(function () {
  function open(modal) { if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', ''); }
  function close(modal) { if (typeof modal.close === 'function') modal.close(); else modal.removeAttribute('open'); }

  document.querySelectorAll('[data-edit-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var modal = document.getElementById(btn.getAttribute('data-edit-open'));
      if (!modal) return; // no pop-up: the link opens the edit page instead
      e.preventDefault();
      var data = JSON.parse(btn.getAttribute('data-edit') || '{}');
      var form = modal.querySelector('form');
      Object.keys(data).forEach(function (key) {
        var field = form.elements.namedItem(key);
        if (field && field.type !== 'file') field.value = data[key];
      });
      var preview = modal.querySelector('[data-field="logo_preview"]');
      if (preview) { preview.hidden = !data.logo_preview; preview.src = data.logo_preview || ''; }
      var file = form.querySelector('input[type="file"]');
      if (file) file.value = '';
      modal.querySelectorAll('.acct-modal-error').forEach(function (p) { p.remove(); }); // errors were for another row
      open(modal);
      form.querySelector('input[name="name"]').focus();
    });
  });

  document.querySelectorAll('dialog.admin-modal').forEach(function (modal) {
    modal.querySelectorAll('[data-modal-close]').forEach(function (b) { b.addEventListener('click', function () { close(modal); }); });
    // A click on the dark backdrop (outside the box) closes it too.
    modal.addEventListener('click', function (e) { if (e.target === modal) close(modal); });
    // Opened by the server: an ?edit_… link, or a save that needs fixing.
    if (modal.hasAttribute('data-open')) open(modal);
  });
})();
</script>
<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
