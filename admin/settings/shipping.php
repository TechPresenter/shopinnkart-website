<?php
/**
 * ShopInnKart Admin - Shipping.
 *
 * Four things an admin treats as one job: the global shipping/COD rules in
 * the settings table, how long the courier API log is kept, the
 * shipping_methods rows checkout offers, and the pincode serviceability list
 * the delivery estimator reads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('settings.view');

require_once ADMIN_PATH . '/settings/_layout.php';

const PINCODE_IMPORT_MAX_ROWS = 20000;
const PINCODE_CSV_COLUMNS = ['pincode', 'city', 'state', 'is_serviceable', 'cod_available', 'delivery_days'];

$spec = [
    'free_shipping_enabled' => [
        // "Off charges the method cost on every order" is only the label read
        // backwards, so it is no longer printed under the switch.
        'type' => 'bool', 'label' => 'Enable free shipping',
    ],
    'free_shipping_threshold' => [
        'type' => 'number', 'label' => 'Free shipping above', 'required' => true,
        'min_value' => 0, 'max_value' => 1000000, 'step' => '0.01',
    ],
    'default_shipping_cost' => [
        'type' => 'number', 'label' => 'Default shipping cost', 'required' => true,
        'min_value' => 0, 'max_value' => 100000, 'step' => '0.01',
        'help' => 'Only when no shipping method is active.',
    ],
    'default_delivery_days' => [
        'type' => 'number', 'label' => 'Default delivery days', 'required' => true,
        'min_value' => 1, 'max_value' => 60,
        'help' => 'Used when a PIN code has no entry.',
    ],
    'cod_enabled' => [
        'type' => 'bool', 'label' => 'Enable Cash on Delivery',
        'help' => 'Off removes COD even if the payment row is active.',
    ],
    'cod_charge' => [
        'type' => 'number', 'label' => 'COD handling fee', 'required' => true,
        'min_value' => 0, 'max_value' => 100000, 'step' => '0.01',
        // The fee charged is the one on the COD row, and policy copy quotes
        // that row too - so this field only matters where there is no active
        // COD row to read. Saying so beats two numbers that silently disagree.
        'help' => 'Fallback only. The fee charged is on Settings > Payment.',
    ],
    'cod_max_amount' => [
        'type' => 'number', 'label' => 'Max order value for COD', 'required' => true,
        'min_value' => 0, 'max_value' => 10000000, 'step' => '0.01',
        // The COD row on Settings > Payment carries its own max order value
        // and the lower of the two wins. That interaction is in the card's
        // "When COD is refused anyway" details, not under the field.
        'help' => 'Above this, prepaid only. 0 removes the cap.',
    ],
    // The counterpart of email_log_retention_days on Settings > Email. The
    // floor is enforced again in shipping_log_retention_days(): a courier
    // dispute is argued from these rows, so neither a blank field nor a direct
    // edit of the settings table may take it below a week.
    'shipping_log_retention_days' => [
        'type' => 'number', 'label' => 'Keep courier API log for (days)', 'required' => true,
        'min_value' => 7, 'max_value' => 3650, 'default' => '30',
        'help' => 'Never below 7 days: disputes are argued from these rows.',
    ],

    // --- Automatic courier selection ---------------------------------------
    // Relative weights, not percentages: they are normalised when they are
    // read, so 40/25/25/10 and 4/2.5/2.5/1 choose the same courier. Setting
    // them all to zero is allowed and falls back to the defaults, because the
    // alternative is a screen that scores every courier NaN.
    'shipping_select_weight_cost' => [
        'type' => 'number', 'label' => 'Weight: cost', 'required' => true,
        'min_value' => 0, 'max_value' => 100, 'default' => '40',
    ],
    'shipping_select_weight_speed' => [
        'type' => 'number', 'label' => 'Weight: delivery speed', 'required' => true,
        'min_value' => 0, 'max_value' => 100, 'default' => '25',
    ],
    'shipping_select_weight_reliability' => [
        'type' => 'number', 'label' => 'Weight: courier performance', 'required' => true,
        'min_value' => 0, 'max_value' => 100, 'default' => '25',
        'help' => 'Delivered against returned, from your own shipments.',
    ],
    'shipping_select_weight_rating' => [
        'type' => 'number', 'label' => 'Weight: courier rating', 'required' => true,
        'min_value' => 0, 'max_value' => 100, 'default' => '10',
        'help' => "The courier's own published rating.",
    ],
    'shipping_select_window_days' => [
        'type' => 'number', 'label' => 'Performance window (days)', 'required' => true,
        'min_value' => 7, 'max_value' => 730, 'default' => '90',
        'help' => 'Shorter reacts faster and is noisier.',
    ],
    'shipping_select_min_shipments' => [
        'type' => 'number', 'label' => 'Parcels needed before judging a courier', 'required' => true,
        'min_value' => 1, 'max_value' => 10000, 'default' => '20',
        // Without a floor, three deliveries out of three would read as a
        // perfect courier. Below it the screens say "not enough history".
        'help' => 'Below this, performance scores neutral.',
    ],
    'shipping_auto_book_enabled' => [
        'type' => 'bool', 'label' => 'Ship confirmed orders automatically',
        // What is always left for a human - unpaid prepaid orders, blocked
        // orders, PIN codes nobody serves, COD above the ceiling - is in the
        // card's details, with every refusal in the activity log.
        'help' => 'On, a confirmed order books a courier with nobody watching.',
    ],
    'shipping_auto_book_cod_max' => [
        'type' => 'number', 'label' => 'Auto-ship COD ceiling', 'required' => true,
        'min_value' => 0, 'max_value' => 10000000, 'step' => '0.01', 'default' => '10000',
        // Unattended COD is goods handed over against a promise of cash,
        // which is why this ceiling is separate from cod_max_amount.
        'help' => 'Above this, never booked automatically. 0 removes the ceiling.',
    ],
    'shipping_auto_book_max_age_days' => [
        'type' => 'number', 'label' => 'Auto-ship only orders newer than (days)', 'required' => true,
        'min_value' => 0, 'max_value' => 3650, 'default' => '7',
        // This guards the FIRST pass. The switch above ships off, so by the
        // time it is turned on there is usually a tail of old confirmed
        // orders that were settled by hand, written off, or are waiting on
        // stock. Without this the first cron pass books a courier for every
        // one of them, oldest first, with nobody watching. Older orders stay
        // bookable by hand.
        'help' => 'Guards the first pass. 0 lets it book your whole backlog.',
    ],
];

$action = (string) input('action', '');

/** List URL that keeps the current pincode search and page. */
function shipping_list_url(array $overrides = []): string
{
    $query = array_diff_key($_GET, array_flip(['action', 'id', 'template']));
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $qs = http_build_query($query);
    return settings_url('shipping') . ($qs !== '' ? '?' . $qs : '');
}

