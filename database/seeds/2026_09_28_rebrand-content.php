<?php
/**
 * ShopInnKart - carry the rename through everything a customer reads.
 *
 * 2026_09_28_store-identity.php set the settings the code reads. This one
 * deals with the harder half: the store's name is written into copy all over
 * the database - meta titles, the homepage hero, the footer, the FAQ, blog
 * posts, testimonials - and none of it goes through a setting.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 * ---------------------------------------------------------------------------
 * A rename is not a reason to rewrite the past.
 *
 *   invoices          An issued invoice is a legal document. It must keep the
 *                     name it was issued under, or the store's own records
 *                     stop matching the copies its customers hold - which is
 *                     the definition of a falsified book. Six invoices here
 *                     say ShopInnKart and they will say it forever.
 *   activity_logs     What somebody did, and what the store was called when
 *   login_history     they did it. An audit trail that can be edited to suit
 *   security_events   the present is not an audit trail.
 *   error_logs
 *   notification_queue  Emails already composed, some already sent. The sent
 *                     ones cannot be changed in the customer's inbox, so
 *                     changing the copy here would only make the record
 *                     disagree with what was actually sent.
 *   admins            A person's own name and email is not the store's brand.
 *
 * ---------------------------------------------------------------------------
 * AND WHAT IT LEAVES FOR A HUMAN
 * ---------------------------------------------------------------------------
 *   store_logo        A file path. Renaming the string without replacing the
 *   store_logo_light  image gives you a broken image; replacing the image is
 *                     a design job and the new artwork does not exist yet.
 *   social_*          Real account handles. Pointing them at /nscc when no
 *                     such account exists is worse than pointing them at an
 *                     old one that does.
 *   mail_from_email   Mail identity, which is about which domain can be
 *   admin_notify_email  proved to send - a different question from branding.
 *
 * It says all of this when it finishes rather than leaving it unsaid.
 *
 *     php database/seeds/2026_09_28_rebrand-content.php --dry
 *     php database/seeds/2026_09_28_rebrand-content.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv ?? [], true);

const REBRAND_FROM = 'ShopInnKart';
const REBRAND_TO   = 'NSCC';

/**
 * Where the name is COPY, table by column.
 *
 * Named explicitly rather than swept: a sweep would have found the invoices
 * and the audit trail too, and the whole point of this file is that it does
 * not touch those.
 *
 * @var array<string, array{cols: list<string>, label: string}>
 */
const REBRAND_TARGETS = [
    'seo_settings'      => ['cols' => ['meta_title', 'meta_description', 'meta_keywords',
                                       'og_title', 'og_description', 'twitter_title',
                                       'twitter_description', 'schema_json'],
                            'label' => 'Search and social previews'],
    'pages'             => ['cols' => ['title', 'content', 'meta_title', 'meta_description'],
                            'label' => 'CMS pages'],
    'faqs'              => ['cols' => ['question', 'answer'], 'label' => 'FAQ'],
    // Every column that holds WORDS, not a guess at two of them: the one this
    // first missed was a banner reading "Welcome to ShopInnKart" sitting in
    // `badge`, which no shortlist would have predicted.
    'banners'           => ['cols' => ['title', 'title_accent', 'subtitle', 'description',
                                       'badge', 'button_text', 'button2_text'],
                            'label' => 'Banners'],
    'homepage_sections' => ['cols' => ['title', 'subtitle', 'settings'], 'label' => 'Homepage rows'],
    'footer_columns'    => ['cols' => ['title'], 'label' => 'Footer'],
    'footer_links'      => ['cols' => ['label'], 'label' => 'Footer links'],
    'popups'            => ['cols' => ['title', 'body', 'button_text'], 'label' => 'Popups'],
    'trust_features'    => ['cols' => ['title', 'subtitle'], 'label' => 'Trust badges'],
    'menu_items'        => ['cols' => ['label'], 'label' => 'Menus'],
    'testimonials'      => ['cols' => ['content'], 'label' => 'Testimonials'],
    'blog_posts'        => ['cols' => ['title', 'excerpt', 'content', 'meta_title', 'meta_description'],
                            'label' => 'Blog'],
];

