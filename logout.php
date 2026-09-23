<?php
/**
 * ShopInnKart - Sign out.
 *
 * Ending a session is a state change, so it needs proof the request came from
 * one of our own pages: either a POST carrying the CSRF field, or a GET whose
 * query string carries the same token.
 *
 * A bare GET - <img src="logout.php">, a link on someone else's site, a
 * prefetcher warming links - cannot supply that token, because the attacker's
 * page cannot read this session's token across origins. Those requests fall
 * through to the confirmation card below, which only renders HTML: an <img>
 * tag has no way to submit the form inside it, so nobody gets logged out
 * behind their back. The card is also why the footer's plain "Logout" link
 * still works instead of dead-ending.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/auth-layout.php';

$name     = current_user_name();
$signedIn = is_logged_in();

if (is_post()) {
    csrf_require();
    $authorised = true;
} else {
    $supplied = isset($_GET[CSRF_TOKEN_NAME]) && is_string($_GET[CSRF_TOKEN_NAME]) ? $_GET[CSRF_TOKEN_NAME] : '';
    $authorised = csrf_verify($supplied);
}

if ($authorised) {
    // "Everywhere" only from a POST: a GET carrying the token could be
    // prefetched, and ending every session of the account is not something to
    // do on a link's say-so.
    $everywhere = is_post() && input_bool('everywhere');
    logout_user($everywhere);

    flash('success', $everywhere
        ? 'Signed out on this device and everywhere else. Sign in again to carry on.'
        : ($name === ''
            ? 'You have been signed out.'
            : 'See you soon, ' . $name . '. You have been signed out.'));

    redirect(url());
}

auth_layout_start([
    'title'      => 'Sign Out',
    'heading'    => $signedIn ? 'Sign out of your account?' : 'You are already signed out',
    'subheading' => $signedIn
        ? 'Your cart and wishlist are saved to your account, so they will be waiting next time.'
        : 'There is no active session on this device.',
]);
?>

<?php if ($signedIn): ?>

    <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg">
            <?= icon('logout', 'w-4 h-4') ?>
            <span class="sik-btn__label">Yes, sign me out</span>
        </button>

        <?php // The way back from a borrowed laptop or a stolen phone. ?>
        <label class="sik-check" style="margin-top:var(--sp-3);justify-content:center">
            <input type="checkbox" name="everywhere" value="1">
            <span>Sign out on all my other devices too</span>
        </label>
    </form>

    <a class="sik-btn sik-btn--outline sik-btn--block" style="margin-top:var(--sp-3)"
       href="<?= e(url('account.php')) ?>">Stay signed in</a>

<?php else: ?>

    <a class="sik-btn sik-btn--primary sik-btn--block sik-btn--lg" href="<?= e(url('login.php')) ?>">Sign In</a>

<?php endif; ?>

<?php
auth_layout_end($signedIn ? [] : [
    'text'  => 'New here?',
    'label' => 'Create an account',
    'url'   => url('register.php'),
]);
