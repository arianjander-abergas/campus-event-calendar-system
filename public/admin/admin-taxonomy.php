<?php
/** Admin: categories and organizations (with logos and a link to each org's officers). */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$tab = get_param('tab') === 'organizations' ? 'organizations' : 'categories';
$editCategoryId = get_param('edit_category');
$editOrgId = get_param('edit_org');
$editCategory = $editCategoryId !== '' ? get_category_admin($editCategoryId) : null;
$editOrg = $editOrgId !== '' ? get_organization_admin($editOrgId) : null;
$errors = [];

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
    ];

    if ($fields['name'] === '') {
        $errors[] = 'Organization name is required.';
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
$organizations = get_all_organizations_admin();
$count = fn(array $row, string $rel): int => (int) ($row[$rel][0]['count'] ?? 0);

$cf = $editCategory ?? [];
$catVal = fn(string $key) => htmlspecialchars((string) ($cf[$key] ?? ''));
$of = $editOrg ?? [];
$orgVal = fn(string $key) => htmlspecialchars((string) ($of[$key] ?? ''));

$activeNav = 'admin-taxonomy';
$breadcrumbs = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['Categories & Organizations', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories &amp; Organizations — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">Categories &amp; Organizations</h1>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="admin-tabs" role="tablist">
      <a href="admin-taxonomy.php?tab=categories" class="admin-tab<?= $tab === 'categories' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'categories' ? 'true' : 'false' ?>">Categories (<?= count($categories) ?>)</a>
      <a href="admin-taxonomy.php?tab=organizations" class="admin-tab<?= $tab === 'organizations' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'organizations' ? 'true' : 'false' ?>">Organizations (<?= count($organizations) ?>)</a>
    </div>

    <?php if ($tab === 'categories'): ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=categories" class="admin-card">
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
                  <td><a href="../events.php?category=<?= urlencode($c['id']) ?>&amp;when=all"><?= $used ?></a></td>
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
    <?php else: ?>
      <div class="admin-split">
        <form method="POST" action="admin-taxonomy.php?tab=organizations" class="admin-card" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_organization">
          <?php if (!empty($of['id'])): ?><input type="hidden" name="id" value="<?= $orgVal('id') ?>"><?php endif; ?>
          <h2 class="admin-card-title"><?= !empty($of['id']) ? 'Edit Organization' : 'Add Organization' ?></h2>

          <label class="admin-label" for="org_name">Name <span class="req">*</span></label>
          <input type="text" id="org_name" name="name" class="admin-input" placeholder="e.g. JPSSITE" value="<?= $orgVal('name') ?>" required>

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
              <tr><th>Logo</th><th>Name</th><th>Events</th><th>Officers</th><th><span class="visually-hidden">Actions</span></th></tr>
            </thead>
            <tbody>
              <?php if (empty($organizations)): ?>
                <tr><td colspan="5" class="admin-table-empty">No organizations yet — add your first one.</td></tr>
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
                  <td><a href="../events.php?organization=<?= urlencode($o['id']) ?>&amp;when=all"><?= $used ?></a></td>
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
<?= presence_script_tag() ?>
</body>
</html>