/**
 * Settings whose VALUE is copy. Everything else in `settings` is a path, a
 * handle or an address and is listed in the closing note instead.
 */
const REBRAND_SETTINGS = [
    'store_description', 'maintenance_message', 'copyright_text',
    'meta_title', 'meta_description', 'meta_keywords', 'mail_from_name',
];

/** Left alone on purpose, and said out loud at the end. */
const REBRAND_LEFT_ALONE = [
    'store_logo'         => 'a file path - the image itself still reads ShopInnKart',
    'store_logo_light'   => 'the same file, light variant',
    'social_facebook'    => 'a real account handle',
    'social_instagram'   => 'a real account handle',
    'social_twitter'     => 'a real account handle',
    'social_youtube'     => 'a real account handle',
    'social_linkedin'    => 'a real account handle',
    'store_email'        => 'the business address you gave - info@shopinnkart.com',
    'mail_from_email'    => 'mail identity: which domain can prove it sends',
    'admin_notify_email' => 'mail identity, same question',
];

// ---------------------------------------------------------------------------

$changed = 0;
$rows    = 0;

echo 'Rebrand content: ', REBRAND_FROM, ' -> ', REBRAND_TO, $dry ? ' (DRY RUN)' : '', "\n";
echo str_repeat('-', 66), "\n";

$pdo  = Database::connect();
$like = $pdo->quote('%' . REBRAND_FROM . '%');

// --- 1. settings -----------------------------------------------------------

echo "Settings\n";
foreach (REBRAND_SETTINGS as $key) {
    $row = Database::fetch(
        'SELECT `id`, `setting_value` FROM `settings` WHERE `setting_key` = :k', ['k' => $key]);
    if ($row === null) {
        continue;
    }
    $was = (string) $row['setting_value'];
    if (!str_contains($was, REBRAND_FROM)) {
        continue;
    }
    $now = str_replace(REBRAND_FROM, REBRAND_TO, $was);

    printf("  %-24s %s\n", $key, mb_substr($now, 0, 56));
    if (!$dry) {
        Database::update('settings', ['setting_value' => $now], '`id` = :id', ['id' => (int) $row['id']]);
    }
    $changed++;
    $rows++;
}

// --- 2. content tables -----------------------------------------------------

foreach (REBRAND_TARGETS as $table => $spec) {
    $present = [];
    try {
        $have = array_column(Database::fetchAll(sprintf('SHOW COLUMNS FROM `%s`', $table)), 'Field');
    } catch (Throwable $e) {
        continue;                       // an older database without this table
    }
    foreach ($spec['cols'] as $col) {
        if (in_array($col, $have, true)) {
            $present[] = $col;
        }
    }
    if ($present === []) {
        continue;
    }

    $where = implode(' OR ', array_map(
        static fn (string $c): string => sprintf('`%s` LIKE %s', $c, $like), $present));

    try {
        $hits = Database::fetchAll(
            sprintf('SELECT `id`, %s FROM `%s` WHERE %s',
                implode(', ', array_map(static fn ($c) => '`' . $c . '`', $present)), $table, $where));
    } catch (Throwable $e) {
        continue;
    }
    if ($hits === []) {
        continue;
    }

    // Printed lazily: MySQL's LIKE is case-insensitive and the replace is
    // not, on purpose - a row can match on "shopinnkart.com" inside a URL or
    // an email and correctly change nothing. Printing the heading first meant
    // a table announced itself and then listed no rows.
    $heading = false;

    foreach ($hits as $hit) {
        $set = [];
        foreach ($present as $col) {
            $was = (string) ($hit[$col] ?? '');
            if ($was === '' || !str_contains($was, REBRAND_FROM)) {
                continue;
            }
            $set[$col] = str_replace(REBRAND_FROM, REBRAND_TO, $was);
            $changed++;
        }
        if ($set === []) {
            continue;
        }

        if (!$heading) {
            printf("\n%s (%s)\n", $spec['label'], $table);
            $heading = true;
        }
        printf("  #%-4s %s\n", $hit['id'], implode(', ', array_keys($set)));
        if (!$dry) {
            Database::update($table, $set, '`id` = :id', ['id' => (int) $hit['id']]);
        }
        $rows++;
    }
}

