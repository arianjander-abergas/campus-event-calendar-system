<?php
/**
 * The Event Hub Assistant chat box: the round button in the bottom-right
 * corner of every page with the sidebar (included by includes/nav.php) and
 * the panel it opens. Behaviour: assets/js/assistant.js (it moves this to the
 * end of the page); answers: api/assistant.php + includes/assistant.php;
 * styles: assets/css/assistant.css.
 *
 * The conversation lives in the PHP session, so it's the same on every page
 * until "New chat" or logging out. Without an Anthropic API key (see
 * config/assistant.local.example.php) it answers in basic mode, and says so.
 */
require_once __DIR__ . '/assistant.php';

$assistantUser = current_user();
$assistantAi = assistant_has_key();
$assistantBoot = [
    'endpoint' => BASE_URL . '/api/assistant.php',
    'base' => BASE_URL,
    'ready' => true,
    'log' => assistant_display_log(),
    'busy' => assistant_busy(),
    'greeting' => assistant_greeting($assistantUser),
    'suggestions' => assistant_suggestions($assistantUser),
    'maxLength' => ASSISTANT_MAX_MESSAGE,
];
$assistantSvg = fn(string $paths, int $size, string $class = '') => '<svg' . ($class !== '' ? ' class="' . $class . '"' : '')
    . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$assistantSpark = '<path d="M12 3.5l1.9 4.6 4.6 1.9-4.6 1.9L12 16.5l-1.9-4.6L5.5 10l4.6-1.9z" fill="currentColor" stroke="none"/><path d="M18.5 15.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z" fill="currentColor" stroke="none"/>';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/assistant.css?v=<?= filemtime(__DIR__ . '/../public/assets/css/assistant.css') ?>">
<div class="assistant" data-assistant hidden>
  <section class="assistant-panel" id="assistantPanel" data-assistant-panel role="dialog" aria-labelledby="assistantTitle" hidden>
    <header class="assistant-head">
      <span class="assistant-avatar"><?= $assistantSvg($assistantSpark, 20) ?></span>
      <div class="assistant-head-text">
        <h2 class="assistant-title" id="assistantTitle">Event Hub Assistant</h2>
        <p class="assistant-sub"><?php if (!$assistantAi): ?><span class="assistant-mode">Basic mode</span> <?php endif; ?>Ask about events and the site</p>
      </div>
      <button type="button" class="assistant-icon-btn" data-assistant-reset title="New chat" aria-label="Start a new chat">
        <?= $assistantSvg('<path d="M3 12a9 9 0 1 0 2.6-6.4"/><path d="M3 4v5h5"/>', 18) ?>
      </button>
      <button type="button" class="assistant-icon-btn" data-assistant-close title="Close" aria-label="Close the assistant">
        <?= $assistantSvg('<path d="M18 6L6 18M6 6l12 12"/>', 18) ?>
      </button>
    </header>

    <div class="assistant-log" data-assistant-log role="log" aria-live="polite" aria-label="Conversation"></div>

    <form class="assistant-form" data-assistant-form>
      <textarea class="assistant-input" data-assistant-input rows="1" maxlength="<?= ASSISTANT_MAX_MESSAGE ?>"
        placeholder="Ask about events, organizations…" aria-label="Message the assistant"></textarea>
      <button type="submit" class="assistant-send" data-assistant-send aria-label="Send">
        <?= $assistantSvg('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>', 18) ?>
      </button>
    </form>
    <p class="assistant-note">
      <?php if ($assistantAi): ?>
        AI answers can be wrong, so check the event's page. The assistant only reads the site; it can't change anything.
      <?php else: ?>
        Basic mode understands common questions about events and pages. It only reads the site; it can't change anything.
        <?php if (($assistantUser['role'] ?? '') === 'admin'): ?>
          Admins: add an Anthropic API key in <code>config/assistant.local.php</code> for the full AI.
        <?php endif; ?>
      <?php endif; ?>
    </p>
  </section>

  <button type="button" class="assistant-launcher" data-assistant-toggle aria-controls="assistantPanel" aria-expanded="false"
    aria-label="Open the Event Hub Assistant" title="Ask the Event Hub Assistant">
    <?= $assistantSvg('<path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 20.5l1.6-5A8.4 8.4 0 1 1 21 11.5z"/><path d="M12.4 7.6l1 2.4 2.4 1-2.4 1-1 2.4-1-2.4-2.4-1 2.4-1z" fill="currentColor" stroke="none"/>', 26, 'assistant-icon-chat') ?>
    <?= $assistantSvg('<path d="M18 6L6 18M6 6l12 12"/>', 24, 'assistant-icon-close') ?>
    <span class="assistant-dot" data-assistant-dot hidden></span>
  </button>
</div>
<script type="application/json" id="assistantData"><?= json_encode($assistantBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= BASE_URL ?>/assets/js/assistant.js?v=<?= filemtime(__DIR__ . '/../public/assets/js/assistant.js') ?>" defer></script>