// ---------------------------------------------------------------------------
//  CSV template
// ---------------------------------------------------------------------------
if (($_GET['template'] ?? '') === '1') {
    admin_require('settings.edit');
    stream_csv('pincode-import-template.csv', PINCODE_CSV_COLUMNS, [
        ['560001', 'Bengaluru', 'Karnataka', '1', '1', '2'],
        ['796001', 'Aizawl', 'Mizoram', '0', '0', '9'],
    ]);
}

// ---------------------------------------------------------------------------
//  Shipping + COD settings
// ---------------------------------------------------------------------------
if (is_post() && $action === 'settings') {
    settings_handle_save('shipping', 'shipping', $spec, settings_group('shipping'));
}

// ---------------------------------------------------------------------------
//  Shipping methods
// ---------------------------------------------------------------------------
if (is_post() && $action === 'method_save') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $existing = $id > 0
        ? Database::fetch('SELECT * FROM `shipping_methods` WHERE `id` = :id', ['id' => $id])
        : null;

    $code = mb_strtolower(trim((string) input('code', '')));
    $name = trim((string) input('name', ''));
    $minDays = max(1, input_int('min_days', 1));
    $maxDays = max(1, input_int('max_days', 1));
    $cost = trim((string) input('cost', '0'));
    $freeAbove = trim((string) input('free_above', ''));

    $problem = '';
    if ($id > 0 && $existing === null) {
        $problem = 'That shipping method no longer exists.';
    } elseif (preg_match('/^[a-z0-9_-]{2,40}$/', $code) !== 1) {
        $problem = 'Code may only contain lower-case letters, numbers, hyphens and underscores.';
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $problem = 'Name is required and must not exceed 100 characters.';
    } elseif (!is_numeric($cost) || (float) $cost < 0) {
        $problem = 'Cost must be zero or more.';
    } elseif ($freeAbove !== '' && (!is_numeric($freeAbove) || (float) $freeAbove < 0)) {
        $problem = 'Free-above must be a positive amount, or blank.';
    } elseif ($maxDays < $minDays) {
        $problem = 'Maximum delivery days cannot be lower than the minimum.';
    } elseif (Database::exists('shipping_methods', '`code` = :c' . ($id > 0 ? ' AND `id` <> :id' : ''),
        $id > 0 ? ['c' => $code, 'id' => $id] : ['c' => $code])) {
        $problem = 'Another shipping method already uses the code "' . $code . '".';
    }

    if ($problem !== '') {
        flash('error', $problem);
        redirect(shipping_list_url());
    }

    $row = [
        'code'        => $code,
        'name'        => $name,
        'description' => trim((string) input('description', '')) ?: null,
        'cost'        => (float) $cost,
        'free_above'  => $freeAbove === '' ? null : (float) $freeAbove,
        'min_days'    => min(255, $minDays),
        'max_days'    => min(255, $maxDays),
        'sort_order'  => max(0, input_int('sort_order')),
        'status'      => in_array((string) input('status', 'active'), ['active', 'inactive'], true)
            ? (string) input('status', 'active') : 'inactive',
    ];

    if ($existing !== null) {
        Database::update('shipping_methods', $row, '`id` = :id', ['id' => $id]);
        log_activity('shipping_method.updated', 'shipping_method', $id, 'Updated shipping method "' . $name . '"');
        flash('success', $name . ' updated.');
    } else {
        $id = Database::insert('shipping_methods', $row);
        log_activity('shipping_method.created', 'shipping_method', $id, 'Created shipping method "' . $name . '"');
        flash('success', $name . ' added.');
    }

    admin_after_write();
    redirect(shipping_list_url());
}

if (is_post() && $action === 'method_delete') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $method = Database::fetch('SELECT * FROM `shipping_methods` WHERE `id` = :id', ['id' => $id]);

    if ($method === null) {
        flash('error', 'That shipping method no longer exists.');
        redirect(shipping_list_url());
    }

    $used = (int) Database::fetchColumn(
        'SELECT COUNT(*) FROM `orders` WHERE `shipping_method` = :c',
        ['c' => (string) $method['code']]
    );
    if ($used > 0) {
        flash('error', $method['name'] . ' is attached to ' . $used . ' order(s). Set it to inactive instead.');
        redirect(shipping_list_url());
    }

    Database::delete('shipping_methods', '`id` = :id', ['id' => $id]);
    log_activity('shipping_method.deleted', 'shipping_method', $id, 'Deleted shipping method "' . $method['name'] . '"');
    admin_after_write();

    flash('success', $method['name'] . ' deleted.');
    redirect(shipping_list_url());
}

