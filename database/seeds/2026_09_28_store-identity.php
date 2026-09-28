<?php
/**
 * ShopInnKart - the store's real business identity.
 *
 * Everything on the store that says who it is was placeholder: the phone was
 * +91 98765 43210, the support address was support@shopinnkart.com, there was
 * no postal address at all and no GSTIN - which means the tax invoices the
 * store issues were not tax invoices, because an Indian invoice without the
 * seller's GSTIN and place of business is not one.
 *
 * WHAT IS NOT HERE, DELIBERATELY: the bank account. Nothing in this store
 * reads settlement details - a payment gateway settles to the account
 * configured in ITS OWN dashboard, not to one recorded here, and inventing a
 * settings row nothing reads would only make it look configured. If the
 * account is wanted on invoices so customers can pay by NEFT, that is a
 * different thing and it belongs in invoice_terms, where it would be printed
 * for every customer - which is the point of it, and worth deciding on
 * purpose. See the note this prints when it finishes.
 *
 * Run from the project root. Idempotent: it only writes a value that differs,
 * and it never overwrites something the owner has already set to a third
 * thing without saying so.
 *
 *     php database/seeds/2026_09_28_store-identity.php --dry
 *     php database/seeds/2026_09_28_store-identity.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv ?? [], true);

/**
 * The owner's details, as supplied.
 *
 * The keys are the ones the app already reads - none of this invents a
 * setting. store_* feed the contact page, the footer and the LocalBusiness
 * structured data; invoice_* and gst_number feed the PDF.
 */
const STORE_IDENTITY = [
    // --- who the shop is to a customer -----------------------------------
    'store_name'     => 'NSCC',
    'store_email'    => 'info@shopinnkart.com',
    'store_phone'    => '8130038099',
    // Digits only, with the country code: this is what the wa.me link is
    // built from, and wa.me refuses anything else.
    'store_whatsapp' => '918130038099',
    'store_address'  => 'SN05, Ground Floor, Digamber Jain Shopping Complex, '
        . 'Railway Road, Karnal - 132001',

    // --- who the shop is on a tax invoice ---------------------------------
    // The legal entity, which is not the brand. Navkar Enterprises issues the
    // invoice; NSCC is the name over the door.
    'invoice_company_name'    => 'Navkar Enterprises',
    'invoice_company_address' => 'SN05, Ground Floor, Digamber Jain Shopping Complex, '
        . 'Railway Road, Karnal - 132001, Haryana',
    // Decides CGST+SGST versus IGST on every invoice, so it is not cosmetic.
    'invoice_seller_state'    => 'Haryana',
    'gst_number'              => '06AQXPJ7622A2ZR',
];

/** The categories the shop actually sells. */
const STORE_CATEGORIES = [
    // The existing category is renamed rather than replaced: it holds the
    // whole catalogue, and a new empty one beside it would orphan every
    // product. admin/categories/_save.php calls seo_slug_redirect(), so the
    // old URL 301s to the new one - but this seed writes directly, so it
    // records the redirect itself below.
    ['from' => 'festive-decor-lighting', 'name' => 'Decorative Lights', 'slug' => 'decorative-lights'],
    ['name' => 'Hair Accessories',   'slug' => 'hair-accessories'],
    ['name' => 'Mobile Accessories', 'slug' => 'mobile-accessories'],
    ['name' => 'Trending Products',  'slug' => 'trending-products'],
];

// ---------------------------------------------------------------------------

$applied = [];
$skipped = [];
$notes   = [];

echo "Store identity", $dry ? ' (DRY RUN)' : '', "\n";
echo str_repeat('-', 66), "\n";

// --- 1. settings -----------------------------------------------------------

foreach (STORE_IDENTITY as $key => $want) {
    $row = Database::fetch(
        'SELECT `id`, `setting_value` FROM `settings` WHERE `setting_key` = :k',
        ['k' => $key]
    );
    $have = $row === null ? null : (string) $row['setting_value'];

    if ($have === $want) {
        $skipped[] = $key . ' (already set)';
        continue;
    }

    // A value the owner has already changed to something else is not ours to
    // overwrite silently - it is said out loud and then applied, because this
    // seed carries what they just told us.
    if ($have !== null && $have !== '' && !str_contains($have, '98765')
        && !str_contains($have, 'shopinnkart.com') && $have !== 'ShopInnKart') {
        $notes[] = $key . ': replacing "' . mb_substr($have, 0, 40) . '"';
    }

    printf("  %-26s %s\n", $key, mb_substr($want, 0, 50));

    if (!$dry) {
        if ($row === null) {
            Database::insert('settings', [
                'setting_group' => str_starts_with($key, 'invoice_') || $key === 'gst_number' ? 'invoice' : 'store',
                'setting_key'   => $key,
                'setting_value' => $want,
                'setting_type'  => 'text',
                'label'         => ucfirst(str_replace('_', ' ', $key)),
            ]);
        } else {
            Database::update('settings', ['setting_value' => $want], '`id` = :id', ['id' => (int) $row['id']]);
        }
    }
    $applied[] = $key;
}

