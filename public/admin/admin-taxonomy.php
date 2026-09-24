<?php
require_once __DIR__ . '/config/supabase.php';

$user = require_admin(); // redirects non-admins to dashboard.php

// Which tab is active — defaults to categories.
$tab = ($_GET['tab'] ?? 'categories') === 'organizations' ? 'organizations' : 'categories';

$editCategoryId = $_GET['edit_category'] ?? null;
$editOrgId = $_GET['edit_org'] ?? null;
$editCategory = $editCategoryId ? get_category_admin($editCategoryId) : null;
$editOrg = $editOrgId ? get_organization_admin($editOrgId) : null;

$errors = [];
$flash = null; // ['type' => 'success'|'error', 'message' => '...']

if (isset($_GET['category_saved'])) $flash = ['type' => 'success', 'message' => 'Category saved.'];
if (isset($_GET['category_deleted'])) $flash = ['type' => 'success', 'message' => 'Category deleted.'];
if (isset($_GET['org_saved'])) $flash = ['type' => 'success', 'message' => 'Organization saved.'];
if (isset($_GET['org_deleted'])) $flash = ['type' => 'success', 'message' => 'Organization deleted.'];
if (isset($_GET['action_error'])) $flash = ['type' => 'error', 'message' => urldecode($_GET['action_error'])];

// ---- Handle category save (create or update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_category') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $existingId = $_POST['id'] ?? null;

    if ($name === '') {
        $errors[] = 'Category name is required.';
    } else {
        $fields = ['name' => $name, 'description' => $description !== '' ? $description : null];
        $result = $existingId
            ? update_category_admin($existingId, $fields)
            : create_category_admin($fields);

        if ($result['ok']) {
            header('Location: admin-taxonomy.php?tab=categories&category_saved=1');
            exit;
        }
        $errors[] = $result['message'];
    }

    $editCategory = ['id' => $existingId, 'name' => $name, 'description' => $description];
    $editCategoryId = $existingId;
    $tab = 'categories';
}

// ---- Handle category delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_category') {
    $result = delete_category_admin($_POST['id'] ?? '');
    if ($result['ok']) {
        header('Location: admin-taxonomy.php?tab=categories&category_deleted=1');
    } else {
        header('Location: admin-taxonomy.php?tab=categories&action_error=' . urlencode($result['message']));
    }
    exit;
}

// ---- Handle organization save (create or update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_organization') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $logoUrl = trim($_POST['logo_url'] ?? '');
    $existingId = $_POST['id'] ?? null;

    if ($name === '') {
        $errors[] = 'Organization name is required.';
    } else {
        $fields = [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ];
        $result = $existingId
            ? update_organization_admin($existingId, $fields)
            : create_organization_admin($fields);

        if ($result['ok']) {
            header('Location: admin-taxonomy.php?tab=organizations&org_saved=1');
            exit;
        }
        $errors[] = $result['message'];
    }

    $editOrg = ['id' => $existingId, 'name' => $name, 'description' => $description, 'logo_url' => $logoUrl];
    $editOrgId = $existingId;
    $tab = 'organizations';
}

// ---- Handle organization delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_organization') {
    $result = delete_organization_admin($_POST['id'] ?? '');
    if ($result['ok']) {
        header('Location: admin-taxonomy.php?tab=organizations&org_deleted=1');
    } else {
        header('Location: admin-taxonomy.php?tab=organizations&action_error=' . urlencode($result['message']));
    }
    exit;
}

$categories = get_all_categories_admin();
$organizations = get_all_organizations_admin();

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= substr($p, 0, 1); }
    return strtoupper($out) ?: 'U';
}

$cf = $editCategory ?? [];
$catVal = fn(string $key, $default = '') => htmlspecialchars((string)($cf[$key] ?? $default));

