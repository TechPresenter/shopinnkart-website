<?php
/**
 * ShopInnKart - 403 Forbidden
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

http_response_code(403);

seo_set([
    'title'  => 'Access Denied',
    'robots' => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';
?>
<div class="sik-container">
    <div class="sik-empty" style="padding-block:var(--section-y-lg) var(--section-y)">
        <?php /* A direct child of .sik-empty, so it takes the one illustrative
                 icon size the design system defines — same treatment as the
                 404 artwork. It used to be a hand-rolled 88px circle around an
                 off-scale 40px glyph, which is why the two error pages did not
                 look like the same product. */ ?>
        <?= icon('lock', 'w-12 h-12') ?>
        <p style="font-size:56px;font-weight:800;color:var(--sik-navy);line-height:1;margin-bottom:var(--sp-2)">403</p>
        <h1 class="sik-empty__title">You don&rsquo;t have access to this page</h1>
        <p class="sik-empty__text">
            You may need to sign in with a different account, or this area may be restricted.
        </p>
        <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
            <a class="sik-btn sik-btn--primary" href="<?= e(url()) ?>">Back to Home</a>
            <?php if (!is_logged_in()): ?>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('login.php')) ?>">Sign In</a>
            <?php else: ?>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('contact.php')) ?>">Contact Support</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