// --- 2. categories ---------------------------------------------------------

echo "\nCategories\n";

foreach (STORE_CATEGORIES as $spec) {
    $existing = Database::fetch(
        'SELECT `id`, `name`, `slug` FROM `categories` WHERE `slug` = :s LIMIT 1',
        ['s' => $spec['slug']]
    );
    if ($existing !== null) {
        $skipped[] = 'category ' . $spec['slug'] . ' (already there)';
        printf("  %-26s already there\n", $spec['slug']);
        continue;
    }

    if (isset($spec['from'])) {
        $old = Database::fetch(
            'SELECT `id`, `name`, `slug` FROM `categories` WHERE `slug` = :s LIMIT 1',
            ['s' => $spec['from']]
        );
        if ($old !== null) {
            // The catalogue is a tree and this one is a PARENT: its products
            // sit in its children. Counting direct products reported 0 and
            // made renaming the whole catalogue look like renaming an empty
            // shelf - which is exactly the reassuring kind of wrong.
            $kids = array_map('intval', Database::fetchColumnAll(
                'SELECT `id` FROM `categories` WHERE `parent_id` = :p', ['p' => (int) $old['id']]));
            $ids  = array_merge([(int) $old['id']], $kids);
            $count = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM `products` WHERE `category_id` IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            );
            printf("  %-26s renamed from \"%s\" (%d sub-categor%s, %d product(s) kept)\n",
                $spec['slug'], $old['name'], count($kids), count($kids) === 1 ? 'y' : 'ies', $count);

            if (!$dry) {
                Database::update('categories',
                    ['name' => $spec['name'], 'slug' => $spec['slug']],
                    '`id` = :id', ['id' => (int) $old['id']]);
                // Direct write, so the redirect the admin screen would have
                // recorded is recorded here instead. Without it every link to
                // the old URL - and everything Google has indexed - 404s.
                if (function_exists('seo_slug_redirect')) {
                    seo_slug_redirect('category', (string) $old['slug'], $spec['slug'], true);
                }
            }
            $applied[] = 'category ' . $spec['slug'];
            continue;
        }
        $notes[] = 'category "' . $spec['from'] . '" not found; created "' . $spec['slug'] . '" fresh';
    }

    printf("  %-26s new, empty\n", $spec['slug']);
    if (!$dry) {
        Database::insert('categories', [
            'name'      => $spec['name'],
            'slug'      => $spec['slug'],
            'status'    => 'active',
            'parent_id' => null,
        ]);
    }
    $applied[] = 'category ' . $spec['slug'];
}

// ---------------------------------------------------------------------------

if (!$dry && $applied !== []) {
    if (function_exists('cache_bust')) {
        cache_bust();
    }
}

echo "\n", str_repeat('-', 66), "\n";
printf("%d change(s)%s, %d already correct\n",
    count($applied), $dry ? ' would apply' : ' applied', count($skipped));

foreach ($notes as $n) {
    echo "  note  ", $n, "\n";
}

echo "\nNOT SET BY THIS SEED, and why:\n";
echo "  The bank account. Nothing in this store reads settlement details - a\n";
echo "  payment gateway settles to the account configured in its own\n";
echo "  dashboard. Recording it here would look configured and do nothing.\n";
echo "  If you want customers to see it so they can pay by NEFT, put it in\n";
echo "  Settings > Invoice > Terms, where it prints on every invoice.\n";

echo "\nWORTH CHECKING NOW:\n";
echo "  - Settings > Email: mail_from_email is still no-reply@shopinnkart.com.\n";
echo "    Mail must be sent from a domain you control, or it fails SPF.\n";
echo "  - The three new categories are empty. Products stay where they are\n";
echo "    until you move them in Catalogue > Products.\n";
echo "  - The logo still says ShopInnKart. Appearance > Customization.\n";

exit(0);