$of = $editOrg ?? [];
$orgVal = fn(string $key, $default = '') => htmlspecialchars((string)($of[$key] ?? $default));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories &amp; Organizations — Campus Event Hub</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/admin.css">
<style>
  /* Small page-local additions — not worth their own stylesheet. */
  .taxonomy-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
  .taxonomy-tab {
    padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 0.85rem;
    color: var(--text-muted); background: #fff; border: 1px solid var(--border);
  }
  .taxonomy-tab.active { background: var(--navy); color: #fff; border-color: var(--navy); }
  .taxonomy-layout { display: grid; grid-template-columns: 360px 1fr; gap: 20px; align-items: start; }
  @media (max-width: 1000px) { .taxonomy-layout { grid-template-columns: 1fr; } }
</style>
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
      <a href="event-organizer.php"><span class="icon">🎟️</span> Event</a>
    </nav>

    <div style="margin-top:auto; display:flex; flex-direction:column; gap:8px;">
      <a href="event-organizer.php" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:8px; font-weight:600; font-size:0.9rem; color:#cdd8ef; background:rgba(255,255,255,0.08);">
        <span class="icon">🛠️</span> Admin View
      </a>
      <a href="admin-taxonomy.php" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:8px; font-weight:600; font-size:0.9rem; color:#fff; background:var(--orange);">
        <span class="icon">🏷️</span> Categories &amp; Orgs
      </a>
    </div>
  </aside>

  <!-- Top bar -->
  <header class="dash-topbar">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <span class="crumb-sep">›</span>
      <a href="event-organizer.php">Events</a>
      <span class="crumb-sep">›</span>
      <span class="crumb-current">Categories &amp; Organizations</span>
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
    <h1 style="margin:0 0 18px; font-size:1.4rem;">Categories &amp; Organizations</h1>

    <?php if ($flash): ?>
      <div class="<?= $flash['type'] === 'success' ? 'auth-success' : 'auth-alert' ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="auth-alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="taxonomy-tabs">
      <a href="admin-taxonomy.php?tab=categories" class="taxonomy-tab <?= $tab === 'categories' ? 'active' : '' ?>">Categories (<?= count($categories) ?>)</a>
      <a href="admin-taxonomy.php?tab=organizations" class="taxonomy-tab <?= $tab === 'organizations' ? 'active' : '' ?>">Organizations (<?= count($organizations) ?>)</a>
    </div>

    <?php if ($tab === 'categories'): ?>
      <div class="taxonomy-layout">
        <div class="admin-card">
          <h2 class="admin-card-title"><?= $editCategoryId ? 'Edit Category' : 'Add Category' ?></h2>
          <form method="POST" action="admin-taxonomy.php">
            <input type="hidden" name="action" value="save_category">
            <?php if ($editCategoryId): ?><input type="hidden" name="id" value="<?= htmlspecialchars((string)$editCategoryId) ?>"><?php endif; ?>

            <label class="admin-label">Name <span class="req">*</span></label>
            <input type="text" name="name" class="admin-input" placeholder="e.g. Seminar" value="<?= $catVal('name') ?>" required>

            <label class="admin-label">Description</label>
            <textarea name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $catVal('description') ?></textarea>

            <div class="admin-actions" style="justify-content:flex-start; margin-top:16px;">
              <button type="submit" class="btn btn-primary"><?= $editCategoryId ? 'Save Changes' : 'Add Category' ?></button>
              <?php if ($editCategoryId): ?><a href="admin-taxonomy.php?tab=categories" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Description</th><th></th></tr>
            </thead>
            <tbody>
              <?php if (empty($categories)): ?>
                <tr><td colspan="3" style="color:var(--text-muted); text-align:center; padding:24px;">No categories yet — add your first one.</td></tr>
              <?php endif; ?>
              <?php foreach ($categories as $c): ?>
                <tr>
                  <td><?= htmlspecialchars($c['name']) ?></td>
                  <td><?= htmlspecialchars($c['description'] ?? '—') ?></td>
                  <td class="admin-row-actions">
                    <a href="admin-taxonomy.php?tab=categories&edit_category=<?= urlencode($c['id']) ?>" class="btn-outline" style="padding:6px 14px; border-radius:6px; font-size:0.78rem;">Edit</a>
                    <form method="POST" action="admin-taxonomy.php" onsubmit="return confirm('Delete this category? Events using it must be reassigned first.');" style="display:inline;">
                      <input type="hidden" name="action" value="delete_category">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                      <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--red); color:var(--red); background:transparent;">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php else: ?>
      <div class="taxonomy-layout">
        <div class="admin-card">
          <h2 class="admin-card-title"><?= $editOrgId ? 'Edit Organization' : 'Add Organization' ?></h2>
          <form method="POST" action="admin-taxonomy.php">
            <input type="hidden" name="action" value="save_organization">
            <?php if ($editOrgId): ?><input type="hidden" name="id" value="<?= htmlspecialchars((string)$editOrgId) ?>"><?php endif; ?>

            <label class="admin-label">Name <span class="req">*</span></label>
            <input type="text" name="name" class="admin-input" placeholder="e.g. JPSSITE" value="<?= $orgVal('name') ?>" required>

            <label class="admin-label">Description</label>
            <textarea name="description" class="admin-input" rows="3" placeholder="Optional short description"><?= $orgVal('description') ?></textarea>

            <label class="admin-label">Logo URL</label>
            <input type="text" name="logo_url" class="admin-input" placeholder="assets/org-jpssite.jpg or https://…" value="<?= $orgVal('logo_url') ?>">

            <div class="admin-actions" style="justify-content:flex-start; margin-top:16px;">
              <button type="submit" class="btn btn-primary"><?= $editOrgId ? 'Save Changes' : 'Add Organization' ?></button>
              <?php if ($editOrgId): ?><a href="admin-taxonomy.php?tab=organizations" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr><th>Logo</th><th>Name</th><th>Description</th><th></th></tr>
            </thead>
            <tbody>
              <?php if (empty($organizations)): ?>
                <tr><td colspan="4" style="color:var(--text-muted); text-align:center; padding:24px;">No organizations yet — add your first one.</td></tr>
              <?php endif; ?>
              <?php foreach ($organizations as $o): ?>
                <tr>
                  <td>
                    <?php if (!empty($o['logo_url'])): ?>
                      <img src="<?= htmlspecialchars($o['logo_url']) ?>" alt="" style="width:32px; height:32px; border-radius:6px; object-fit:cover;">
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($o['name']) ?></td>
                  <td><?= htmlspecialchars($o['description'] ?? '—') ?></td>
                  <td class="admin-row-actions">
                    <a href="admin-taxonomy.php?tab=organizations&edit_org=<?= urlencode($o['id']) ?>" class="btn-outline" style="padding:6px 14px; border-radius:6px; font-size:0.78rem;">Edit</a>
                    <form method="POST" action="admin-taxonomy.php" onsubmit="return confirm('Delete this organization? Events using it must be reassigned first.');" style="display:inline;">
                      <input type="hidden" name="action" value="delete_organization">
                      <input type="hidden" name="id" value="<?= htmlspecialchars($o['id']) ?>">
                      <button type="submit" style="padding:6px 14px; border-radius:6px; font-size:0.78rem; border:1.5px solid var(--red); color:var(--red); background:transparent;">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </main>

</div>

</body>
</html>
