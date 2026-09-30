<?php
/**
 * The account fields of the sign-up form (auth/register.php) and My
 * Profile (enduser/profile.php): student or faculty, department,
 * organization (grouped by department), and for students the school ID,
 * course, highest year level and regular / irregular — irregular opens a
 * pop-up to pick the year levels of their subjects. Behaviour:
 * assets/js/account.js. Checked by validate_account_fields().
 *
 * Set before including:
 *   $acct          current values: account_type, department_id, organization_id,
 *                  school_id, course, year_level, student_type, irregular_year_levels
 *   $departments, $organizations
 *   $acctStyle     'auth' (sign-up page) or 'admin' (My Profile)
 *   $acctTypeLocked   true = the student / faculty choice can't be changed any more
 *   $acctOrgLocked    true = the organization can't be changed (moderators)
 *   $acctSchoolIdLocked true = the school ID is verified (only an admin changes it)
 *   $acctOptional  true = department / organization / student-or-faculty may be left empty (admins)
 */
$acct = $acct ?? [];
$isAuth = ($acctStyle ?? 'admin') === 'auth';
$inputClass = $isAuth ? 'login-input' : 'admin-input';
$labelClass = $isAuth ? '' : 'admin-label';
$acctType = $acct['account_type'] ?? '';
$acctLevels = parse_year_levels($acct['irregular_year_levels'] ?? []);
$acctVal = fn(string $key): string => htmlspecialchars((string) ($acct[$key] ?? ''));
// A moderator's department follows their (locked) organization — unless it isn't set yet.
$deptLocked = !empty($acctOrgLocked) && !empty($acct['department_id']);

