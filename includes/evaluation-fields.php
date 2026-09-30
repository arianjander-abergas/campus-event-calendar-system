<?php
/**
 * The event evaluation form's questions, grouped by section — used by the
 * student form (enduser/evaluate.php) and the admin preview
 * (admin/evaluation-form.php). Set before including:
 *   $questions  active questions in order (get_evaluation_questions())
 *   $answers    [question id => value] to re-fill after a failed submit (optional)
 *   $preview    true = read-only preview for admins (optional)
 */
$answers = $answers ?? [];
$preview = !empty($preview);
?>
<?php foreach (group_rows($questions, fn($q) => $q['section']) as $section => $items): ?>
  <fieldset class="eval-section">
    <legend class="eval-section-title"><?= htmlspecialchars($section) ?></legend>
    <?php foreach ($items as $q):
      $fieldName = 'answers[' . $q['id'] . ']';
      $fieldId = 'q-' . ($preview ? 'preview-' : '') . $q['id'];
      $current = (string) ($answers[$q['id']] ?? '');
      $required = $q['is_required'] ? ' <span class="req" aria-hidden="true">*</span>' : '';
    ?>
      <?php if ($q['question_type'] === 'rating'): ?>
        <div class="eval-question" role="radiogroup" aria-labelledby="<?= $fieldId ?>"<?= $q['is_required'] ? ' aria-required="true"' : '' ?>>
          <p class="eval-q-text" id="<?= $fieldId ?>"><?= htmlspecialchars($q['question']) ?><?= $required ?></p>
          <div class="eval-scale">
            <?php foreach (RATING_SCALE as $value => $label): ?>
              <label class="eval-option">
                <input type="radio" name="<?= htmlspecialchars($fieldName) ?>" value="<?= $value ?>"
                  <?= $current === (string) $value ? 'checked' : '' ?>
                  <?= $q['is_required'] && !$preview ? 'required' : '' ?>
                  <?= $preview ? 'disabled' : '' ?>>
                <span class="eval-option-num"><?= $value ?></span>
                <span class="eval-option-label"><?= htmlspecialchars($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="eval-question">
          <label class="eval-q-text" for="<?= $fieldId ?>"><?= htmlspecialchars($q['question']) ?><?= $required ?></label>
          <textarea id="<?= $fieldId ?>" name="<?= htmlspecialchars($fieldName) ?>" class="admin-input" rows="3" maxlength="2000"
            placeholder="<?= $q['is_required'] ? 'Your answer' : 'Optional' ?>"
            <?= $q['is_required'] && !$preview ? 'required' : '' ?> <?= $preview ? 'disabled' : '' ?>><?= htmlspecialchars($current) ?></textarea>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </fieldset>
<?php endforeach; ?>
