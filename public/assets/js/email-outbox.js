/**
 * Campus Event Hub — notification emails, sent in the background.
 * After an event is published / updated / cancelled / approved, the next
 * page (for admins and moderators) loads this script, which asks
 * api/notification-emails.php to send the waiting emails one batch at a
 * time and shows a small note while it works. Leaving the page is fine: the
 * next page picks up where this one stopped. It also runs every 10 minutes
 * while an Admin uses the site, so emails that failed are tried again.
 */
(function () {
  var url = window.EMAIL_OUTBOX_URL;
  if (!url || !window.fetch) return;

  var note = document.createElement('div');
  note.className = 'email-outbox';
  note.setAttribute('role', 'status');
  note.hidden = true;
  document.body.appendChild(note);

  var sent = 0;
  var failed = 0;
  function show(text, done) {
    note.textContent = text;
    note.hidden = false;
    note.classList.toggle('is-done', !!done);
    if (done) setTimeout(function () { note.hidden = true; }, 6000);
  }

  function batch(round) {
    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.enabled) return; // email isn't set up yet: nothing to say
        sent += res.sent || 0;
        failed += res.failed || 0;
        if (res.more && round < 60) {
          show('Emailing the notifications… ' + sent + ' sent');
          setTimeout(function () { batch(round + 1); }, 400);
          return;
        }
        if (sent || failed) {
          var people = function (n) { return n + ' ' + (n === 1 ? 'person' : 'people'); };
          var why = res.error ? ' (' + String(res.error).slice(0, 120) + ')' : '';
          show(failed
            ? (sent ? 'Emailed ' + people(sent) + '; the emails to ' : 'The emails to ') + people(failed) + ' couldn’t be sent yet' + why + '. They’ll be tried again later.'
            : 'Notification emails sent to ' + people(sent) + '.', true);
        }
      })
      .catch(function () { /* offline: the next page tries again */ });
  }
  batch(1);
})();