// --- 3. contact details written INTO copy ----------------------------------
//
// The brand name is not the only thing hard-coded into page text. About Us
// tells a customer to email support@shopinnkart.com and call a number that
// was never real - and neither is the brand name, so the replace above
// correctly left both alone. They still have to be right.

echo "\nContact details inside page copy\n";

// A list of pairs, not a map: PHP turns a numeric-string array key into an
// int, so '9876543210' => '...' arrives at str_ireplace() as an integer and
// it refuses the argument.
$contactFixes = [
    ['support@shopinnkart.com', (string) setting('store_email', 'info@shopinnkart.com')],
    ['+91 98765 43210',         (string) setting('store_phone', '')],
    ['+919876543210',           (string) setting('store_whatsapp', '')],
    ['9876543210',              (string) setting('store_phone', '')],
];

foreach (['pages' => ['content'], 'faqs' => ['answer'], 'blog_posts' => ['content']] as $table => $cols) {
    foreach ($cols as $col) {
        try {
            $hits = Database::fetchAll(sprintf(
                'SELECT `id`, `%s` FROM `%s` WHERE %s', $col, $table,
                implode(' OR ', array_map(
                    static fn (array $f): string => sprintf('`%s` LIKE %s', $col, $pdo->quote('%' . $f[0] . '%')),
                    $contactFixes))));
        } catch (Throwable $e) {
            continue;
        }

        foreach ($hits as $hit) {
            $was = (string) $hit[$col];
            $now = $was;
            foreach ($contactFixes as [$old, $new]) {
                if ($new !== '') {
                    $now = str_ireplace($old, $new, $now);
                }
            }
            if ($now === $was) {
                continue;
            }
            printf("  %-14s #%-4s %s\n", $table, $hit['id'], $col);
            if (!$dry) {
                Database::update($table, [$col => $now], '`id` = :id', ['id' => (int) $hit['id']]);
            }
            $changed++;
            $rows++;
        }
    }
}

if (!$dry && $rows > 0 && function_exists('cache_bust')) {
    cache_bust();
}

echo "\n", str_repeat('-', 66), "\n";
printf("%d value(s) across %d row(s)%s\n", $changed, $rows, $dry ? ' would change' : ' changed');

// --- what was left, and why ------------------------------------------------

echo "\nNOT TOUCHED - the past is not rewritten:\n";
foreach (['invoices' => 'issued invoices are legal documents and keep the name they were issued under',
          'activity_logs' => 'an audit trail that can be edited to suit the present is not one',
          'login_history' => 'the same',
          'security_events' => 'the same',
          'notification_queue' => 'emails already composed, some already sent',
          'admins' => 'a person\'s own name is not the store\'s brand'] as $table => $why) {
    try {
        $n = (int) Database::fetchColumn(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE CONCAT_WS(\' \', %s) LIKE %s',
            $table,
            implode(', ', array_map(
                static fn ($c) => '`' . $c . '`',
                array_slice(array_column(Database::fetchAll(
                    sprintf('SHOW COLUMNS FROM `%s`', $table)), 'Field'), 0, 12))),
            $like));
    } catch (Throwable $e) {
        continue;
    }
    if ($n > 0) {
        printf("  %-20s %4d row(s) still say %s - %s\n", $table, $n, REBRAND_FROM, $why);
    }
}

echo "\nLEFT FOR YOU, because guessing would be worse:\n";
foreach (REBRAND_LEFT_ALONE as $key => $why) {
    $value = (string) setting($key, '');
    if ($value === '' || !str_contains($value . $key, 'shopinnkart') && !str_contains(strtolower($value), 'shopinnkart')) {
        continue;
    }
    printf("  %-20s %-44s %s\n", $key, mb_substr($value, 0, 44), $why);
}

echo "\n  The logo is the one a customer notices first. New artwork goes in\n";
echo "  Appearance > Customization; until then the header still reads ", REBRAND_FROM, ".\n";

exit(0);
