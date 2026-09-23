<?php
/**
 * ShopInnKart - Unsubscribe from the newsletter.
 *
 * Every newsletter email has carried a link to this page since the first
 * build; the page itself was never written, so the link 404'd and customers
 * could not opt out at all - which the privacy policy promises they can, and
 * which is what keeps a sender out of spam folders.
 *
 * No login: the recipient of a marketing email is usually not a registered
 * customer, and asking them to create an account to stop the mail is exactly
 * the dark pattern the promise exists to prevent. The link is authenticated by
 * an HMAC of the address instead, so it works for its own recipient only and
 * cannot be walked through the subscriber list.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$email = mb_strtolower(trim((string) input('email', '')));
$token = trim((string) input('token', ''));

$state = 'invalid';     // invalid | done | already | unknown
$subscriber = null;

if ($email !== '' && $token !== '' && newsletter_unsubscribe_token_valid($email, $token)) {
    // Unsubscribing is a state change, so it should not happen on the GET that
    // a mail client's link-scanner fires. The page posts to itself instead, and
    // the GET only confirms who the link belongs to.
    $subscriber = Database::fetch(
        'SELECT `id`, `email`, `status` FROM `newsletter_subscribers` WHERE `email` = :e LIMIT 1',
        ['e' => $email]
    );

    if ($subscriber === null) {
        $state = 'unknown';
    } elseif ((string) $subscriber['status'] === 'unsubscribed') {
        $state = 'already';
    } else {
        $state = 'confirm';
    }

    if (is_post() && $state === 'confirm') {
        csrf_require();
        Database::update(
            'newsletter_subscribers',
            ['status' => 'unsubscribed'],
            '`id` = :id',
            ['id' => (int) $subscriber['id']]
        );
        security_event('privacy.newsletter_unsubscribed', 'info', ['email' => mask_email($email)]);
        $state = 'done';
    }
} else {
    // A wrong or missing token is logged: a run of them is somebody walking
    // the subscriber list, which is worth seeing on the security screen.
    if ($email !== '' || $token !== '') {
        security_event('privacy.unsubscribe_token_invalid', 'low', ['email' => mask_email($email)]);
    }
}

seo_set([
    'title'  => 'Newsletter preferences',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container">
    <div class="sik-empty" style="padding-block:var(--section-y-lg) var(--section-y);max-width:560px;margin-inline:auto">
        <?php if ($state === 'done'): ?>
            <h1 class="sik-empty__title">You have been unsubscribed</h1>
            <p class="sik-empty__text">
                We will not send <strong><?= e($email) ?></strong> any more newsletters.
                Order updates and delivery notices for anything you buy are separate and still arrive.
            </p>

        <?php elseif ($state === 'already'): ?>
            <h1 class="sik-empty__title">Already unsubscribed</h1>
            <p class="sik-empty__text">
                <strong><?= e($email) ?></strong> is not on the newsletter list. Nothing more to do.
            </p>

        <?php elseif ($state === 'unknown'): ?>
            <h1 class="sik-empty__title">Nothing to unsubscribe</h1>
            <p class="sik-empty__text">
                We have no newsletter subscription for <strong><?= e($email) ?></strong>.
            </p>

        <?php elseif ($state === 'confirm'): ?>
            <h1 class="sik-empty__title">Stop the newsletter?</h1>
            <p class="sik-empty__text">
                Confirm and we will stop sending offers and new-arrival mail to
                <strong><?= e($email) ?></strong>.
            </p>
            <form method="post" style="margin-top:var(--sp-4)">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e_attr($email) ?>">
                <input type="hidden" name="token" value="<?= e_attr($token) ?>">
                <button type="submit" class="sik-btn sik-btn--primary">Unsubscribe me</button>
                <a class="sik-btn sik-btn--outline" href="<?= e(url()) ?>">Keep it, take me home</a>
            </form>

        <?php else: ?>
            <h1 class="sik-empty__title">This link is not valid</h1>
            <p class="sik-empty__text">
                Unsubscribe links are tied to one address and one mail. Open the newest newsletter
                and use the link at the bottom of it, or
                <a href="<?= e(url('contact.php')) ?>">contact us</a> and we will remove you.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
