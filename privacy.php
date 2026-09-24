<?php
/**
 * ShopInnKart - Data & Privacy (customer account).
 *
 * Where a customer exercises the two rights the store's privacy policy already
 * promises: a copy of everything held about them, and deletion.
 *
 * Neither happens on a click. Both send a link to the address on the account
 * and do nothing until that link is opened while signed in, so a borrowed
 * laptop cannot download somebody's life, and a stolen inbox cannot delete
 * their account. Both are rate limited and both are written to the security
 * log.
 *
 * The deletion text here comes from privacy_erase_plan() - the same list the
 * confirmation email prints and the same list the code carries out. It is
 * written down once so the promise and the behaviour cannot drift apart.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/account-layout.php';
require_once INCLUDES_PATH . '/privacy.php';

$user   = require_login();
$userId = (int) $user['id'];
$selfUrl = url('privacy.php');

// The owner can switch self-service off. The right does not go away with it -
// it goes back to being handled by a person - so the page still loads and
// says where to write instead.
$selfService = privacy_enabled();

// ---------------------------------------------------------------------------
//  Confirming a link from the email
// ---------------------------------------------------------------------------
$confirmToken = trim((string) ($_GET['confirm'] ?? ''));

if ($confirmToken !== '') {
    $request = privacy_find_by_token($confirmToken);

    if ($request === null) {
        flash('error', 'That link has expired or has already been used. Start again below.');
        redirect($selfUrl);
    }

    // The token alone is not enough. Email accounts get borrowed, forwarded
    // and breached; the link only works for the person it was sent about,
    // signed in.
    if ((int) $request['user_id'] !== $userId) {
        security_event('privacy.confirm_mismatch', 'high', [
            'request_id' => (int) $request['id'],
        ], $userId, 'customer');
        flash('error', 'That link belongs to a different account. Sign in as that account to use it.');
        redirect($selfUrl);
    }

    // The confirmation itself is a POST: a link opened by a mail client's
    // preview fetcher must not delete an account.
    if (!is_post()) {
        seo_set(['title' => 'Confirm your request', 'robots' => 'noindex, nofollow']);
        require INCLUDES_PATH . '/header.php';
        account_layout_open('privacy', ['subtitle' => 'One more step.']);
        $plan = privacy_erase_plan();
        ?>
        <div class="sik-panel" style="max-width:680px">
            <div class="sik-panel__head">
                <h2 class="sik-panel__title">
                    <?= (string) $request['type'] === 'delete'
                        ? 'Delete your account?'
                        : 'Build your data file?' ?>
                </h2>
            </div>
            <div class="sik-panel__body">
                <?php if ((string) $request['type'] === 'delete'): ?>
                    <div class="sik-alert sik-alert--warning" style="margin-bottom:var(--sp-4)">
                        <?= icon('alert', 'w-5 h-5') ?>
                        <span><strong>This cannot be undone.</strong> You will be signed out and will not
                        be able to sign in again.</span>
                    </div>

                    <p style="margin:0 0 var(--sp-2) 0"><strong>Removed completely</strong></p>
                    <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
                        <?php foreach ($plan['removed'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
                    </ul>

                    <p style="margin:0 0 var(--sp-2) 0"><strong>Kept, but no longer connected to you</strong></p>
                    <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
                        <?php foreach ($plan['anonymised'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
                    </ul>

                    <p style="margin:0 0 var(--sp-2) 0"><strong>Kept, because we are required to</strong></p>
                    <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
                        <?php foreach ($plan['kept'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="margin:0 0 var(--sp-4) 0;line-height:1.8">
                        We will put everything we hold about you into one file and tell you when it is
                        ready. It waits <?= (int) privacy_bundle_days() ?> days in your account and is then
                        deleted. We do not email the file &mdash; it holds every address and order you have
                        ever had.
                    </p>
                <?php endif; ?>

                <form method="post" action="<?= e($selfUrl . '?confirm=' . urlencode($confirmToken)) ?>">
                    <?= csrf_field() ?>
                    <button type="submit"
                            class="sik-btn <?= (string) $request['type'] === 'delete' ? 'sik-btn--danger' : 'sik-btn--primary' ?>">
                        <span class="sik-btn__label">
                            <?= (string) $request['type'] === 'delete' ? 'Yes, delete my account' : 'Yes, build my file' ?>
                        </span>
                    </button>
                    <a class="sik-btn sik-btn--ghost" href="<?= e($selfUrl) ?>">
                        <span class="sik-btn__label">Cancel</span>
                    </a>
                </form>
            </div>
        </div>
        <?php
        account_layout_close();
        require INCLUDES_PATH . '/footer.php';
        exit;
    }

    csrf_require();

    $result = privacy_fulfil($request, ['actor' => 'customer']);

    if (!$result['ok']) {
        flash('error', $result['error']);
        redirect($selfUrl);
    }

    if ((string) $request['type'] === 'delete') {
        // Nothing is left to come back to, so the session goes with it.
        logout_user();
        flash('success', 'Your account has been deleted. We have emailed a record of what was done '
            . 'to the address that was on the account.');
        redirect(url('index.php'));
    }

    flash('success', 'Your file is ready. Download it below.');
    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
//  Asking
// ---------------------------------------------------------------------------
if (is_post()) {
    csrf_require();

    $action = (string) input('action', '');

    if (in_array($action, ['export', 'delete'], true)) {
        if (!$selfService) {
            flash('error', 'These requests are handled by a person at the moment. Write to us and we '
                . 'will take care of it.');
            redirect($selfUrl);
        }

        $result = privacy_request_create([
            'user_id' => $userId,
            'type'    => $action,
            'by'      => 'customer',
        ]);

        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Check your email. We have sent a link to ' . mask_email((string) $user['email'])
              . ' - the request does nothing until you open it.'
            : $result['error']);

        redirect($selfUrl);
    }

    if ($action === 'cancel') {
        $request = Database::fetch(
            "SELECT * FROM `privacy_requests`
              WHERE `id` = :id AND `user_id` = :uid AND `status` IN ('pending', 'ready')",
            ['id' => input_int('id'), 'uid' => $userId]
        );

        if ($request !== null) {
            Database::update('privacy_requests', [
                'status'     => 'cancelled',
                'token_hash' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = :id', ['id' => (int) $request['id']]);

            security_event('privacy.cancelled', 'info',
                ['request_id' => (int) $request['id']], $userId, 'customer');
        }

        flash('success', 'Cancelled.');
        redirect($selfUrl);
    }

    // -----------------------------------------------------------------------
    //  Downloading the finished file
    //
    //  The password again, even though they are already signed in. A session
    //  left open on a shared computer should not be able to hand over every
    //  address and order in one file.
    // -----------------------------------------------------------------------
    if ($action === 'download') {
        $request = Database::fetch(
            "SELECT * FROM `privacy_requests`
              WHERE `id` = :id AND `user_id` = :uid AND `status` = 'ready'",
            ['id' => input_int('id'), 'uid' => $userId]
        );

        if ($request === null || (string) $request['file_name'] === '') {
            flash('error', 'That file is no longer available. Ask for a new one below.');
            redirect($selfUrl);
        }

        if (!rate_limit_attempt('privacy.download', 'user:' . $userId, 10, 3600)) {
            security_event('privacy.download_throttled', 'medium', [], $userId, 'customer');
            flash('error', 'Too many tries. Wait an hour and try again.');
            redirect($selfUrl);
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($password === '' || !password_verify_app($password, (string) $user['password'])) {
            security_event('privacy.download_denied', 'medium',
                ['reason' => 'wrong password'], $userId, 'customer');
            flash('error', 'That is not your password.');
            redirect($selfUrl);
        }

        rate_limit_clear('privacy.download', 'user:' . $userId);

        try {
            $stream = privacy_bundle_stream($request);
            $first  = $stream->current();
        } catch (Throwable $e) {
            flash('error', 'That file could not be read. Ask for a new one below.');
            redirect($selfUrl);
        }

        Database::update('privacy_requests', [
            'downloads'     => (int) $request['downloads'] + 1,
            'downloaded_at' => date('Y-m-d H:i:s'),
        ], '`id` = :id', ['id' => (int) $request['id']]);

        security_event('privacy.export_downloaded', 'high', [
            'request_id' => (int) $request['id'],
            'by'         => 'customer',
        ], $userId, 'customer');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $name = preg_replace('/\.enc$/', '', (string) $request['file_name']);
        header('Content-Type: ' . (str_ends_with((string) $name, '.zip') ? 'application/zip' : 'application/json'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo $first;
        $stream->next();
        while ($stream->valid()) {
            echo $stream->current();
            flush();
            $stream->next();
        }
        exit;
    }

    redirect($selfUrl);
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$requests = Database::fetchAll(
    'SELECT * FROM `privacy_requests` WHERE `user_id` = :uid ORDER BY `id` DESC LIMIT 20',
    ['uid' => $userId]
);

$ready      = null;
$openExport = null;
$openDelete = null;
foreach ($requests as $row) {
    if ($ready === null && (string) $row['status'] === 'ready' && (string) $row['file_name'] !== '') {
        $ready = $row;
    }
    if ($openExport === null && (string) $row['type'] === 'export'
        && in_array((string) $row['status'], ['pending', 'ready'], true)) {
        $openExport = $row;
    }
    if ($openDelete === null && (string) $row['type'] === 'delete' && (string) $row['status'] === 'pending') {
        $openDelete = $row;
    }
}

$plan       = privacy_erase_plan();
$supportUrl = url('contact.php');

seo_set([
    'title'       => 'Data & Privacy',
    'description' => 'Download a copy of everything we hold about you, or ask us to delete your account.',
    'robots'      => 'noindex, nofollow',
]);

require INCLUDES_PATH . '/header.php';

account_layout_open('privacy', [
    'subtitle' => 'A copy of what we hold about you, and how to have it removed.',
]);
?>

<?php if (!$selfService): ?>
    <div class="sik-alert sik-alert--info" style="margin-bottom:var(--sp-4)">
        <?= icon('info', 'w-5 h-5') ?>
        <span>
            These requests are handled by a person at the moment.
            <a href="<?= e($supportUrl) ?>">Write to us</a> and we will take care of it &mdash; your
            rights do not depend on this button being here.
        </span>
    </div>
<?php endif; ?>

<?php if ($ready !== null): ?>
    <div class="sik-panel" style="max-width:680px;margin-bottom:var(--sp-4)">
        <div class="sik-panel__head">
            <h2 class="sik-panel__title">Your file is ready</h2>
        </div>
        <div class="sik-panel__body">
            <p style="margin:0 0 var(--sp-4) 0;line-height:1.8">
                Built <?= e(time_ago((string) $ready['completed_at'])) ?>,
                <?= e(format_bytes((int) $ready['file_bytes'])) ?>.
                It is deleted from our server
                <?= $ready['expires_at'] !== null
                    ? 'on ' . e(format_datetime((string) $ready['expires_at'], 'd M Y'))
                    : 'after a few days' ?>.
                Your password again, because this one file holds everything.
            </p>
            <form method="post" action="<?= e($selfUrl) ?>" style="display:flex;gap:var(--sp-2);flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="download">
                <input type="hidden" name="id" value="<?= (int) $ready['id'] ?>">
                <label class="sik-sr" for="dlPassword">Your password</label>
                <input class="sik-input" id="dlPassword" name="password" type="password"
                       autocomplete="current-password" placeholder="Your password"
                       style="max-width:260px" required>
                <button type="submit" class="sik-btn sik-btn--primary">
                    <span class="sik-btn__label">Download</span>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="sik-panel" style="max-width:680px;margin-bottom:var(--sp-4)">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Download a copy of your data</h2>
    </div>
    <div class="sik-panel__body">
        <p style="margin:0 0 var(--sp-4) 0;line-height:1.8">
            Your profile, addresses, orders and what was in them, invoices, payments, returns,
            reviews, notifications, preferences, wishlist, searches and sign-in history &mdash; in one
            file. Your password and two-factor secret are left out on purpose: they are credentials,
            not information about you, and a copy of them in your Downloads folder would only ever
            help somebody else.
        </p>

        <?php if ($openExport !== null && (string) $openExport['status'] === 'pending'): ?>
            <div class="sik-alert sik-alert--info" style="margin-bottom:var(--sp-4)">
                <?= icon('mail', 'w-5 h-5') ?>
                <span>
                    We emailed a link to <?= e(mask_email((string) $user['email'])) ?>
                    <?= e(time_ago((string) $openExport['created_at'])) ?>. Open it to go ahead.
                </span>
            </div>
            <form method="post" action="<?= e($selfUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $openExport['id'] ?>">
                <button type="submit" class="sik-btn sik-btn--ghost">
                    <span class="sik-btn__label">Cancel that request</span>
                </button>
            </form>
        <?php elseif ($selfService): ?>
            <form method="post" action="<?= e($selfUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="export">
                <button type="submit" class="sik-btn sik-btn--primary">
                    <span class="sik-btn__label">Email me a link</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="sik-panel" style="max-width:680px">
    <div class="sik-panel__head">
        <h2 class="sik-panel__title">Delete your account</h2>
    </div>
    <div class="sik-panel__body">
        <p style="margin:0 0 var(--sp-4) 0;line-height:1.8">
            We would rather tell you exactly what this does than say "your data is deleted" and leave
            you to find out. Some of it legally has to stay, and pretending otherwise would be the
            easier answer, not the true one.
        </p>

        <p style="margin:0 0 var(--sp-2) 0"><strong>Removed completely</strong></p>
        <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
            <?php foreach ($plan['removed'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>

        <p style="margin:0 0 var(--sp-2) 0"><strong>Kept, but no longer connected to you</strong></p>
        <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
            <?php foreach ($plan['anonymised'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>

        <p style="margin:0 0 var(--sp-2) 0"><strong>Kept, because we are required to</strong></p>
        <ul style="margin:0 0 var(--sp-4) 0;padding-left:20px;line-height:1.8">
            <?php foreach ($plan['kept'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>

        <?php if ($openDelete !== null): ?>
            <div class="sik-alert sik-alert--warning" style="margin-bottom:var(--sp-4)">
                <?= icon('alert', 'w-5 h-5') ?>
                <span>
                    A deletion link was emailed to <?= e(mask_email((string) $user['email'])) ?>
                    <?= e(time_ago((string) $openDelete['created_at'])) ?>. Nothing has happened yet.
                </span>
            </div>
            <form method="post" action="<?= e($selfUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $openDelete['id'] ?>">
                <button type="submit" class="sik-btn sik-btn--primary">
                    <span class="sik-btn__label">Cancel the deletion</span>
                </button>
            </form>
        <?php elseif ($selfService): ?>
            <form method="post" action="<?= e($selfUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="sik-btn sik-btn--danger">
                    <span class="sik-btn__label">Email me a deletion link</span>
                </button>
            </form>
            <p class="sik-help" style="margin-top:var(--sp-2)">
                Nothing happens until you open the link. If you have an order still on its way,
                wait until it arrives &mdash; we will no longer be able to contact you about it.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($requests !== []): ?>
    <div class="sik-panel" style="max-width:680px;margin-top:var(--sp-4)">
        <div class="sik-panel__head">
            <h2 class="sik-panel__title">What you have asked for before</h2>
        </div>
        <div class="sik-panel__body">
            <ul style="margin:0;padding-left:20px;line-height:1.9">
                <?php foreach ($requests as $row): ?>
                    <li>
                        <?= e(PRIVACY_TYPES[(string) $row['type']] ?? (string) $row['type']) ?>
                        &mdash; <?= e(privacy_status_meta((string) $row['status'])[0]) ?>,
                        <?= e(format_datetime((string) $row['created_at'], 'd M Y')) ?>
                        <?php if ((string) $row['requested_by'] === 'admin'): ?>
                            <span class="sik-help">(logged by our team)</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php
account_layout_close();
require INCLUDES_PATH . '/footer.php';
