<?php
/**
 * The name as three fields — Last name, First name (required) and Middle
 * name (optional) — for Edit my profile and Users › Edit (admin-input style).
 * The full name ("First Middle Last") is made from them when saving
 * (name_parts_from_post()).
 *
 * From the page: $nameValues — the values to show: last_name, first_name,
 * middle_name (and full_name, used when the parts are still empty).
 */
$nameValues = $nameValues ?? [];
$nameVal = fn(string $key) => htmlspecialchars((string) ($nameValues[$key] ?? ''));
?>
<div class="admin-two-col">
  <div>
    <label class="admin-label" for="last_name">Last Name <span class="req">*</span></label>
    <input type="text" id="last_name" name="last_name" class="admin-input" required maxlength="60" autocomplete="family-name" placeholder="Dela Cruz" value="<?= $nameVal('last_name') ?>">
  </div>
  <div>
    <label class="admin-label" for="first_name">First Name <span class="req">*</span></label>
    <input type="text" id="first_name" name="first_name" class="admin-input" required maxlength="60" autocomplete="given-name" placeholder="Juan" value="<?= $nameVal('first_name') ?>">
  </div>
</div>
<label class="admin-label" for="middle_name">Middle Name <span class="admin-muted">(optional)</span></label>
<input type="text" id="middle_name" name="middle_name" class="admin-input" maxlength="60" autocomplete="additional-name" placeholder="Santos" value="<?= $nameVal('middle_name') ?>">
<?php if (($nameValues['last_name'] ?? '') === '' && ($nameValues['first_name'] ?? '') === '' && !empty($nameValues['full_name'])): ?>
  <p class="admin-hint">Saved as one name so far: <strong><?= htmlspecialchars((string) $nameValues['full_name']) ?></strong> — fill in the parts above.</p>
<?php endif; ?>
