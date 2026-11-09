<?php
/**
 * Event Hub Assistant settings (the AI chat box — includes/assistant.php).
 *
 * 1. Copy this file to config/assistant.local.php (same folder).
 * 2. Paste your Anthropic API key below. Get one at https://console.anthropic.com
 *    (Settings → API keys); usage is billed to that account.
 * 3. Reload any page: the chat box appears in the bottom-right corner for everyone.
 *
 * config/assistant.local.php is listed in .gitignore, so the key never goes
 * to GitHub. Keep it out of screenshots and chats too. (Instead of the file,
 * the server can set the ANTHROPIC_API_KEY environment variable.)
 */
return [
    // Your secret key, starting with "sk-ant-".
    'api_key' => 'paste-your-key-here',

    // The Claude model that answers. claude-opus-5-5 is the most capable;
    // claude-sonnet-5-5 or claude-haiku-5-5 answer faster and cost less.
    'model' => 'claude-opus-5-5',

    // Most questions the whole site may ask in 24 hours (each one costs a little).
    // Each person also gets at most 150 a day (visitors 40) and 12 in 2 minutes.
    'daily_limit' => 500,
];
