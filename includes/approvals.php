<?php
/**
 * Approvals (sidebar; admin/users.php?tab=approvals): what waits for a
 * Super Admin, in two lists —
 *   Event approvals  Admins' new events, published once approved
 *   Profile edits    changes to someone's details, saved once approved
 * Waiting / Approved / Rejected, 10 per page. Super Admins see everything,
 * with Approve / Reject / Note (a message to the sender before deciding);
 * Admins see their own organization's events and their own edits, with the
 * Super Admins' notes. The database checks all of it too
 * (trg_events_approval, review_profile_change(), note_profile_change()).
 *
 * From users.php: $isSuper, $kind, $decision, $approvalsUrl, $approvalsReady,
 * $waitingEvents, $waitingProfiles, $names, $departments, $organizations,
 * $pager, $pageUrl, $here.
 */
$decisions = ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'];
?>
<?php if (!$approvalsReady): ?>
  <div class="admin-note">
    <strong>Approvals aren't switched on yet.</strong> Run <code>supabase/migrations/20261017120000_super_admin_approvals.sql</code>
    and then <code>20261018090000_three_role_levels.sql</code> in Supabase → SQL Editor (paste everything, Run). After that,
    Admins' new events and profile edits wait here for a Super Admin.
  </div>
<?php else: ?>
  <p class="admin-hint admin-hint-block approvals-intro">
    <?php if ($isSuper): ?>
      New events by Admins are published, and profile edits are saved, once a Super Admin approves them.
      Use <strong>Note</strong> to tell the sender what to change before you decide; rejecting keeps things as they were. Notes are shown to the person who sent it.
    <?php else: ?>
      Your organization's new events are published, and your profile edits are saved, once a Super Admin approves them.
      Notes from the Super Admins show here — change your event (Manage Events › Update Events) or profile (Edit my profile) and it stays in the queue.
    <?php endif; ?>
  </p>

  <div class="admin-tabs admin-subtabs" role="tablist" aria-label="Approvals">
    <a href="<?= htmlspecialchars($approvalsUrl('events')) ?>" class="admin-tab<?= $kind === 'events' ? ' active' : '' ?>" role="tab" aria-selected="<?= $kind === 'events' ? 'true' : 'false' ?>">
      Event approvals<?php if ($waitingEvents): ?> <span class="tab-badge"><?= count($waitingEvents) ?></span><?php endif; ?>
    </a>
    <a href="<?= htmlspecialchars($approvalsUrl('profiles')) ?>" class="admin-tab<?= $kind === 'profiles' ? ' active' : '' ?>" role="tab" aria-selected="<?= $kind === 'profiles' ? 'true' : 'false' ?>">
      Profile edits<?php if ($waitingProfiles): ?> <span class="tab-badge"><?= count($waitingProfiles) ?></span><?php endif; ?>
    </a>
  </div>

  <nav class="approval-filter" aria-label="Show">
    <?php foreach ($decisions as $key => $label): ?>
      <a href="<?= htmlspecialchars($approvalsUrl($kind, $key)) ?>" class="btn-sm<?= $decision === $key ? ' btn-sm-accent' : '' ?>"<?= $decision === $key ? ' aria-current="true"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="admin-table-wrap">
    <table class="admin-table approvals-table">
      <?php if ($kind === 'events'): ?>
        <thead><tr><th>Event</th><th>When &amp; where</th><th>Sent by</th><th><?= $decision === 'pending' ? 'Sent' : 'Decision' ?></th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php if (!$pager['rows']): ?>
            <tr><td colspan="5" class="admin-table-empty"><?= $decision === 'pending' ? 'No events are waiting for approval.' : 'No ' . strtolower($decisions[$decision]) . ' events yet.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($pager['rows'] as $ev): ?>
            <tr>
              <td>
                <a href="../event-details.php?id=<?= urlencode($ev['id']) ?>" class="admin-table-title"><?= htmlspecialchars($ev['title']) ?></a>
                <div class="admin-table-sub"><?= htmlspecialchars(implode(' · ', array_filter([event_category_label($ev, ''), $ev['organizations']['name'] ?? '']))) ?></div>
              </td>
              <td>
                <?= htmlspecialchars(event_when($ev)) ?>
                <div class="admin-table-sub"><?= htmlspecialchars(event_place($ev)) ?></div>
              </td>
              <td><?= htmlspecialchars($names[$ev['created_by'] ?? ''] ?? 'An admin') ?></td>
              <td class="nowrap">
                <?php if ($decision === 'pending'): ?>
                  <?= htmlspecialchars(format_timestamp($ev['approval_requested_at'] ?? null)) ?>
                  <?php if (!empty($ev['approval_note'])): ?><div class="approval-note"><span class="approval-note-label">Note:</span> “<?= htmlspecialchars($ev['approval_note']) ?>”</div><?php endif; ?>
                <?php else: ?>
                  <span class="admin-status admin-status-<?= $decision === 'approved' ? 'published' : 'cancelled' ?>"><?= $decisions[$decision] ?></span>
                  <div class="admin-table-sub"><?= htmlspecialchars(format_timestamp($ev['approval_reviewed_at'] ?? null)) ?><?= !empty($names[$ev['approval_reviewed_by'] ?? '']) ? ' by ' . htmlspecialchars($names[$ev['approval_reviewed_by']]) : '' ?></div>
                  <?php if (!empty($ev['approval_note'])): ?><div class="approval-note">“<?= htmlspecialchars($ev['approval_note']) ?>”</div><?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="admin-row-actions">
                <?php if ($isSuper && $decision === 'pending'): ?>
                  <form method="POST" action="users.php" data-confirm="Approve and publish “<?= htmlspecialchars($ev['title']) ?>”?<?= !empty($ev['send_notification']) ? ' Everyone will be notified.' : '' ?>" data-confirm-title="Approve event" data-confirm-ok="Approve &amp; publish">
                    <input type="hidden" name="action" value="review_event">
                    <input type="hidden" name="decision" value="approve">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($ev['id']) ?>">
                    <input type="hidden" name="back" value="<?= htmlspecialchars($here) ?>">
                    <button type="submit" class="btn-sm btn-sm-accent">Approve</button>
                  </form>
                  <button type="button" class="btn-sm btn-sm-danger" data-reject="review_event" data-id="<?= htmlspecialchars($ev['id']) ?>" data-name="<?= htmlspecialchars($ev['title']) ?>">Reject</button>
                  <button type="button" class="btn-sm" data-note="note_event" data-id="<?= htmlspecialchars($ev['id']) ?>" data-name="<?= htmlspecialchars($ev['title']) ?>" data-current="<?= htmlspecialchars((string) ($ev['approval_note'] ?? '')) ?>"><?= !empty($ev['approval_note']) ? 'Edit note' : 'Note' ?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php else: ?>
        <thead><tr><th>Account</th><th>Changes</th><th><?= $decision === 'pending' ? 'Sent' : 'Decision' ?></th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php if (!$pager['rows']): ?>
            <tr><td colspan="4" class="admin-table-empty"><?= $decision === 'pending' ? 'No profile edits are waiting for approval.' : 'No ' . strtolower($decisions[$decision]) . ' profile edits yet.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($pager['rows'] as $req):
            $who = $req['profiles'] ?? [];
            $who['school_id'] = $who['school_ids']['school_id'] ?? null;
          ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($who['full_name'] ?? 'Unknown account') ?></strong>
                <div class="admin-table-sub"><?= htmlspecialchars(role_display($who['role'] ?? 'user', $who['account_type'] ?? null)) ?></div>
              </td>
              <td>
                <ul class="approval-changes">
                  <?php foreach ((array) $req['changes'] as $key => $new): if (!isset(PROFILE_DETAIL_LABELS[$key])) continue; ?>
                    <li>
                      <span class="approval-field"><?= htmlspecialchars(PROFILE_DETAIL_LABELS[$key]) ?>:</span>
                      <?php if ($decision === 'pending'): ?><span class="approval-old"><?= htmlspecialchars(profile_value_label($key, $who[$key] ?? null, $departments, $organizations)) ?></span> <span aria-label="to">→</span><?php endif; ?>
                      <strong><?= htmlspecialchars(profile_value_label($key, $new, $departments, $organizations)) ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </td>
              <td class="nowrap">
                <?php if ($decision === 'pending'): ?>
                  <?= htmlspecialchars(format_timestamp($req['created_at'])) ?>
                  <?php if (!empty($req['review_note'])): ?><div class="approval-note"><span class="approval-note-label">Note:</span> “<?= htmlspecialchars($req['review_note']) ?>”</div><?php endif; ?>
                <?php else: ?>
                  <span class="admin-status admin-status-<?= $decision === 'approved' ? 'published' : 'cancelled' ?>"><?= $decisions[$decision] ?></span>
                  <div class="admin-table-sub"><?= htmlspecialchars(format_timestamp($req['reviewed_at'] ?? null)) ?><?= !empty($names[$req['reviewed_by'] ?? '']) ? ' by ' . htmlspecialchars($names[$req['reviewed_by']]) : '' ?></div>
                  <?php if (!empty($req['review_note'])): ?><div class="approval-note">“<?= htmlspecialchars($req['review_note']) ?>”</div><?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="admin-row-actions">
                <?php if ($isSuper && $decision === 'pending'): ?>
                  <form method="POST" action="users.php" data-confirm="Approve these changes to <?= htmlspecialchars($who['full_name'] ?? 'this account') ?>’s profile? They’re saved right away." data-confirm-title="Approve profile changes" data-confirm-ok="Approve">
                    <input type="hidden" name="action" value="review_profile">
                    <input type="hidden" name="decision" value="approve">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($req['id']) ?>">
                    <input type="hidden" name="back" value="<?= htmlspecialchars($here) ?>">
                    <button type="submit" class="btn-sm btn-sm-accent">Approve</button>
                  </form>
                  <button type="button" class="btn-sm btn-sm-danger" data-reject="review_profile" data-id="<?= htmlspecialchars($req['id']) ?>" data-name="<?= htmlspecialchars(($who['full_name'] ?? 'this account') . '’s profile changes') ?>">Reject</button>
                  <button type="button" class="btn-sm" data-note="note_profile" data-id="<?= htmlspecialchars($req['id']) ?>" data-name="<?= htmlspecialchars(($who['full_name'] ?? 'this account') . '’s profile changes') ?>" data-current="<?= htmlspecialchars((string) ($req['review_note'] ?? '')) ?>"><?= !empty($req['review_note']) ? 'Edit note' : 'Note' ?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endif; ?>
    </table>
  </div>
  <?= render_pagination($pager, $pageUrl, $kind === 'events' ? 'event' : 'request', $kind === 'events' ? 'events' : 'requests') ?>

  <?php if ($isSuper): ?>
    <!-- Reject: pop-up for the reason, filled by the row's Reject button -->
    <dialog class="acct-modal admin-modal" id="reject-modal" aria-labelledby="reject-modal-title">
      <form method="POST" action="users.php" autocomplete="off">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="decision" value="reject">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="back" value="<?= htmlspecialchars($here) ?>">
        <div class="admin-modal-head">
          <h2 id="reject-modal-title">Reject</h2>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
        </div>
        <p data-reject-what></p>
        <label class="admin-label" for="reject_note">Reason <span class="admin-muted">(optional — shown to them; empty keeps your earlier note)</span></label>
        <textarea id="reject_note" name="note" class="admin-input" rows="3" maxlength="500" placeholder="e.g. Please use your full name as on your school ID."></textarea>
        <div class="acct-modal-actions">
          <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
          <button type="submit" class="btn btn-danger">Reject</button>
        </div>
      </form>
    </dialog>

    <!-- Note: pop-up for a note to the sender, without deciding yet -->
    <dialog class="acct-modal admin-modal" id="note-modal" aria-labelledby="note-modal-title">
      <form method="POST" action="users.php" autocomplete="off">
        <input type="hidden" name="action" value="">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="back" value="<?= htmlspecialchars($here) ?>">
        <div class="admin-modal-head">
          <h2 id="note-modal-title">Note</h2>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
        </div>
        <p data-note-what></p>
        <label class="admin-label" for="approval_note">Note <span class="admin-muted">(shown to the person who sent it; leave empty to remove it)</span></label>
        <textarea id="approval_note" name="note" class="admin-input" rows="4" maxlength="500" placeholder="e.g. Please change the venue to the Gymnasium, then I'll approve it." data-note-text></textarea>
        <p class="admin-hint"><span data-note-count>0</span> / 500</p>
        <div class="acct-modal-actions">
          <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
          <button type="submit" class="btn btn-primary">Save note</button>
        </div>
      </form>
    </dialog>
    <script>
    (function () {
      // Note pop-up: filled by the row's Note button.
      var noteModal = document.getElementById('note-modal');
      var noteForm = noteModal.querySelector('form');
      var noteText = noteModal.querySelector('[data-note-text]');
      var noteCount = noteModal.querySelector('[data-note-count]');
      function countNote() { noteCount.textContent = noteText.value.length; }
      noteText.addEventListener('input', countNote);
      document.querySelectorAll('[data-note]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          noteForm.elements.action.value = btn.getAttribute('data-note');
          noteForm.elements.id.value = btn.getAttribute('data-id');
          noteText.value = btn.getAttribute('data-current') || '';
          countNote();
          noteModal.querySelector('[data-note-what]').textContent = 'A note on “' + btn.getAttribute('data-name') + '”. It stays waiting for approval — you can still approve or reject it later.';
          if (typeof noteModal.showModal === 'function') noteModal.showModal(); else noteModal.setAttribute('open', '');
          noteText.focus();
        });
      });
      noteModal.querySelectorAll('[data-modal-close]').forEach(function (b) {
        b.addEventListener('click', function () { if (typeof noteModal.close === 'function') noteModal.close(); else noteModal.removeAttribute('open'); });
      });
      noteModal.addEventListener('click', function (e) { if (e.target === noteModal) noteModal.close(); });
    })();
    (function () {
      var modal = document.getElementById('reject-modal');
      var form = modal.querySelector('form');
      function close() { if (typeof modal.close === 'function') modal.close(); else modal.removeAttribute('open'); }
      document.querySelectorAll('[data-reject]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          form.elements.action.value = btn.getAttribute('data-reject');
          form.elements.id.value = btn.getAttribute('data-id');
          form.elements.note.value = '';
          modal.querySelector('[data-reject-what]').textContent = 'Reject “' + btn.getAttribute('data-name') + '”? '
            + (btn.getAttribute('data-reject') === 'review_event' ? 'The event stays a draft; its admin can change it and send it again.' : 'Their profile stays as it is.');
          if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
          form.elements.note.focus();
        });
      });
      modal.querySelectorAll('[data-modal-close]').forEach(function (b) { b.addEventListener('click', close); });
      modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    })();
    </script>
  <?php endif; ?>
<?php endif; ?>