// ---------------------------------------------------------------------------
//  Pincodes
// ---------------------------------------------------------------------------
if (is_post() && $action === 'pincode_save') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $pincode = trim((string) input('pincode', ''));
    $city = trim((string) input('city', ''));
    $state = trim((string) input('state', ''));
    $days = input_int('delivery_days', 4);

    $problem = '';
    if (preg_match('/^[1-9]\d{5}$/', $pincode) !== 1) {
        $problem = 'Enter a valid 6-digit PIN code.';
    } elseif ($city === '' || mb_strlen($city) > 100) {
        $problem = 'City is required and must not exceed 100 characters.';
    } elseif ($state === '' || mb_strlen($state) > 100) {
        $problem = 'State is required and must not exceed 100 characters.';
    } elseif ($days < 1 || $days > 60) {
        $problem = 'Delivery days must be between 1 and 60.';
    } elseif (Database::exists('pincodes', '`pincode` = :p' . ($id > 0 ? ' AND `id` <> :id' : ''),
        $id > 0 ? ['p' => $pincode, 'id' => $id] : ['p' => $pincode])) {
        $problem = 'PIN code ' . $pincode . ' is already in the list.';
    }

    if ($problem !== '') {
        flash('error', $problem);
        redirect(shipping_list_url());
    }

    $row = [
        'pincode'        => $pincode,
        'city'           => $city,
        'state'          => $state,
        'is_serviceable' => input_bool('is_serviceable') ? 1 : 0,
        'cod_available'  => input_bool('cod_available') ? 1 : 0,
        'delivery_days'  => $days,
    ];

    if ($id > 0 && Database::exists('pincodes', '`id` = :id', ['id' => $id])) {
        Database::update('pincodes', $row, '`id` = :id', ['id' => $id]);
        log_activity('pincode.updated', 'pincode', $id, 'Updated PIN code ' . $pincode);
        flash('success', 'PIN code ' . $pincode . ' updated.');
    } else {
        $id = Database::insert('pincodes', $row);
        log_activity('pincode.created', 'pincode', $id, 'Added PIN code ' . $pincode);
        flash('success', 'PIN code ' . $pincode . ' added.');
    }

    admin_after_write();
    redirect(shipping_list_url());
}

if (is_post() && $action === 'pincode_delete') {
    admin_require_action('settings.edit');

    $id = input_int('id');
    $pincode = Database::fetch('SELECT * FROM `pincodes` WHERE `id` = :id', ['id' => $id]);

    if ($pincode === null) {
        flash('error', 'That PIN code is no longer in the list.');
        redirect(shipping_list_url());
    }

    Database::delete('pincodes', '`id` = :id', ['id' => $id]);
    log_activity('pincode.deleted', 'pincode', $id, 'Deleted PIN code ' . $pincode['pincode']);
    admin_after_write();

    flash('success', 'PIN code ' . $pincode['pincode'] . ' removed.');
    redirect(shipping_list_url());
}

// ---------------------------------------------------------------------------
//  Pincode CSV import - validate the whole file, then write in one transaction
// ---------------------------------------------------------------------------
$importErrors = [];
$importFileError = '';
$importSummary = null;

if (is_post() && $action === 'pincode_import') {
    admin_require_action('settings.edit');

    $upload = $_FILES['csv'] ?? null;

    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $importFileError = 'Choose a CSV file to import.';
    } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $importFileError = 'The upload did not complete. Try a smaller file.';
    } elseif (!is_uploaded_file((string) $upload['tmp_name'])) {
        $importFileError = 'Invalid upload source.';
    } elseif ((int) $upload['size'] > MAX_UPLOAD_SIZE) {
        $importFileError = 'The file is larger than ' . (MAX_UPLOAD_SIZE / 1048576) . ' MB.';
    } elseif (!in_array(strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
        $importFileError = 'Only .csv files can be imported.';
    }

    $handle = $importFileError === '' ? fopen((string) $upload['tmp_name'], 'r') : false;
    if ($importFileError === '' && $handle === false) {
        $importFileError = 'The uploaded file could not be opened.';
    }

    $parsed = [];
    $index = [];

    if ($importFileError === '' && $handle !== false) {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            $importFileError = 'That file is empty.';
        } else {
            // Excel writes a UTF-8 BOM in front of the first header cell.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

            foreach ($header as $position => $name) {
                $key = strtolower(trim((string) $name));
                if (in_array($key, PINCODE_CSV_COLUMNS, true)) {
                    $index[$key] = $position;
                }
            }

            $missing = array_values(array_diff(['pincode', 'city', 'state'], array_keys($index)));
            if ($missing !== []) {
                $importFileError = 'These required columns are missing from the header row: ' . implode(', ', $missing) . '.';
            }
        }
    }

    if ($importFileError === '' && $handle !== false) {
        $cell = static function (array $row, array $index, string $column): string {
            if (!isset($index[$column]) || !isset($row[$index[$column]])) {
                return '';
            }
            return trim((string) $row[$index[$column]]);
        };
        $flag = static function (string $value, int $default): int {
            $value = strtolower(trim($value));
            if ($value === '') {
                return $default;
            }
            return in_array($value, ['1', 'y', 'yes', 'true', 'on'], true) ? 1 : 0;
        };

        $seen = [];
        $line = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            if ($data === [null] || trim(implode('', array_map('strval', $data))) === '') {
                continue;
            }
            if (count($parsed) >= PINCODE_IMPORT_MAX_ROWS) {
                $importFileError = 'That file has more than ' . PINCODE_IMPORT_MAX_ROWS . ' rows. Split it and import in batches.';
                break;
            }

            $pincode = $cell($data, $index, 'pincode');
            $city    = $cell($data, $index, 'city');
            $state   = $cell($data, $index, 'state');
            $days    = $cell($data, $index, 'delivery_days');
            $rowErrors = [];

            if (preg_match('/^[1-9]\d{5}$/', $pincode) !== 1) {
                $rowErrors[] = 'PIN code must be 6 digits and cannot start with 0.';
            } elseif (isset($seen[$pincode])) {
                $rowErrors[] = 'This PIN code appears earlier in the file (line ' . $seen[$pincode] . ').';
            }
            if ($city === '' || mb_strlen($city) > 100) {
                $rowErrors[] = 'City is required and must not exceed 100 characters.';
            }
            if ($state === '' || mb_strlen($state) > 100) {
                $rowErrors[] = 'State is required and must not exceed 100 characters.';
            }
            if ($days !== '' && (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1 || (int) $days > 60)) {
                $rowErrors[] = 'Delivery days must be a whole number between 1 and 60.';
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $importErrors[] = ['line' => $line, 'pincode' => $pincode, 'message' => $message];
                }
                continue;
            }

            $seen[$pincode] = $line;
            $parsed[] = [
                'pincode'        => $pincode,
                'city'           => $city,
                'state'          => $state,
                'is_serviceable' => $flag($cell($data, $index, 'is_serviceable'), 1),
                'cod_available'  => $flag($cell($data, $index, 'cod_available'), 1),
                'delivery_days'  => $days === '' ? setting_int('default_delivery_days', 4) : (int) $days,
            ];
        }
    }

    if ($handle !== false) {
        fclose($handle);
    }

    if ($importFileError === '' && $importErrors === [] && $parsed === []) {
        $importFileError = 'That file has a header row but no PIN code rows.';
    }

    if ($importFileError === '' && $importErrors === []) {
        $created = 0;
        $updated = 0;

        Database::transaction(static function () use ($parsed, &$created, &$updated): void {
            foreach ($parsed as $row) {
                $existingId = Database::fetchColumn(
                    'SELECT `id` FROM `pincodes` WHERE `pincode` = :p',
                    ['p' => $row['pincode']]
                );

                if ($existingId === null) {
                    Database::insert('pincodes', $row);
                    $created++;
                } else {
                    Database::update('pincodes', $row, '`id` = :id', ['id' => (int) $existingId]);
                    $updated++;
                }
            }
        });

        log_activity('pincode.imported', 'pincode', null,
            'PIN code CSV import: ' . $created . ' added, ' . $updated . ' updated');
        admin_after_write();

        $importSummary = ['created' => $created, 'updated' => $updated, 'total' => count($parsed)];
        flash('success', 'Import finished: ' . $created . ' added, ' . $updated . ' updated.');
    }
}

