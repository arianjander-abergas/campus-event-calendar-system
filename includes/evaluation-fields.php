<?php
/**
 * The event evaluation form's questions, grouped by section (Part I, Part II…)
 * — used by the student form (enduser/evaluate.php) and the admin preview
 * (admin/evaluation-form.php). Set before including:
 *   $questions  active questions in order (get_evaluation_questions())
 *   $answers    [question id => value] to re-fill after a failed submit (optional)
 *   $preview    true = read-only preview for admins (optional)
 *
 * Ratings are 5 stars, 1 to 5 from left to right; choosing a star fills it and
 * the ones before it (CSS only: admin.css "Evaluation form").
 */
$answers = $answers ?? [];
$preview = !empty($preview);
$scaleUp = array_reverse(RATING_SCALE, true); // 1 … 5
$scaleText = implode(' | ', array_map(fn($v, $l) => $v . ' – ' . $l, array_keys($scaleUp), $scaleUp));
$star = '<svg class="eval-star" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.8l2.75 5.75 6.3.8-4.62 4.37 1.17 6.25L12 16.9l-5.6 3.07 1.17-6.25L2.95 9.35l6.3-.8z"/></svg>';
?>
<?php foreach (group_rows($questions, fn($q) => $q['section']) as $section => $items):
  $hasRatings = (bool) array_filter($items, fn($q) => $q['question_type'] === 'rating');
  $allOptional = !array_filter($items, fn($q) => $q['is_required']);
?>
  <fieldset class="eval-section">
    <legend class="eval-section-title"><?= htmlspecialchars($section) ?><?php if ($allOptional): ?> <span class="eval-optional">Optional</span><?php endif; ?></legend>
    <?php if ($hasRatings): ?><p class="eval-section-scale"><?= htmlspecialchars($scaleText) ?></p><?php endif; ?>
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
            <?php foreach ($scaleUp as $value => $label): ?>
              <label class="eval-option" title="<?= $value ?> – <?= htmlspecialchars($label) ?>">
                <input type="radio" name="<?= htmlspecialchars($fieldName) ?>" value="<?= $value ?>"
                  <?= $current === (string) $value ? 'checked' : '' ?>
                  <?= $q['is_required'] && !$preview ? 'required' : '' ?>
                  <?= $preview ? 'disabled' : '' ?>>
                <span class="eval-option-num"><?= $value ?></span>
                <?= $star ?>
                <span class="visually-hidden"><?= htmlspecialchars($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="eval-question">
          <label class="eval-q-text" for="<?= $fieldId ?>"><?= htmlspecialchars($q['question']) ?><?= $required ?></label>
          <textarea id="<?= $fieldId ?>" name="<?= htmlspecialchars($fieldName) ?>" class="admin-input" rows="3" maxlength="2000"
            placeholder="<?= $q['is_required'] ? 'Long answer' : 'Optional' ?>"
            <?= $q['is_required'] && !$preview ? 'required' : '' ?> <?= $preview ? 'disabled' : '' ?>><?= htmlspecialchars($current) ?></textarea>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </fieldset>
<?php endforeach; ?>
