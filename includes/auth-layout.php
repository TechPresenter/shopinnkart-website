<?php
/**
 * ShopInnKart - Chrome for the authentication pages.
 *
 * login.php, register.php, forgot-password.php, reset-password.php and
 * logout.php all render the same centred card on a soft background, so the
 * card lives here once instead of five times.
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once __DIR__ . '/init.php';
}

/**
 * One alert block. Used for flash messages and for the form-level errors the
 * no-JavaScript path renders server side.
 */
function auth_alert(string $type, string $message): string
{
    $icons = [
        'success' => 'check-circle',
        'error'   => 'alert',
        'warning' => 'alert',
        'info'    => 'info',
    ];
    if (!isset($icons[$type])) {
        $type = 'info';
    }

    return '<div class="sik-alert sik-alert--' . e($type) . '" style="margin-bottom:var(--sp-4)">'
        . icon($icons[$type], 'w-5 h-5')
        . '<div>' . e($message) . '</div>'
        . '</div>';
}

/**
 * Open an auth page: SEO, storefront header, soft backdrop, card, logo and
 * heading. Everything the page echoes afterwards sits inside the card.
 *
 * @param array{title?:string,description?:string,heading:string,subheading?:string,width?:int} $options
 */
function auth_layout_start(array $options): void
{
    $heading   = (string) ($options['heading'] ?? '');
    $sub       = (string) ($options['subheading'] ?? '');
    $width     = (int) ($options['width'] ?? 460);
    $storeName = (string) setting('store_name', SITE_NAME);
    $script    = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

    seo_set([
        'title'       => (string) ($options['title'] ?? $heading),
        'description' => (string) ($options['description'] ?? ''),
        // A crawler only ever sees the empty form, and a sign-in screen has no
        // business turning up in search results.
        'robots'      => 'noindex, follow',
        'canonical'   => url($script),
    ]);

    // Taken before the header so the toast layer never receives them: these
    // pages have to read correctly with JavaScript switched off, so the
    // messages are printed inside the card instead.
    $flashes = flash_pull();

    require INCLUDES_PATH . '/header.php';
    ?>
    <div class="sik-auth">
        <div class="sik-container sik-auth__inner" style="max-width:<?= $width ?>px">
            <div class="sik-auth__card">

                <?php // Both files ship and CSS picks the one this theme needs, so the
                      // card reads on the light and the dark palette alike. ?>
                <a href="<?= e(url()) ?>" class="sik-auth__logo sik-logo" aria-label="<?= e($storeName) ?> home">
                    <?= brand_logo($storeName) ?>
                </a>

                <h1 class="sik-auth__title"><?= e($heading) ?></h1>

                <?php if ($sub !== ''): ?>
                    <p class="sik-auth__sub"><?= e($sub) ?></p>
                <?php endif; ?>

                <?php foreach ($flashes as $flashMessage): ?>
                    <?= auth_alert((string) ($flashMessage['type'] ?? 'info'), (string) ($flashMessage['message'] ?? '')) ?>
                <?php endforeach; ?>
    <?php
}

/**
 * Close the card and the page. $options carries the link to this page's
 * counterpart: ['text' => 'New here?', 'label' => 'Create an account', 'url' => ...].
 */
function auth_layout_end(array $options = []): void
{
    $text      = (string) ($options['text'] ?? '');
    $label     = (string) ($options['label'] ?? '');
    $link      = (string) ($options['url'] ?? '');
    $storeName = (string) setting('store_name', SITE_NAME);
    ?>
                <?php if ($label !== '' && $link !== ''): ?>
                    <div class="sik-divider sik-auth__rule"></div>
                    <p class="sik-auth__alt">
                        <?php if ($text !== ''): ?><?= e($text) ?> <?php endif; ?>
                        <a href="<?= e($link) ?>"><?= e($label) ?></a>
                    </p>
                <?php endif; ?>
            </div>

            <p class="sik-auth__back">
                <a href="<?= e(url()) ?>">&larr; Back to <?= e($storeName) ?></a>
            </p>
        </div>
    </div>
    <?php
    require INCLUDES_PATH . '/footer.php';
}

/**
 * Password field with the show/hide toggle account.js binds to.
 * Passing 'meter' switches on the strength meter (new passwords only).
 */
function auth_password_field(array $options): void
{
    $id          = (string) $options['id'];
    $name        = (string) ($options['name'] ?? 'password');
    $label       = (string) ($options['label'] ?? 'Password');
    $autocomplete = (string) ($options['autocomplete'] ?? 'current-password');
    $placeholder = (string) ($options['placeholder'] ?? '');
    $error       = (string) ($options['error'] ?? '');
    $help        = (string) ($options['help'] ?? '');
    $meter       = (string) ($options['meter'] ?? '');
    // Optional ['label' => .., 'url' => ..] shown on the right of the label row.
    $aside       = is_array($options['aside'] ?? null) ? $options['aside'] : null;
    // Only marked on forms that also carry optional fields.
    $mark        = empty($options['required_mark']) ? '' : ' <span class="req">*</span>';
    ?>
    <div class="sik-field">
        <?php if ($aside !== null): ?>
            <div class="flex items-center justify-between" style="margin-bottom:var(--sp-2);gap:var(--sp-3)">
                <label class="sik-label" for="<?= e($id) ?>" style="margin-bottom:0"><?= e($label) ?><?= $mark ?></label>
                <?php /* --sik-primary is 3.48:1 on white, under AA for text this
                         size; the ink tone is the same hue at 5.37:1. 12.5px was
                         also off the type scale. */ ?>
                <a href="<?= e((string) $aside['url']) ?>"
                   style="font-size:var(--fs-xs);font-weight:var(--fw-semibold);color:var(--sik-primary-ink)"><?= e((string) $aside['label']) ?></a>
            </div>
        <?php else: ?>
            <label class="sik-label" for="<?= e($id) ?>"><?= e($label) ?><?= $mark ?></label>
        <?php endif; ?>

        <div style="position:relative">
            <input class="sik-input<?= $error !== '' ? ' is-invalid' : '' ?>" type="password"
                   id="<?= e($id) ?>" name="<?= e($name) ?>"
                   autocomplete="<?= e($autocomplete) ?>"
                   <?php if ($placeholder !== ''): ?>placeholder="<?= e($placeholder) ?>" <?php endif; ?>
                   <?php if ($meter !== ''): ?>data-password-meter="<?= e($meter) ?>" <?php endif; ?>
                   <?php // Only when a password is being chosen - an older account may hold a shorter one. ?>
                   <?php if (!empty($options['new_password'])): ?>minlength="<?= PASSWORD_MIN_LENGTH ?>" <?php endif; ?>
                   required style="padding-right:var(--sp-9)">

            <?php // Pinned to the top of the wrapper, not centred in it: account.js appends
                  // validation errors here and a centred button would drift off the input. ?>
            <button type="button" class="sik-iconbtn" data-toggle-password="#<?= e($id) ?>"
                    style="position:absolute;right:5px;top:5px;width:34px;height:34px"
                    aria-label="Show password"><?= icon('eye', 'w-4 h-4') ?></button>
        </div>

        <?php if ($meter !== ''): ?>
            <?php // No inline display rule here: it would beat [hidden] and leave an empty meter on screen. ?>
            <div id="<?= e(ltrim($meter, '#')) ?>" hidden style="margin-top:var(--sp-2)" aria-live="polite"></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <span class="sik-error"><?= e($error) ?></span>
        <?php elseif ($help !== ''): ?>
            <span class="sik-help"><?= e($help) ?></span>
        <?php endif; ?>
    </div>
    <?php
}