// ---------------------------------------------------------------------------
//  Read
// ---------------------------------------------------------------------------
$stored = settings_group('shipping');
$errors = errors_pull();
$values = settings_values($spec, $stored);

$methods = Database::fetchAll('SELECT * FROM `shipping_methods` ORDER BY `sort_order`, `id`');

$search = trim((string) ($_GET['q'] ?? ''));
$serviceFilter = admin_filter('service', ['all', 'yes', 'no'], 'all');
$sort = admin_safe_sort((string) ($_GET['sort'] ?? ''), ['pincode', 'city', 'state', 'delivery_days'], 'pincode');
$dir = admin_safe_dir((string) ($_GET['dir'] ?? 'asc'));

$where = ['1'];
$params = [];
if ($search !== '') {
    // Each placeholder is used once: with emulated prepares off PDO cannot
    // reuse a named parameter. The wildcards a user types are neutralised.
    $where[] = '(`pincode` LIKE :q1 OR `city` LIKE :q2 OR `state` LIKE :q3)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
if ($serviceFilter !== 'all') {
    $where[] = '`is_serviceable` = :serviceable';
    $params['serviceable'] = $serviceFilter === 'yes' ? 1 : 0;
}
$whereSql = implode(' AND ', $where);

$totalPincodes = (int) Database::fetchColumn("SELECT COUNT(*) FROM `pincodes` WHERE {$whereSql}", $params);
$page = max(1, (int) ($_GET['page'] ?? 1));
$pagination = paginate($totalPincodes, ADMIN_PER_PAGE, $page);

// PDO cannot bind LIMIT/OFFSET with emulated prepares off, so they are cast here.
$limit = (int) $pagination['per_page'];
$offset = (int) $pagination['offset'];

$pincodes = Database::fetchAll(
    "SELECT * FROM `pincodes` WHERE {$whereSql} ORDER BY `{$sort}` {$dir}, `pincode` ASC LIMIT {$limit} OFFSET {$offset}",
    $params
);

$serviceableCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM `pincodes` WHERE `is_serviceable` = 1');
$codCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM `pincodes` WHERE `is_serviceable` = 1 AND `cod_available` = 1');
$allPincodes = (int) Database::fetchColumn('SELECT COUNT(*) FROM `pincodes`');

$canEdit = admin_can('settings.edit');

$pageTitle    = 'Shipping Settings';
$pageSubtitle = 'Delivery rules, the methods checkout offers and where you actually ship.';
$breadcrumbs  = settings_breadcrumbs('shipping');

require ADMIN_PATH . '/includes/header.php';
?>

<?= settings_tabs('shipping') ?>

<div class="ad-grid ad-grid--4" style="margin-bottom:18px">
    <?= admin_stat_card('Shipping methods', (string) count($methods), 'truck', 'navy',
        Database::count('shipping_methods', "`status` = 'active'") . ' active') ?>
    <?= admin_stat_card('PIN codes on file', number_format($allPincodes), 'location', 'blue') ?>
    <?= admin_stat_card('Serviceable', number_format($serviceableCount), 'check-circle', 'green',
        number_format($allPincodes - $serviceableCount) . ' not served') ?>
    <?= admin_stat_card('COD available', number_format($codCount), 'wallet', 'primary',
        'Of the serviceable PIN codes') ?>
</div>

<!-- ========================= Shipping & COD rules ========================= -->
<form class="ad-form" method="post" action="<?= e(settings_url('shipping')) ?>" data-guard-unsaved>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">

    <div class="ad-grid ad-grid--2">
        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Delivery charges</div>
                    <div class="ad-card__sub">Fallbacks. A method with its own free-above value wins.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('free_shipping_enabled', $spec, $values, $errors) ?>
                <div class="ad-row ad-row--2">
                    <?= settings_field('free_shipping_threshold', $spec, $values, $errors) ?>
                    <?= settings_field('default_shipping_cost', $spec, $values, $errors) ?>
                </div>
                <?= settings_field('default_delivery_days', $spec, $values, $errors) ?>
            </div>
        </div>

        <div class="ad-card" style="margin:0">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Cash on Delivery</div>
                    <div class="ad-card__sub">Read by the COD gateway on every checkout attempt.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <?= settings_field('cod_enabled', $spec, $values, $errors) ?>
                <div class="ad-row ad-row--2">
                    <?= settings_field('cod_charge', $spec, $values, $errors) ?>
                    <?= settings_field('cod_max_amount', $spec, $values, $errors) ?>
                </div>
                <details>
                    <summary>When COD is refused anyway</summary>
                    <p>
                        The delivery PIN code has COD switched off below; a product in the cart is
                        marked "no COD"; or the max order value on the COD row in
                        Settings &rsaquo; Payment is lower than the cap above &mdash; the lower wins.
                    </p>
                </details>
            </div>
        </div>

        <?php // Full width under both cards: eight fields do not fit a half. ?>
        <div class="ad-card" style="margin:0;grid-column:1/-1">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Automatic courier selection</div>
                    <div class="ad-card__sub">Which courier the store picks, and why.</div>
                </div>
                <a class="ad-btn ad-btn--sm" href="<?= e(admin_url('shipping/rates.php')) ?>">
                    <?= icon('truck', 'w-4 h-4') ?> Try it in the rate calculator
                </a>
            </div>
            <div class="ad-card__body">
                <div class="ad-grid ad-grid--4" style="gap:12px 16px">
                    <?= settings_field('shipping_select_weight_cost', $spec, $values, $errors) ?>
                    <?= settings_field('shipping_select_weight_speed', $spec, $values, $errors) ?>
                    <?= settings_field('shipping_select_weight_reliability', $spec, $values, $errors) ?>
                    <?= settings_field('shipping_select_weight_rating', $spec, $values, $errors) ?>
                </div>
                <div class="ad-row ad-row--2">
                    <?= settings_field('shipping_select_window_days', $spec, $values, $errors) ?>
                    <?= settings_field('shipping_select_min_shipments', $spec, $values, $errors) ?>
                </div>
                <hr style="border:0;border-top:1px solid var(--ad-border);margin:18px 0">
                <?= settings_field('shipping_auto_book_enabled', $spec, $values, $errors) ?>
                <div class="ad-row ad-row--2">
                    <?= settings_field('shipping_auto_book_cod_max', $spec, $values, $errors) ?>
                    <?= settings_field('shipping_auto_book_max_age_days', $spec, $values, $errors) ?>
                </div>
                <?php /* A callout, not a muted paragraph. This is the one line on the card
                         that says the switch above it does nothing on its own, and as
                         12.5px muted prose it rendered as the faintest text in the card,
                         wrapping under the field help above it as though it belonged to
                         that input. A box at the card's own body size is the rank it
                         needs; section 48 of admin.css ranks a callout above help. */ ?>
                <div class="sik-alert sik-alert--info" style="margin:12px 0 0">
                    <?= icon('info', 'w-5 h-5') ?>
                    <div>Unattended booking does nothing until the shipment cron is running.</div>
                </div>
                <details>
                    <summary>How the scoring and unattended booking work</summary>
                    <p>
                        The four weights are relative, not percentages: 40/25/25/10 and 4/2.5/2.5/1
                        pick the same courier. A courier that cannot be judged on something &mdash; no
                        delivery estimate, no rating, not enough history &mdash; scores neutral there,
                        and the screen says so in words rather than guessing.
                    </p>
                    <p>
                        <code>bin/refresh-shipments.php</code> is what walks the queue. Nothing is ever
                        booked twice, and a booking the courier refuses is left for a human rather than
                        retried forever. Unpaid prepaid orders, blocked orders, PIN codes nobody serves
                        and COD above the ceiling are always left for a human; every refusal is in the
                        activity log. An order the sweep declines to touch stays bookable by hand on
                        its own shipping screen.
                    </p>
                </details>
            </div>
        </div>

        <div class="ad-card" style="margin:0;grid-column:1/-1">
            <div class="ad-card__head">
                <div>
                    <div class="ad-card__title">Courier API log</div>
                    <div class="ad-card__sub">Every courier call and webhook push, under Shipping &rsaquo; API log.</div>
                </div>
            </div>
            <div class="ad-card__body">
                <div class="ad-row ad-row--2">
                    <?= settings_field('shipping_log_retention_days', $spec, $values, $errors) ?>
                </div>
                <?php /* Same reason as the card above: this names an attack on the log,
                         so it cannot be the faintest line on the screen. */ ?>
                <div class="sik-alert sik-alert--warning" style="margin:12px 0 0">
                    <?= icon('alert', 'w-5 h-5') ?>
                    <div>The webhook URL is unauthenticated: anyone who finds it can add rows.</div>
                </div>
                <details>
                    <summary>How old rows are deleted</summary>
                    <p>
                        A courier cannot hold a login, which is why the webhook URL is open.
                        <code>bin/refresh-shipments.php</code> deletes what is past this age on every
                        run; without a cron, roughly one log write in 500 does a smaller pass instead.
                    </p>
                </details>
            </div>
            <?= settings_save_bar() ?>
        </div>
    </div>
</form>

<!-- ============================ Shipping methods ========================= -->
<div class="ad-card">
    <div class="ad-card__head">
        <div>
            <div class="ad-card__title">Shipping methods</div>
            <div class="ad-card__sub">What the shopper picks. The cheapest active method is the default.</div>
        </div>
        <?php if ($canEdit): ?>
            <button type="button" class="ad-btn ad-btn--primary ad-btn--sm"
                    data-modal-open="methodModal"
                    data-field-id="0" data-field-code="" data-field-name="" data-field-description=""
                    data-field-cost="0" data-field-free-above="" data-field-min-days="3"
                    data-field-max-days="7" data-field-sort-order="<?= count($methods) + 1 ?>"
                    data-field-status="active">
                <?= icon('plus', 'w-4 h-4') ?> Add Method
            </button>
        <?php endif; ?>
    </div>
    <div class="ad-card__body ad-card__body--flush">
        <?php if ($methods === []): ?>
            <?= admin_empty(
                'No shipping methods',
                'Checkout falls back to the default cost above until you add at least one method.',
                null, null, 'truck'
            ) ?>
        <?php else: ?>
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Code</th>
                            <th class="ad-table__num">Cost</th>
                            <th class="ad-table__num">Free above</th>
                            <th>Delivery</th>
                            <th class="ad-table__num">Order</th>
                            <th>Status</th>
                            <th class="ad-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($methods as $method): ?>
                            <tr>
                                <td>
                                    <div class="ad-cellflex__name"><?= e((string) $method['name']) ?></div>
                                    <div class="ad-cellflex__meta"><?= e(str_limit((string) $method['description'], 70)) ?></div>
                                </td>
                                <td class="ad-mono"><?= e((string) $method['code']) ?></td>
                                <td class="ad-table__num"><?= e(money((float) $method['cost'])) ?></td>
                                <td class="ad-table__num">
                                    <?= $method['free_above'] === null
                                        ? '<span class="ad-muted">Never</span>'
                                        : e(money((float) $method['free_above'])) ?>
                                </td>
                                <td><?= (int) $method['min_days'] ?>&ndash;<?= (int) $method['max_days'] ?> days</td>
                                <td class="ad-table__num"><?= (int) $method['sort_order'] ?></td>
                                <td><?= admin_state_badge((string) $method['status']) ?></td>
                                <td class="ad-table__actions">
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="ad-btn ad-btn--icon" title="Edit" aria-label="Edit"
                                                data-modal-open="methodModal"
                                                data-field-id="<?= (int) $method['id'] ?>"
                                                data-field-code="<?= e_attr((string) $method['code']) ?>"
                                                data-field-name="<?= e_attr((string) $method['name']) ?>"
                                                data-field-description="<?= e_attr((string) $method['description']) ?>"
                                                data-field-cost="<?= e_attr((string) $method['cost']) ?>"
                                                data-field-free-above="<?= e_attr($method['free_above'] === null ? '' : (string) $method['free_above']) ?>"
                                                data-field-min-days="<?= (int) $method['min_days'] ?>"
                                                data-field-max-days="<?= (int) $method['max_days'] ?>"
                                                data-field-sort-order="<?= (int) $method['sort_order'] ?>"
                                                data-field-status="<?= e_attr((string) $method['status']) ?>">
                                            <?= icon('edit', 'w-4 h-4') ?>
                                        </button>
                                        <?= admin_delete_form(
                                            shipping_list_url(['action' => 'method_delete']),
                                            (int) $method['id'],
                                            'Delete "' . $method['name'] . '"? This cannot be undone.'
                                        ) ?>
                                    <?php else: ?>
                                        <span class="ad-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================== PIN codes ============================== -->
<?php if ($importFileError !== ''): ?>
    <div class="sik-alert sik-alert--error">
        <?= icon('alert', 'w-5 h-5') ?>
        <div><strong>Nothing was imported.</strong> <?= e($importFileError) ?></div>
    </div>
<?php endif; ?>

<?php if ($importErrors !== []): ?>
    <div class="ad-card">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">Nothing was imported</div>
                <div class="ad-card__sub">
                    <?= count($importErrors) ?> problem(s). Nothing was written &mdash; fix the file and upload it again.
                </div>
            </div>
        </div>
        <div class="ad-card__body ad-card__body--flush">
            <div class="ad-tablewrap">
                <table class="ad-table">
                    <thead><tr><th class="ad-table__num">Line</th><th>PIN code</th><th>Problem</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($importErrors, 0, 200) as $rowError): ?>
                            <tr>
                                <td class="ad-table__num"><?= (int) $rowError['line'] ?></td>
                                <td class="ad-mono"><?= e($rowError['pincode'] !== '' ? $rowError['pincode'] : '—') ?></td>
                                <td><?= e($rowError['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($importSummary !== null): ?>
    <div class="ad-card">
        <div class="ad-card__body">
            <div class="ad-grid ad-grid--3">
                <?= admin_stat_card('Rows processed', number_format($importSummary['total']), 'list', 'navy') ?>
                <?= admin_stat_card('PIN codes added', number_format($importSummary['created']), 'plus', 'green') ?>
                <?= admin_stat_card('PIN codes updated', number_format($importSummary['updated']), 'refresh', 'blue') ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="ad-grid ad-grid--sidebar">
    <div class="ad-card" style="margin:0">
        <div class="ad-card__head">
            <div>
                <div class="ad-card__title">PIN code serviceability</div>
                <?php
                // A PIN code that is NOT on this list is accepted with the default delivery
                // estimate - the list blocks, it does not allow. Said in the subtitle because
                // assuming the opposite is how a store quietly refuses half its addresses.
                ?>
                <div class="ad-card__sub">Not serviceable blocks checkout. Unlisted PIN codes are accepted.</div>
            </div>
            <?php if ($canEdit): ?>
                <button type="button" class="ad-btn ad-btn--primary ad-btn--sm"
                        data-modal-open="pincodeModal"
                        data-field-id="0" data-field-pincode="" data-field-city="" data-field-state=""
                        data-field-delivery-days="<?= (int) setting_int('default_delivery_days', 4) ?>"
                        data-field-is-serviceable="1" data-field-cod-available="1">
                    <?= icon('plus', 'w-4 h-4') ?> Add PIN Code
                </button>
            <?php endif; ?>
        </div>

        <form class="ad-filters" method="get" action="<?= e(settings_url('shipping')) ?>">
            <div class="ad-search">
                <?= icon('search', 'w-4 h-4') ?>
                <input class="sik-input" type="search" name="q" value="<?= e($search) ?>"
                       placeholder="Search PIN code, city or state" data-filter-search>
            </div>
            <select class="sik-select" name="service" data-auto-submit aria-label="Serviceability">
                <?= admin_options(
                    ['all' => 'All PIN codes', 'yes' => 'Serviceable only', 'no' => 'Not serviceable'],
                    $serviceFilter
                ) ?>
            </select>
            <?php if ($search !== '' || $serviceFilter !== 'all'): ?>
                <a class="ad-btn ad-btn--sm" href="<?= e(settings_url('shipping')) ?>">Reset</a>
            <?php endif; ?>
        </form>

        <div class="ad-card__body ad-card__body--flush">
            <?php if ($pincodes === []): ?>
                <?= admin_empty(
                    $search !== '' || $serviceFilter !== 'all' ? 'No PIN codes match' : 'No PIN codes yet',
                    $search !== '' || $serviceFilter !== 'all'
                        ? 'Try a different search, or reset the filters.'
                        : 'Add PIN codes one at a time, or import a CSV to load them in bulk.',
                    null, null, 'location'
                ) ?>
            <?php else: ?>
                <div class="ad-tablewrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th><?= admin_sort_header('PIN code', 'pincode', $sort, strtolower($dir)) ?></th>
                                <th><?= admin_sort_header('City', 'city', $sort, strtolower($dir)) ?></th>
                                <th><?= admin_sort_header('State', 'state', $sort, strtolower($dir)) ?></th>
                                <th><?= admin_sort_header('Delivery', 'delivery_days', $sort, strtolower($dir)) ?></th>
                                <th>Serviceable</th>
                                <th>COD</th>
                                <th class="ad-table__actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pincodes as $row): ?>
                                <tr>
                                    <td class="ad-mono"><strong><?= e((string) $row['pincode']) ?></strong></td>
                                    <td><?= e((string) $row['city']) ?></td>
                                    <td class="ad-muted"><?= e((string) $row['state']) ?></td>
                                    <td><?= (int) $row['delivery_days'] ?> days</td>
                                    <td>
                                        <?= (int) $row['is_serviceable'] === 1
                                            ? '<span class="sik-status sik-status--green">Yes</span>'
                                            : '<span class="sik-status sik-status--red">No</span>' ?>
                                    </td>
                                    <td>
                                        <?= (int) $row['cod_available'] === 1
                                            ? '<span class="sik-status sik-status--green">Yes</span>'
                                            : '<span class="sik-status sik-status--gray">No</span>' ?>
                                    </td>
                                    <td class="ad-table__actions">
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="ad-btn ad-btn--icon" title="Edit" aria-label="Edit"
                                                    data-modal-open="pincodeModal"
                                                    data-field-id="<?= (int) $row['id'] ?>"
                                                    data-field-pincode="<?= e_attr((string) $row['pincode']) ?>"
                                                    data-field-city="<?= e_attr((string) $row['city']) ?>"
                                                    data-field-state="<?= e_attr((string) $row['state']) ?>"
                                                    data-field-delivery-days="<?= (int) $row['delivery_days'] ?>"
                                                    data-field-is-serviceable="<?= (int) $row['is_serviceable'] ?>"
                                                    data-field-cod-available="<?= (int) $row['cod_available'] ?>">
                                                <?= icon('edit', 'w-4 h-4') ?>
                                            </button>
                                            <?= admin_delete_form(
                                                shipping_list_url(['action' => 'pincode_delete']),
                                                (int) $row['id'],
                                                'Remove PIN code ' . $row['pincode'] . ' from the serviceable list?'
                                            ) ?>
                                        <?php else: ?>
                                            <span class="ad-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($pagination['last'] > 1): ?>
            <div class="ad-card__foot" style="justify-content:space-between">
                <span class="ad-muted" style="font-size:var(--ad-text-xs)">
                    Showing <?= (int) $pagination['from'] ?>&ndash;<?= (int) $pagination['to'] ?>
                    of <?= number_format((int) $pagination['total']) ?>
                </span>
                <?= admin_pagination($pagination, settings_url('shipping')) ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:grid;gap:18px;align-content:start">
        <?php if ($canEdit): ?>
            <div class="ad-card" style="margin:0">
                <div class="ad-card__head">
                    <div class="ad-card__title">Import PIN codes</div>
                    <a class="ad-btn ad-btn--sm" href="<?= e(settings_url('shipping') . '?template=1') ?>">
                        <?= icon('download', 'w-4 h-4') ?> Template
                    </a>
                </div>
                <form class="ad-card__body" method="post" enctype="multipart/form-data"
                      action="<?= e(settings_url('shipping')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pincode_import">

                    <div class="ad-field">
                        <span class="sik-label">CSV file</span>
                        <div class="ad-drop" data-drop="#pincodeCsvPreview">
                            <?= icon('upload', 'w-6 h-6') ?>
                            <div style="font-size:13px;margin-top:6px">Click or drop your .csv here</div>
                            <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
                        </div>
                        <div class="ad-preview" id="pincodeCsvPreview"></div>
                        <span class="sik-help">
                            Matched on <code>pincode</code>: an existing row is updated, not duplicated.
                        </span>
                    </div>

                    <button type="submit" class="ad-btn ad-btn--primary ad-btn--block">
                        <?= icon('upload', 'w-4 h-4') ?> Validate &amp; import
                    </button>

                    <details>
                        <summary>CSV format</summary>
                        <p>
                            Columns: <?= e(implode(', ', PINCODE_CSV_COLUMNS)) ?>.
                            <code>pincode</code>, <code>city</code> and <code>state</code> are required;
                            the flags accept 1/0 or yes/no and default to yes. Up to
                            <?= number_format(PINCODE_IMPORT_MAX_ROWS) ?> rows and
                            <?= e(format_bytes(MAX_UPLOAD_SIZE)) ?> per file.
                        </p>
                    </details>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
    <!-- Shipping method modal -->
    <div class="ad-modal" id="methodModal" role="dialog" aria-modal="true" aria-labelledby="methodModalTitle">
        <div class="ad-modal__backdrop"></div>
        <form class="ad-modal__panel" method="post" action="<?= e(settings_url('shipping')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="method_save">
            <input type="hidden" name="id" value="0">

            <div class="ad-modal__head">
                <div class="ad-card__title" id="methodModalTitle">Shipping method</div>
                <button type="button" class="ad-btn ad-btn--icon" data-modal-close aria-label="Close">
                    <?= icon('close', 'w-4 h-4') ?>
                </button>
            </div>

            <div class="ad-modal__body">
                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <label class="sik-label" for="smCode">Code <span class="req">*</span></label>
                        <input class="sik-input" type="text" id="smCode" name="code" maxlength="40" required
                               spellcheck="false" placeholder="express">
                        <span class="sik-help">Lower case. Orders store this value, so change it with care.</span>
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="smName">Name <span class="req">*</span></label>
                        <input class="sik-input" type="text" id="smName" name="name" maxlength="100" required
                               placeholder="Express Delivery">
                    </div>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="smDescription">Description</label>
                    <input class="sik-input" type="text" id="smDescription" name="description" maxlength="255"
                           placeholder="Shown under the method at checkout.">
                </div>

                <div class="ad-row ad-row--2">
                    <div class="ad-field">
                        <label class="sik-label" for="smCost">Cost</label>
                        <input class="sik-input" type="number" id="smCost" name="cost" min="0" step="0.01" value="0">
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="smFree">Free above</label>
                        <input class="sik-input" type="number" id="smFree" name="free_above" min="0" step="0.01"
                               placeholder="Blank = never free">
                    </div>
                </div>

                <div class="ad-row ad-row--3">
                    <div class="ad-field">
                        <label class="sik-label" for="smMin">Min days</label>
                        <input class="sik-input" type="number" id="smMin" name="min_days" min="1" max="255" value="3">
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="smMax">Max days</label>
                        <input class="sik-input" type="number" id="smMax" name="max_days" min="1" max="255" value="7">
                    </div>
                    <div class="ad-field">
                        <label class="sik-label" for="smSort">Sort order</label>
                        <input class="sik-input" type="number" id="smSort" name="sort_order" min="0" max="9999" value="0">
                    </div>
                </div>

                <div class="ad-field">
                    <label class="sik-label" for="smStatus">Status</label>
                    <select class="sik-select" id="smStatus" name="status">
                        <?= admin_options(['active' => 'Active', 'inactive' => 'Inactive'], 'active') ?>
                    </select>
                </div>
            </div>

            <div class="ad-modal__foot">
                <button type="button" class="ad-btn" data-modal-close>Cancel</button>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save Method</button>
            </div>
        </form>
    </div>

    <!-- PIN code modal -->
    <div class="ad-modal" id="pincodeModal" role="dialog" aria-modal="true" aria-labelledby="pincodeModalTitle">
        <div class="ad-modal__backdrop"></div>
        <form class="ad-modal__panel ad-modal__panel--sm" method="post" action="<?= e(settings_url('shipping')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pincode_save">
            <input type="hidden" name="id" value="0">

            <div class="ad-modal__head">
                <div class="ad-card__title" id="pincodeModalTitle">PIN code</div>
                <button type="button" class="ad-btn ad-btn--icon" data-modal-close aria-label="Close">
                    <?= icon('close', 'w-4 h-4') ?>
                </button>
            </div>

            <div class="ad-modal__body">
                <div class="ad-field">
                    <label class="sik-label" for="pcPincode">PIN code <span class="req">*</span></label>
                    <input class="sik-input" type="text" id="pcPincode" name="pincode" maxlength="6" required
                           inputmode="numeric" pattern="[1-9][0-9]{5}" placeholder="560001">
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="pcCity">City <span class="req">*</span></label>
                    <input class="sik-input" type="text" id="pcCity" name="city" maxlength="100" required>
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="pcState">State <span class="req">*</span></label>
                    <input class="sik-input" type="text" id="pcState" name="state" maxlength="100" required
                           list="pcStateList">
                    <datalist id="pcStateList">
                        <?php foreach (INDIAN_STATES as $state): ?>
                            <option value="<?= e_attr($state) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="ad-field">
                    <label class="sik-label" for="pcDays">Delivery days</label>
                    <input class="sik-input" type="number" id="pcDays" name="delivery_days" min="1" max="60"
                           value="<?= (int) setting_int('default_delivery_days', 4) ?>">
                </div>
                <div style="display:grid;gap:12px">
                    <label class="ad-switch">
                        <input type="checkbox" name="is_serviceable" value="1" checked>
                        <span class="ad-switch__track"></span>
                        <span>We deliver here</span>
                    </label>
                    <label class="ad-switch">
                        <input type="checkbox" name="cod_available" value="1" checked>
                        <span class="ad-switch__track"></span>
                        <span>Cash on Delivery available</span>
                    </label>
                </div>
            </div>

            <div class="ad-modal__foot">
                <button type="button" class="ad-btn" data-modal-close>Cancel</button>
                <button type="submit" class="ad-btn ad-btn--primary"><?= icon('check', 'w-4 h-4') ?> Save PIN Code</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require ADMIN_PATH . '/includes/footer.php'; ?>
