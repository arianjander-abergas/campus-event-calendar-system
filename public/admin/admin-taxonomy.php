<?php
/**
 * Admin: Categories (sidebar: Categories ▾) — four lists, one per tab:
 *   ?tab=categories     event categories (Seminar, Workshop…)
 *   ?tab=departments    departments
 *   ?tab=organizations  organizations (with logos, their department and a link to each org's officers)
 *   ?tab=venues         the event form's Venue dropdown: On-campus places and Online platforms
 *                       (off-campus places are typed in on the form)
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$tab = in_array(get_param('tab'), ['departments', 'organizations', 'venues'], true) ? get_param('tab') : 'categories';
$editCategoryId = get_param('edit_category');
$editDeptId = get_param('edit_department');
$editOrgId = get_param('edit_org');
$editCategory = $editCategoryId !== '' ? get_category_admin($editCategoryId) : null;
$editDept = $editDeptId !== '' ? get_department_admin($editDeptId) : null;
$editOrg = $editOrgId !== '' ? get_organization_admin($editOrgId) : null;
$departments = get_departments();
$errors = [];
// Organizations' "Short code" field appears once the organization_codes database update is run.
$orgCodes = !isset(pending_db_updates()['20261108120000_organization_codes.sql']);

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
        $errors[] = 'Keep the code short (12 characters or fewer), e.g. CIT.';
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
    if ($orgCodes) $fields['code'] = post_param('code') ?: null;

    if ($fields['name'] === '') {
        $errors[] = 'Organization name is required.';
    } elseif (($fields['code'] ?? null) !== null && mb_strlen($fields['code']) > 12) {
        $errors[] = 'Keep the code short (12 characters or fewer), e.g. UAO.';
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

// ---- Venue add (On-campus place or Online platform) ----
$venuesReady = !isset(pending_db_updates()['20261120120000_venues.sql']);
$venueForm = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_venue') {
    $venueForm = ['name' => preg_replace('/\s+/', ' ', post_param('name')), 'venue_type' => post_param('venue_type')];
    if ($venueForm['name'] === '') {
        $errors[] = 'Venue name is required.';
    } elseif (mb_strlen($venueForm['name']) > 120) {
        $errors[] = 'Keep the venue name to 120 characters or fewer.';
    } elseif (!isset(VENUE_LISTS[$venueForm['venue_type']])) {
        $errors[] = 'Choose whether it\'s an on-campus place or an online platform.';
    } else {
        $result = create_venue_admin($venueForm);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: admin-taxonomy.php?tab=venues');
            exit;
        }
        $errors[] = $result['message'];
    }
    $tab = 'venues';
}

// ---- Venue delete (events already held there keep it: events.venue is text) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete_venue') {
    $result = delete_venue_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $returnTo('venues'));
    exit;
}

$categories = get_all_categories_admin();
$allDepartments = get_all_departments_admin();
$organizations = get_all_organizations_admin();
$venues = $venuesReady ? get_all_venues_admin() : [];
$count = fn(array $row, string $rel): int => (int) ($row[$rel][0]['count'] ?? 0);

// ---- The list on each tab: search, department filter (Organizations), type filter (Venues), 10 rows per page ----
$q = get_param('q');
$deptFilter = $tab === 'organizations' ? get_param('dept') : '';
if ($deptFilter !== 'none' && !in_array($deptFilter, array_column($departments, 'id'), true)) $deptFilter = '';
$kindFilter = $tab === 'venues' && isset(VENUE_LISTS[get_param('kind')]) ? get_param('kind') : '';
$listFilters = array_filter(['q' => $q, 'dept' => $deptFilter, 'kind' => $kindFilter], 'strlen');
$tabUrl = 'admin-taxonomy.php?tab=' . $tab;
$pageUrl = fn(int $p): string => $tabUrl . (($qs = http_build_query($listFilters + ($p > 1 ? ['page' => $p] : []))) !== '' ? '&' . $qs : '');
$listRows = match ($tab) {
    'categories' => array_filter($categories, fn($c) => matches_search($q, [$c['name'], $c['description'] ?? ''])),
    'departments' => array_filter($allDepartments, fn($d) => matches_search($q, [$d['name'], $d['code'] ?? '', $d['description'] ?? ''])),
    'venues' => array_filter($venues, fn($v) => ($kindFilter === '' || $v['venue_type'] === $kindFilter) && matches_search($q, [$v['name']])),
    default => array_filter($organizations, fn($o) =>
        ($deptFilter === '' || ($deptFilter === 'none' ? empty($o['department_id']) : ($o['department_id'] ?? '') === $deptFilter))
        && matches_search($q, [$o['name'], $o['description'] ?? '', $o['departments']['name'] ?? '', $o['departments']['code'] ?? ''])),
};
$pager = paginate($listRows, 10, (int) get_param('page'));
$back = $pageUrl($pager['page']); // Edit / Cancel / Delete come back to the same search and page
$noun = ['categories' => ['category', 'categories'], 'departments' => ['department', 'departments'], 'organizations' => ['organization', 'organizations'], 'venues' => ['venue', 'venues']][$tab];
$emptyText = $listFilters
    ? 'No ' . $noun[1] . ' match your search. <a href="' . $tabUrl . '">Clear the search</a>.'
    : 'No ' . $noun[1] . ' yet — add your first one.';

/** The search bar above a tab's table (plus the department filter on Organizations). */
$searchBar = function (string $placeholder, string $extra = '') use ($tab, $q, $deptFilter, $kindFilter, $departments, $listFilters, $tabUrl): string {
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
    if ($tab === 'venues') {
        $html .= '<select name="kind" class="admin-input" aria-label="Type"><option value="">On-campus &amp; Online</option>';
        foreach (VENUE_LISTS as $key => $label) {
            $html .= '<option value="' . $key . '"' . ($kindFilter === $key ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select>';
    }
    $html .= '<button type="submit" class="btn btn-outline">Search</button>';
    if ($listFilters) $html .= '<a href="' . $tabUrl . '" class="btn btn-muted">Clear</a>';
    return $html . $extra . '</form>'; // $extra: the tab's "+ Add …" button
};

// Adding and editing both happen in pop-ups, so each tab's table uses the whole width.
// …Edit: the row to open the Edit pop-up with right away (an ?edit_… link, or a save
// that failed). …New: what was typed into an Add pop-up whose save failed (it opens
// again with it); null = the Add pop-up starts empty.
$catEdit = !empty($editCategory['id']) ? $editCategory : null;
$catNew = $editCategory !== null && !$catEdit ? $editCategory : null;
$deptEdit = !empty($editDept['id']) ? $editDept : null;
$deptNew = $editDept !== null && !$deptEdit ? $editDept : null;
$orgEdit = !empty($editOrg['id']) ? $editOrg : null;
$orgNew = $editOrg !== null && !$orgEdit ? $editOrg : null;
$venueNew = $venueForm ?: null;
/** Open an Add pop-up on load: a save that needs fixing, or an "&add=1" link. */
$addRetry = ['cat' => $catNew, 'dept' => $deptNew, 'org' => $orgNew, 'venue' => $venueNew];
$addOpen = fn(string $kind): bool => $addRetry[$kind] !== null || (get_param('add') === '1' && !$errors);
/** The "+ Add …" button beside the search (a link to "&add=1" without scripts). */
$addButton = fn(string $modal, string $label, bool $enabled = true): string => $enabled
    ? '<a href="' . htmlspecialchars($tabUrl) . '&amp;add=1" class="btn btn-primary taxonomy-add" data-modal-open="' . $modal . '">+ ' . $label . '</a>'
    : '<button type="button" class="btn btn-primary taxonomy-add" disabled>+ ' . $label . '</button>';
/** The errors shown inside a pop-up the server opened after a failed save. */
$modalErrors = fn(bool $show): string => $show
    ? implode('', array_map(fn($e) => '<p class="acct-modal-error" role="alert">' . htmlspecialchars($e) . '</p>', $errors))
    : '';
$editVal = fn(?array $row, string $key) => htmlspecialchars((string) ($row[$key] ?? ''));
/** What an Edit button fills its pop-up with. */
$editData = fn(array $fields) => htmlspecialchars(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Sidebar: Categories ▾ Event Categories / Departments / Organizations / Venues.
$activeNav = 'admin-' . $tab;
$tabTitle = ['categories' => 'Event Categories', 'departments' => 'Departments', 'organizations' => 'Organizations', 'venues' => 'Venues'][$tab];
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Categories', null], [$tabTitle, null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= $tabTitle ?> — Categories — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">Categories</h1>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-tabs" role="tablist">
      <a href="admin-taxonomy.php?tab=categories" class="admin-tab<?= $tab === 'categories' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'categories' ? 'true' : 'false' ?>">Event Categories (<?= count($categories) ?>)</a>
      <a href="admin-taxonomy.php?tab=departments" class="admin-tab<?= $tab === 'departments' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'departments' ? 'true' : 'false' ?>">Departments (<?= count($allDepartments) ?>)</a>
      <a href="admin-taxonomy.php?tab=organizations" class="admin-tab<?= $tab === 'organizations' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'organizations' ? 'true' : 'false' ?>">Organizations (<?= count($organizations) ?>)</a>
      <a href="admin-taxonomy.php?tab=venues" class="admin-tab<?= $tab === 'venues' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'venues' ? 'true' : 'false' ?>">Venues (<?= count($venues) ?>)</a>
    </div>

    <?php if ($tab === 'categories'): ?>
      <div class="taxonomy-list">
        <?= $searchBar('Search categories by name or description', $addButton('cat-add-modal', 'Add Category')) ?>
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
                    <a href="<?= htmlspecialchars($back) ?>&amp;edit_category=<?= urlencode($c['id']) ?>" class="btn-sm" data-edit-open="cat-modal"
                       data-edit="<?= $editData(['id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'] ?? '']) ?>">Edit</a>
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

      <!-- Add a category: pop-up, opened by "+ Add Category" -->
      <dialog class="acct-modal admin-modal" id="cat-add-modal" aria-labelledby="cat-add-modal-title"<?= $addOpen('cat') ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=categories" autocomplete="off" data-autoreset>
          <input type="hidden" name="action" value="save_category">
          <div class="admin-modal-head">
            <h2 id="cat-add-modal-title">Add Category</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?= $modalErrors($catNew !== null) ?>

          <label class="admin-label" for="cat_name">Name <span class="req">*</span></label>
          <input type="text" id="cat_name" name="name" class="admin-input" placeholder="e.g. Seminar" value="<?= $editVal($catNew, 'name') ?>" required>

          <label class="admin-label" for="cat_description">Description</label>
          <textarea id="cat_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($catNew, 'description') ?></textarea>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add Category</button>
          </div>
        </form>
      </dialog>

      <!-- Edit a category: pop-up, filled by the row's Edit button -->
      <dialog class="acct-modal admin-modal" id="cat-modal" aria-labelledby="cat-modal-title"<?= $catEdit ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=categories" autocomplete="off">
          <input type="hidden" name="action" value="save_category">
          <input type="hidden" name="id" value="<?= $editVal($catEdit, 'id') ?>">
          <div class="admin-modal-head">
            <h2 id="cat-modal-title">Edit Category</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?= $modalErrors($catEdit !== null) ?>

          <label class="admin-label" for="edit_cat_name">Name <span class="req">*</span></label>
          <input type="text" id="edit_cat_name" name="name" class="admin-input" value="<?= $editVal($catEdit, 'name') ?>" required>

          <label class="admin-label" for="edit_cat_description">Description</label>
          <textarea id="edit_cat_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($catEdit, 'description') ?></textarea>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </dialog>
    <?php elseif ($tab === 'departments'): ?>
      <div class="taxonomy-list">
        <?= $searchBar('Search departments by name or code', $addButton('dept-add-modal', 'Add Department')) ?>
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

      <!-- Add a department: pop-up, opened by "+ Add Department" -->
      <dialog class="acct-modal admin-modal" id="dept-add-modal" aria-labelledby="dept-add-modal-title"<?= $addOpen('dept') ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=departments" autocomplete="off" data-autoreset>
          <input type="hidden" name="action" value="save_department">
          <div class="admin-modal-head">
            <h2 id="dept-add-modal-title">Add Department</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?= $modalErrors($deptNew !== null) ?>

          <label class="admin-label" for="dept_name">Name <span class="req">*</span></label>
          <input type="text" id="dept_name" name="name" class="admin-input" placeholder="e.g. College of Information Technology" value="<?= $editVal($deptNew, 'name') ?>" required>

          <label class="admin-label" for="dept_code">Short code</label>
          <input type="text" id="dept_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. CIT" value="<?= $editVal($deptNew, 'code') ?>">

          <label class="admin-label" for="dept_description">Description</label>
          <textarea id="dept_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($deptNew, 'description') ?></textarea>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add Department</button>
          </div>
        </form>
      </dialog>

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
          <?= $modalErrors($deptEdit !== null) ?>

          <label class="admin-label" for="edit_dept_name">Name <span class="req">*</span></label>
          <input type="text" id="edit_dept_name" name="name" class="admin-input" value="<?= $editVal($deptEdit, 'name') ?>" required>

          <label class="admin-label" for="edit_dept_code">Short code</label>
          <input type="text" id="edit_dept_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. CIT" value="<?= $editVal($deptEdit, 'code') ?>">

          <label class="admin-label" for="edit_dept_description">Description</label>
          <textarea id="edit_dept_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($deptEdit, 'description') ?></textarea>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </dialog>
    <?php elseif ($tab === 'organizations'): ?>
      <div class="taxonomy-list">
        <?= $searchBar('Search organizations by name, description or department', $addButton('org-add-modal', 'Add Organization')) ?>
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
                      <img loading="lazy" decoding="async" src="<?= htmlspecialchars(asset_url($o['logo_url'])) ?>" alt="" class="admin-table-logo">
                    <?php else: ?>
                      <span class="admin-table-logo admin-table-logo-initials"><?= htmlspecialchars(initials($o['name'])) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars(organization_label($o)) ?></strong>
                    <?php if (!empty($o['description'])): ?><div class="admin-table-sub"><?= htmlspecialchars(excerpt($o['description'], 70)) ?></div><?php endif; ?>
                  </td>
                  <td><?= !empty($o['departments']) ? htmlspecialchars($o['departments']['code'] ?: $o['departments']['name']) : '<span class="admin-table-sub">None</span>' ?></td>
                  <td><a href="../events.php?organization=<?= urlencode($o['id']) ?>"><?= $used ?></a></td>
                  <td><?= $count($o, 'organization_officers') ?></td>
                  <td class="admin-row-actions">
                    <a href="organization-officers.php?org=<?= urlencode($o['id']) ?>" class="btn-sm btn-sm-accent">Officers</a>
                    <a href="<?= htmlspecialchars($back) ?>&amp;edit_org=<?= urlencode($o['id']) ?>" class="btn-sm" data-edit-open="org-modal"
                       data-edit="<?= $editData(['id' => $o['id'], 'name' => $o['name'], 'code' => $o['code'] ?? '', 'department_id' => $o['department_id'] ?? '', 'description' => $o['description'] ?? '', 'logo_url' => $o['logo_url'] ?? '', 'logo_preview' => !empty($o['logo_url']) ? asset_url($o['logo_url']) : '']) ?>">Edit</a>
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

      <!-- Add an organization: pop-up, opened by "+ Add Organization" -->
      <dialog class="acct-modal admin-modal" id="org-add-modal" aria-labelledby="org-add-modal-title"<?= $addOpen('org') ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=organizations" enctype="multipart/form-data" autocomplete="off" data-autoreset>
          <input type="hidden" name="action" value="save_organization">
          <div class="admin-modal-head">
            <h2 id="org-add-modal-title">Add Organization</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?= $modalErrors($orgNew !== null) ?>

          <label class="admin-label" for="org_name">Name <span class="req">*</span></label>
          <input type="text" id="org_name" name="name" class="admin-input" placeholder="<?= $orgCodes ? 'Full name, e.g. University Ambassadors Organization' : 'e.g. JPSSITE' ?>" value="<?= $editVal($orgNew, 'name') ?>" required>

          <?php if ($orgCodes): ?>
            <label class="admin-label" for="org_code">Short code</label>
            <input type="text" id="org_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. UAO" value="<?= $editVal($orgNew, 'code') ?>">
          <?php endif; ?>

          <label class="admin-label" for="org_department">Department</label>
          <select id="org_department" name="department_id" class="admin-input">
            <option value="">No department</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= htmlspecialchars($d['id']) ?>" <?= ($orgNew['department_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars(department_label($d)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($departments)): ?>
            <p class="admin-hint">No departments yet — <a href="admin-taxonomy.php?tab=departments">add one</a> first.</p>
          <?php endif; ?>

          <label class="admin-label" for="org_description">Description</label>
          <textarea id="org_description" name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $editVal($orgNew, 'description') ?></textarea>

          <label class="admin-label" for="logo_file">Logo image</label>
          <input type="file" id="logo_file" name="logo_file" class="admin-input" accept="image/jpeg,image/png,image/webp,image/gif">
          <label class="admin-label" for="logo_url">…or paste a logo URL</label>
          <input type="text" id="logo_url" name="logo_url" class="admin-input" placeholder="https://…" value="<?= $editVal($orgNew, 'logo_url') ?>">

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add Organization</button>
          </div>
        </form>
      </dialog>

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
          <?= $modalErrors($orgEdit !== null) ?>

          <label class="admin-label" for="edit_org_name">Name <span class="req">*</span></label>
          <input type="text" id="edit_org_name" name="name" class="admin-input" value="<?= $editVal($orgEdit, 'name') ?>" required>

          <?php if ($orgCodes): ?>
            <label class="admin-label" for="edit_org_code">Short code</label>
            <input type="text" id="edit_org_code" name="code" class="admin-input" maxlength="12" placeholder="e.g. UAO" value="<?= $editVal($orgEdit, 'code') ?>">
          <?php endif; ?>

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
    <?php else: /* venues */ ?>
      <?php if (!$venuesReady): ?>
        <div class="flash flash-info" role="status">The venues list needs a database update first: open <a href="database-update.php">Database update</a>, run <strong>20261120120000_venues.sql</strong>, then come back. Until then, the event form lets you type the venue.</div>
      <?php endif; ?>
      <div class="taxonomy-list">
        <?= $searchBar('Search venues by name', $addButton('venue-add-modal', 'Add Venue', $venuesReady)) ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Type</th><th>Events</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($pager['rows'])): ?>
                <tr><td colspan="4" class="admin-table-empty"><?= $emptyText ?></td></tr>
              <?php endif; ?>
              <?php foreach ($pager['rows'] as $v): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($v['name']) ?></strong></td>
                  <td><?= icon($v['venue_type'] === 'online' ? 'online' : 'location', 14) ?> <?= label_of(VENUE_LISTS, $v['venue_type']) ?></td>
                  <td><?= (int) $v['events'] ?></td>
                  <td class="admin-row-actions">
                    <form method="POST" action="admin-taxonomy.php?tab=venues" data-confirm="Delete the venue “<?= htmlspecialchars($v['name']) ?>”? It won't be offered on the event form anymore<?= $v['events'] ? '; the ' . (int) $v['events'] . ' event' . ($v['events'] === 1 ? '' : 's') . ' already there keep' . ($v['events'] === 1 ? 's' : '') . ' it' : '' ?>." data-confirm-title="Delete venue" data-confirm-ok="Delete" data-confirm-tone="danger">
                      <input type="hidden" name="action" value="delete_venue">
                      <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($v['id']) ?>">
                      <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="admin-hint admin-table-note">These are the choices in the event form's Venue dropdown. Off-campus places aren't listed: they're typed in on the form. Deleting a venue only takes it off the dropdown: events already held there keep their venue.</p>
        </div>
        <?= render_pagination($pager, $pageUrl, ...$noun) ?>
      </div>

      <?php if ($venuesReady): ?>
      <!-- Add a venue: pop-up, opened by "+ Add Venue" -->
      <dialog class="acct-modal admin-modal" id="venue-add-modal" aria-labelledby="venue-add-modal-title"<?= $addOpen('venue') ? ' data-open' : '' ?>>
        <form method="POST" action="admin-taxonomy.php?tab=venues" autocomplete="off" data-autoreset>
          <input type="hidden" name="action" value="save_venue">
          <div class="admin-modal-head">
            <h2 id="venue-add-modal-title">Add Venue</h2>
            <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
          </div>
          <?= $modalErrors($venueNew !== null) ?>

          <span class="admin-label">Type <span class="req">*</span></span>
          <div class="admin-radio-row">
            <?php foreach (VENUE_LISTS as $key => $label): ?>
              <label class="admin-radio"><input type="radio" name="venue_type" value="<?= $key ?>" <?= ($venueNew['venue_type'] ?? 'on_campus') === $key ? 'checked' : '' ?>> <?= $key === 'online' ? 'Online platform' : 'On-campus place' ?></label>
            <?php endforeach; ?>
          </div>

          <label class="admin-label" for="venue_name">Name <span class="req">*</span></label>
          <input type="text" id="venue_name" name="name" class="admin-input" maxlength="120" placeholder="e.g. AVR 1, Gymnasium, Zoom" value="<?= $editVal($venueNew, 'name') ?>" required>
          <p class="admin-hint">These are the choices in the event form's Venue dropdown. Off-campus places aren't listed: they're typed in on the form.</p>

          <div class="acct-modal-actions">
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Add Venue</button>
          </div>
        </form>
      </dialog>
      <?php endif; ?>
    <?php endif; ?>
  </main>

</div>

<script>
// Every tab: "+ Add …" opens the Add pop-up; Edit opens the Edit pop-up filled from the row (no page load).
(function () {
  function open(modal) { if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', ''); }
  function close(modal) { if (typeof modal.close === 'function') modal.close(); else modal.removeAttribute('open'); }

  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      var modal = document.getElementById(btn.getAttribute('data-modal-open'));
      if (!modal) return; // no pop-up: the link reloads the page with it open
      e.preventDefault();
      open(modal);
      var first = modal.querySelector('input[name="name"]');
      if (first) first.focus();
    });
  });

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
