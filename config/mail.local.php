<?php
/**
 * Email settings for notifications — the site emails every new notification
 * itself, over SMTP (config/supabase.php → smtp_send()).
 *
 * 1. Copy this file to config/mail.local.php (same folder). That copy is in
 *    .gitignore, so the password never goes to GitHub. Keep it out of
 *    screenshots and chats too.
 * 2. With a Gmail / Google Workspace account (e.g. the site's own Gmail):
 *      - turn on 2-Step Verification for that account;
 *      - create an App Password: Google Account → Security → 2-Step
 *        Verification → App passwords (name it "Campus Event Hub");
 *      - paste the 16-letter password below (spaces don't matter).
 *    The same address and App Password can be used for Supabase's own
 *    emails (Authentication → Emails → SMTP Settings), which send the
 *    "confirm your email" and password-reset links.
 * 3. Reload any page. Database update › Site setup shows "Email notifications: on".
 *
 * Limits: a regular Gmail account sends to about 500 people a day, a Google
 * Workspace account about 2,000. (Instead of this file, the server can set
 * SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL and
 * SMTP_FROM_NAME.)
 */
return [
    'host' => 'smtp.gmail.com',
    'port' => 465,                           // 465 = SSL; 587 = STARTTLS
    'username' => 'arianjander.abergas@email.lcup.edu.ph',  // the account that sends
    'password' => 'uzza jdog bryt fdau',
    'from_email' => '',                      // empty = the username (Gmail requires the same address)
    'from_name' => 'Campus Event Hub',
];