// Organizations grouped under their department (the list shows only the chosen department's).
$deptNames = array_column($departments, 'name', 'id');
$orgGroups = [];
foreach ($organizations as $o) {
    $orgGroups[$o['department_id'] ?? ''][] = $o;
}
uksort($orgGroups, fn($a, $b) => strcmp($deptNames[$a] ?? 'zzz', $deptNames[$b] ?? 'zzz'));
?>
<div class="acct-fields<?= $isAuth ? ' register-full' : '' ?>" data-account-fields>
  <fieldset class="acct-type">
    <legend class="<?= $labelClass ?>">I am a <span class="req">*</span></legend>
    <?php if (!empty($acctTypeLocked)): ?>
      <input type="hidden" name="account_type" value="<?= htmlspecialchars($acctType) ?>">
      <p class="acct-locked"><strong><?= htmlspecialchars(label_of(ACCOUNT_TYPES, $acctType)) ?></strong> account · only an admin can change this.</p>
    <?php else: ?>
      <div class="acct-choice">
        <?php foreach (ACCOUNT_TYPES as $key => $label): ?>
          <label class="acct-option">
            <input type="radio" name="account_type" value="<?= $key ?>" <?= $acctType === $key ? 'checked' : '' ?><?= empty($acctOptional) ? ' required' : '' ?>>
            <span><strong><?= $label ?></strong><small><?= $key === 'student' ? 'Needs your School ID and year level' : 'Teachers and staff' ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </fieldset>

  <div class="acct-grid">
    <div>
      <label class="<?= $labelClass ?>" for="department_id">Department <span class="req">*</span></label>
      <select id="department_id" name="department_id" class="<?= $inputClass ?>" <?= empty($acctOptional) ? 'required' : '' ?><?= $deptLocked ? ' disabled' : '' ?>>
        <option value="">Choose your department</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= htmlspecialchars($d['id']) ?>" <?= ($acct['department_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars(department_label($d)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($deptLocked): ?><input type="hidden" name="department_id" value="<?= $acctVal('department_id') ?>"><?php endif; ?>
    </div>
    <div>
      <label class="<?= $labelClass ?>" for="organization_id">Organization <span class="req">*</span></label>
      <select id="organization_id" name="organization_id" class="<?= $inputClass ?>" <?= empty($acctOptional) ? 'required' : '' ?><?= !empty($acctOrgLocked) ? ' disabled' : '' ?>>
        <option value="">Choose your organization</option>
        <?php foreach ($orgGroups as $deptId => $orgs): ?>
          <optgroup label="<?= htmlspecialchars($deptNames[$deptId] ?? 'Other organizations') ?>" data-department="<?= htmlspecialchars((string) $deptId) ?>">
            <?php foreach ($orgs as $o): ?>
              <option value="<?= htmlspecialchars($o['id']) ?>" data-department="<?= htmlspecialchars((string) ($o['department_id'] ?? '')) ?>" <?= ($acct['organization_id'] ?? '') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($acctOrgLocked)): ?>
        <input type="hidden" name="organization_id" value="<?= $acctVal('organization_id') ?>">
        <p class="admin-hint">Moderators run their organization's events, so only an admin can change it.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Students only -->
  <div class="acct-student" data-student-only<?= $acctType === 'student' ? '' : ' hidden' ?>>
    <div class="acct-grid">
      <div>
        <label class="<?= $labelClass ?>" for="school_id">School ID <span class="req">*</span></label>
        <?php if (!empty($acctSchoolIdLocked)): ?>
          <input type="text" id="school_id" class="<?= $inputClass ?>" value="<?= $acctVal('school_id') ?>" readonly>
          <input type="hidden" name="school_id" value="<?= $acctVal('school_id') ?>">
          <p class="acct-verified">Verified by an admin. Ask an admin if it needs to change.</p>
        <?php else: ?>
          <input type="text" id="school_id" name="school_id" class="<?= $inputClass ?>" value="<?= $acctVal('school_id') ?>"
                 placeholder="e.g. <?= SCHOOL_ID_EXAMPLE ?>" maxlength="20" autocomplete="off" pattern="[A-Za-z0-9][A-Za-z0-9\- ]{3,24}" data-student-required>
        <?php endif; ?>
      </div>
      <div>
        <label class="<?= $labelClass ?>" for="course">Course <span class="req">*</span></label>
        <input type="text" id="course" name="course" class="<?= $inputClass ?>" list="course-options" placeholder="e.g. BSIT" maxlength="60" value="<?= $acctVal('course') ?>" data-student-required>
        <datalist id="course-options">
          <option value="BSIT"><option value="BSCS"><option value="BSCpE"><option value="BSECE"><option value="BSEE">
          <option value="BSIE"><option value="BSA"><option value="BSBA"><option value="BSN"><option value="BSPsych"><option value="BSEd">
        </datalist>
      </div>
      <div>
        <label class="<?= $labelClass ?>" for="year_level">Highest Year Level <span class="req">*</span></label>
        <select id="year_level" name="year_level" class="<?= $inputClass ?>" data-student-required>
          <option value="">Choose</option>
          <?php foreach (YEAR_LEVELS as $y => $label): ?>
            <option value="<?= $y ?>" <?= (string) ($acct['year_level'] ?? '') === (string) $y ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <span class="<?= $labelClass ?> acct-label">Student Status <span class="req">*</span></span>
        <div class="acct-status">
          <?php foreach (STUDENT_TYPES as $key => $label): ?>
            <label><input type="radio" name="student_type" value="<?= $key ?>" <?= ($acct['student_type'] ?? '') === $key ? 'checked' : '' ?> data-student-required> <?= $label ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <p class="acct-irregular-summary" data-irregular-summary<?= ($acct['student_type'] ?? '') === 'irregular' ? '' : ' hidden' ?>>
      <span data-irregular-text><?= $acctLevels ? 'Irregular · subjects in ' . htmlspecialchars(year_levels_label($acctLevels)) : 'Irregular · year levels not set yet' ?></span>
      <button type="button" class="acct-link" data-irregular-open>Change</button>
    </p>

    <!-- Pop-up for irregular students (its fields are part of the form) -->
    <dialog class="acct-modal" id="irregularModal" aria-labelledby="irregularTitle">
      <h2 id="irregularTitle">Irregular student registration</h2>
      <p>Irregular students take subjects from more than one year level. Pick <strong>every</strong> year level you have subjects in this semester. Your highest year level is always included.</p>
      <div class="acct-levels">
        <?php foreach (YEAR_LEVELS as $y => $label): ?>
          <label><input type="checkbox" name="irregular_year_levels[]" value="<?= $y ?>" <?= in_array($y, $acctLevels, true) ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </div>
      <div class="acct-rules">
        <strong>Registration rules for irregular students</strong>
        <ul>
          <li>You can register for events open to <em>any</em> of the year levels you pick.</li>
          <li>Events limited to other year levels stay closed to you.</li>
          <li>Your highest year level can't be lower than a year level you pick.</li>
          <li>Moderators and admins see the year levels you declare, and admins may check them against the registrar's list.</li>
        </ul>
      </div>
      <label class="acct-ack"><input type="checkbox" data-irregular-ack <?= $acctLevels ? 'checked' : '' ?>> I confirm these are the year levels of my enrolled subjects.</label>
      <p class="acct-modal-error" data-irregular-error hidden></p>
      <div class="acct-modal-actions">
        <button type="button" class="btn btn-outline" data-irregular-cancel>Cancel</button>
        <button type="button" class="btn btn-primary" data-irregular-confirm>Confirm</button>
      </div>
    </dialog>
  </div>
</div>
