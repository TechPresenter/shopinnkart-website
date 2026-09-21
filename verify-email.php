<?php
/**
 * ShopInnKart - Confirm an email address from an emailed link.
 *
 * Two deliberate differences from reset-password.php, both of which matter:
 *
 *  1. The token is validated on GET but only CONSUMED on POST. Corporate mail
 *     gateways, link scanners and inbox previewers fetch every URL in a
 *     message; a link that verifies on GET is burned before the human clicks.
 *
 *  2. This never signs anybody in. A verification link proves possession of an
 *     inbox, not of the account — treating it as an authentication credential
 *     would turn a forwarded message into a full takeover.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

// input() reads POST before GET, so this covers the emailed link and the round
// trip through the confirm button.
$token = (string) input('token', '');
$email = mb_strtolower((string) input('email', ''));

$linkProblem = '';
$verified    = false;
$record      = $token === '' ? null : find_email_verification($token);
$user        = null;

if ($record === null) {
    $linkProblem = 'This confirmation link has expired or has already been used.';
} elseif ($email === '' || !hash_equals(mb_strtolower((string) $record['email']), $email)) {
    // Both halves travel together, so a token that leaks on its own cannot be
    // replayed against a different account.
    $linkProblem = 'This confirmation link is not valid. Please request a new one.';
} else {
    // Always re-read the account from the token record, never from the query
    // string or the session.
    $user = Database::fetch('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => (int) $record['user_id']]);

    if ($user === null || (string) $user['status'] !== 'active') {
        $linkProblem = 'This account is no longer active. Please contact support.';
        $user = null;
    } elseif (user_email_verified($user)) {
        $verified = true;
    }
}

if (is_post() && $user !== null && !$verified) {
    csrf_require();

    if (consume_email_verification($record)) {
        $verified = true;
        log_activity('customer.email_verified', 'user', (int) $user['id'],
            'Confirmed the address ' . $user['email']);

        // Drop the token out of the URL so it does not survive in history, in
        // a Referer header or in the access log.
        flash('success', 'Thank you — your email address is confirmed.');
        redirect(url(current_user_id() === (int) $user['id'] ? 'account.php' : 'login.php'));
    }

    $linkProblem = 'We could not confirm the address just now. Please try the link again.';
}

seo_set(['robots' => 'noindex, nofollow']);

auth_layout_start([
    'title'       => 'Confirm your email',
    'description' => 'Confirm the email address on your ShopInnKart account.',
    'heading'     => $linkProblem !== ''
        ? 'This link is no longer valid'
        : ($verified ? 'Your email is confirmed' : 'Confirm your email address'),
    'subheading'  => $linkProblem === '' && !$verified
        ? 'One click and we are done.'
        : '',
]);
?>

<?php if ($linkProblem !== ''): ?>

    <div class="sik-empty sik-empty--sm">
        <?= icon('mail', 'w-12 h-12') ?>
        <h2 class="sik-empty__title">We cannot use this link</h2>
        <p class="sik-empty__text"><?= e($linkProblem) ?></p>
        <p class="sik-empty__text">
            Sign in and we will offer to send a fresh one. Your account works normally in the meantime.
        </p>
        <a class="sik-btn sik-btn--primary" href="<?= e(url('login.php')) ?>">Sign in</a>
    </div>

<?php elseif ($verified): ?>

    <div class="sik-empty sik-empty--sm">
        <?= icon('check-circle', 'w-12 h-12') ?>
        <h2 class="sik-empty__title">All set</h2>
        <p class="sik-empty__text">
            <strong><?= e((string) $record['email']) ?></strong> is confirmed. Order updates and
            invoices will reach you here.
        </p>
        <a class="sik-btn sik-btn--primary" href="<?= e(url('account.php')) ?>">Go to my account</a>
    </div>

<?php else: ?>

    <p style="text-align:center;font-size:13px;color:var(--sik-muted);margin-bottom:var(--sp-5)">
        Confirming <strong><?= e((string) $record['email']) ?></strong>
    </p>

    <form method="post" action="<?= e(url('verify-email.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="email" value="<?= e((string) $record['email']) ?>">

        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <span class="sik-btn__label">Confirm my email address</span>
        </button>
    </form>

    <p style="text-align:center;font-size:12px;color:var(--sik-muted);margin-top:var(--sp-4)">
        If you did not create an account with us, you can close this page and ignore the email.
    </p>

<?php endif; ?>

<?php
auth_layout_end([
    'text'  => 'Already confirmed?',
    'label' => 'Go to sign in',
    'url'   => url('login.php'),
]);
