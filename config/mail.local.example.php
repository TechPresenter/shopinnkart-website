<?php
/**
 * ShopInnKart - Local SMTP credentials (example).
 *
 * Copy this file to `mail.local.php` and fill it in to keep production SMTP
 * credentials out of the database and out of version control. This directory
 * is blocked from the web by config/.htaccess, and mail.local.php is listed in
 * .gitignore.
 *
 * Precedence, highest first:
 *   1. environment variables  (SIK_SMTP_HOST, SIK_SMTP_PORT, SIK_SMTP_USERNAME,
 *                              SIK_SMTP_PASSWORD, SIK_SMTP_ENCRYPTION,
 *                              SIK_MAIL_FROM_EMAIL, SIK_MAIL_FROM_NAME,
 *                              SIK_MAIL_REPLY_TO, SIK_MAIL_TRANSPORT,
 *                              SIK_SMTP_TIMEOUT, SIK_SMTP_AUTH)
 *   2. this file
 *   3. Admin -> Settings -> Email  (password encrypted at rest)
 *
 * Anything set here overrides the admin panel; the Email settings screen shows
 * a warning naming the fields that are being overridden so nobody edits a value
 * that cannot take effect.
 *
 * Never commit the filled-in mail.local.php.
 */

declare(strict_types=1);

return [
    // 'smtp' delivers for real. 'log' writes .eml files to storage/logs/mail/
    // and delivers nothing — useful on a dev machine with no relay.
    // 'disabled' queues messages but never sends them.
    'transport'  => 'smtp',

    'host'       => 'smtp.example.com',
    'port'       => 587,
    'username'   => 'no-reply@example.com',
    'password'   => '',

    // 'tls' = STARTTLS on 587, 'ssl' = implicit TLS on 465, 'none' = plaintext.
    'encryption' => 'tls',

    // Turn off only for a relay that authorises your server by IP.
    'auth'       => true,

    'from_email' => 'no-reply@example.com',
    'from_name'  => 'ShopInnKart',
    'reply_to'   => 'support@example.com',

    'timeout'    => 20,
];
