<?php
/**
 * ShopInnKart - Take the phone-and-laptop framing out of the policy pages.
 *
 * The thirteen CMS pages were written for an electronics catalogue. An earlier
 * pass rewrote the About page and the returns-by-category table, but the rest
 * still told a shopper buying a ₹249 curtain light to factory-reset the device,
 * remove the Find My lock, bring the IMEI sticker and collect a job sheet from a
 * brand service centre - none of which exists for a string of LEDs.
 *
 * Every edit here is a sentence-level swap on the live text. Commercial terms -
 * return windows, fees, who pays for pickup, refund timelines - are untouched;
 * only the product framing changes, and clauses that cannot apply to lighting
 * (data backup, activation locks, firmware, hygiene seals on earbuds) are
 * removed rather than reworded. The wording is still a policy change the store
 * owner must read and ratify.
 *
 * Ratify it before the next install or export, not only before this script is
 * run on the live store. database/schema.sql already seeds the same pages with
 * every edit below applied, so a fresh install and a patched live store read
 * the same text - which also means install.php and `mysql < schema.sql` ship
 * the rewritten wording with no review step in front of it. A fresh install
 * therefore does not need this seed at all; it is only the patch for a store
 * that is already carrying the old electronics copy.
 *
 * Idempotent: an edit whose old text is gone (already applied, or edited by
 * hand since) is skipped and reported, never re-applied.
 *
 *     php database/seeds/festive-policy-copy.php          apply
 *     php database/seeds/festive-policy-copy.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/**
 * Page slug => column => list of [old, new] pairs. An empty "new" removes the
 * old text outright; list items are removed together with their trailing
 * newline so the markup stays tidy.
 */
function festive_policy_page_edits(): array
{
    return [
        'privacy-policy' => [
            'content' => [
                ['<li><strong>Brand authorised service centres</strong> receive your invoice and device details only when you ask us to open a warranty case on your behalf.</li>',
                 '<li><strong>Brands</strong> receive your invoice and product details only when you ask us to open a warranty claim on your behalf.</li>'],
            ],
        ],

        'terms-conditions' => [
            'content' => [
                ['currently {{tax_rate}} per cent for most electronics.</li>',
                 'currently {{tax_rate}} per cent for most lighting products.</li>'],
                ['Keep it, because a service centre will ask for it as proof of purchase date.',
                 'Keep it, because a warranty claim needs it as proof of the purchase date.'],
                ['- manufacturer warranty, dead on arrival and service centres.</li>',
                 '- warranty cover, dead on arrival and how to raise a claim.</li>'],
                // No data lives on a string light; the exclusion has nothing to exclude.
                ["<li>Loss of data on a device you sent for service or returned to us. Back up your data first and remove any account lock.</li>\n", ''],
                ['including brand and service centre pages.', 'including brand pages.'],
            ],
        ],

        'shipping-policy' => [
            'content' => [
                ['<li>Small electronics travel in a right sized corrugated box with air cushioning, never in a loose envelope.</li>',
                 '<li>String lights, diyas and small lamps travel in a right sized corrugated box with air cushioning, never in a loose envelope.</li>'],
            ],
        ],

        'return-policy' => [
            'content' => [
                ['<li>Complete: charger, cable, adapter, remote, stylus, manual, warranty card, freebies and promotional items all included.</li>',
                 '<li>Complete: adapter, controller, remote, cable, batteries, manual, warranty card, freebies and promotional items all included.</li>'],
                ['<li>In the original retail box, with the barcode, IMEI or serial label intact and unpeeled.</li>',
                 '<li>In the original retail box, with the barcode or batch label intact and unpeeled.</li>'],
                // Activation locks and personal data are phone and laptop conditions.
                ["<li>Reset to factory settings, with Find My, Google account, Mi account or any similar activation lock removed. A locked device cannot be accepted or refunded.</li>\n", ''],
                ["<li>Free of personal data. Back up anything you need before you hand the device over, because we cannot recover it afterwards.</li>\n", ''],
                ['<li>Items missing the box, a serial label, an accessory or a bundled free item.</li>',
                 '<li>Items missing the box, a barcode label, an accessory or a bundled free item.</li>'],
                ["<li>Sealed audio products where the hygiene seal has been broken, unless the unit is defective.</li>\n", ''],
                ['which usually means a repair or a brand authorised replacement at a service centre.',
                 'which usually means a repair or a replacement arranged with the brand.'],
                ['where the serial number, completeness and condition are verified.',
                 'where the product, completeness and condition are verified.'],
                ['If you want to dispose of an old device rather than return a new one,',
                 'If you want to dispose of old lights or adapters rather than return a new one,'],
                ['The original retail box with its serial and barcode label is part of the product for a return, and a service centre also needs it for some brands.',
                 'The original retail box with its barcode label is part of the product for a return, and some brands also need it for a warranty claim.'],
                ["<h3>My phone is still linked to my account. What happens?</h3>\n<p>Quality check will fail the return and the unit will be sent back to you. Remove the activation lock and factory reset the device before handover.</p>\n", ''],
            ],
        ],

        'warranty-policy' => [
            'content' => [
                ['<p>Everything sold here is authorised India stock, which means the warranty is provided by the brand and honoured by its authorised service network anywhere in India.',
                 '<p>The warranty on each product is the one stated on its product page, and it is provided by the brand.'],
                ['which is why the invoice is the document a service centre asks for first.',
                 'which is why the invoice is the document a brand asks for first.'],
                ['We do not repair devices ourselves', 'We do not repair products ourselves'],
                ['What we do is make sure your paperwork is correct, that the unit is registered for India service, and that a genuine defect',
                 'What we do is make sure your paperwork is correct and that a genuine defect'],
                ['<td>We help you open the case and locate a service centre</td><td>Repair or replacement of the defective part</td>',
                 '<td>We help you open the claim with the brand</td><td>Repair or replacement of the defective product</td>'],
                ['<tr><td>Where the repair happens</td><td>Not at our premises</td><td>At a brand authorised service centre</td></tr>',
                 '<tr><td>Where the claim is handled</td><td>Not at our premises</td><td>By the brand, usually as a replacement</td></tr>'],
                ['Accessories bundled in the box usually carry a shorter period than the main device, and a battery or an adapter is frequently six months where the device itself is twelve.',
                 'Accessories bundled in the box, such as an adapter, a controller or a remote, can carry a shorter period than the light itself.'],
                ['<li>Free repair or replacement of the defective part at an authorised service centre.</li>',
                 '<li>Free repair or replacement of the defective product or part, as the brand decides.</li>'],
                ['<li>Battery capacity falling below the threshold the brand defines, where the brand offers that cover.</li>',
                 '<li>A built-in rechargeable battery that stops holding a charge, where the brand offers that cover.</li>'],
                ['<li>Physical damage, cracked screens, bent frames and dents.</li>',
                 '<li>Physical damage, such as crushed or cut wires, broken bulbs, cracked glass or crystal, and dents.</li>'],
                ['<li>Liquid damage, including on devices with a water resistance rating, because that rating is a design specification and not a warranty.</li>',
                 '<li>Liquid damage, including on lights with a water resistance (IP) rating, because that rating is a design specification and not a warranty.</li>'],
                ['<li>Damage from voltage fluctuation, a non certified charger, or repair by anyone other than an authorised centre.</li>',
                 '<li>Damage from voltage fluctuation, an adapter other than the one supplied, or repair by anyone other than the brand.</li>'],
                ['<li>Normal wear such as scratches, faded printing and battery ageing inside the rated cycle count.</li>',
                 '<li>Normal wear such as scratches, faded colours and battery ageing.</li>'],
                ["<li>Software problems caused by rooting, jailbreaking, unlocking the bootloader or installing unofficial firmware.</li>\n", ''],
                ['<li>Consumables such as ear tips, cables, printer ink and stylus nibs.</li>',
                 '<li>Consumables such as replaceable batteries, fuses and hooks.</li>'],
                ["<li>Loss of data. Back it up before any service visit.</li>\n", ''],
                ['<li><strong>Prepare the device.</strong> Back up your data and remove every account lock, such as Find My or a linked Google account. A service centre cannot accept a locked device.</li>',
                 '<li><strong>Record the fault.</strong> A short video or a few photographs of the fault, with the adapter and controller that came in the box, lets the brand decide the claim without a site visit.</li>'],
                ['<li><strong>Open the case.</strong> Contact the brand authorised service centre directly, or raise a ticket with us and we will locate the nearest authorised centre for your pin code and give you a documented case reference.</li>',
                 '<li><strong>Open the claim.</strong> Contact the brand directly using the details on the warranty card, or raise a ticket with us and we will pass it to the brand and give you a documented case reference.</li>'],
                ['<li><strong>Collect the job sheet.</strong> The job sheet is your record of the deposit, the reported fault and the promised turnaround. Do not leave without it.</li>',
                 '<li><strong>Keep the claim number.</strong> The claim or ticket number from the brand is your record of the reported fault and the promised turnaround.</li>'],
                ['<li><strong>Track it.</strong> If the service centre exceeds the turnaround it stated, come back to us with the job sheet number and we will follow it up with the brand.</li>',
                 '<li><strong>Track it.</strong> If the brand exceeds the turnaround it stated, come back to us with the claim number and we will follow it up with the brand.</li>'],
                ['for the remainder of the device warranty', 'for the remainder of the product warranty'],
                ['<p>When a device cannot be repaired economically, do not put it in household waste. Electronics contain recoverable metals and lithium cells that are a fire risk in a landfill.',
                 '<p>When a light, an adapter or a rechargeable lamp reaches the end of its life, do not put it in household waste. LED strings and adapters contain recoverable metals, and rechargeable lamps carry lithium cells that are a fire risk in a landfill.'],
                ['and we can route an end of life device to an authorised recycler.',
                 'and we can route end of life lights to an authorised recycler.'],
                ['we cannot overrule the technical assessment of an authorised service centre. Where you believe an assessment is wrong, ask for it in writing on the job sheet and escalate it with us; that written assessment is what makes an escalation possible.',
                 'we cannot overrule the brand\'s assessment of a claim. Where you believe an assessment is wrong, ask the brand for it in writing and escalate it with us; that written assessment is what makes an escalation possible.'],
                ['<h3>My screen cracked in the first week. Is it covered?</h3>',
                 '<h3>The crystal on my lamp cracked in the first week. Is it covered?</h3>'],
                ["<h3>The device is water resistant but it stopped after a spill.</h3>\n<p>Water resistance ratings are tested in laboratory conditions and are not a warranty against liquid ingress. Such damage is a chargeable repair.</p>",
                 "<h3>The light is rated for outdoor use but it stopped after heavy rain.</h3>\n<p>Water resistance ratings are tested in laboratory conditions and are not a warranty against water getting in. Such damage is not covered.</p>"],
                ["<h3>Where is my nearest service centre?</h3>\n<p>Raise a ticket with the model and your pin code and we will send you the authorised centre details. Brand websites also publish centre locators.</p>",
                 "<h3>How do I reach the brand?</h3>\n<p>The brand contact details are on the warranty card in the box. If the card is missing, raise a ticket with the product name and we will send them to you.</p>"],
            ],
            'meta_description' => [
                ['how to raise a claim at a brand service centre, and e-waste disposal.',
                 'how to raise a claim with the brand, and e-waste disposal.'],
            ],
        ],

        'faq' => [
            'content' => [
                ['<p>Every product is authorised India stock with a manufacturer warranty serviced by the brand across the country.',
                 '<p>Every product carries the warranty stated on its product page.'],
                ['we will replace or refund it rather than sending you to a service centre.',
                 'we will replace or refund it rather than sending you to the brand.'],
            ],
        ],

        'payment-policy' => [
            'content' => [
                ['currently {{tax_rate}} per cent for most electronics categories.</li>',
                 'currently {{tax_rate}} per cent for most lighting products.</li>'],
                ['it is the document a service centre uses to establish the warranty start date.',
                 'it is the document a warranty claim uses to establish the purchase date.'],
            ],
        ],

        'delivery-policy' => [
            'content' => [
                ['<li>On an open box delivery, do the model, colour, storage variant and serial number match the invoice?</li>',
                 '<li>On an open box delivery, do the product, colour and pack size match the invoice?</li>'],
                ['<h2>10. Delivery Of Old Devices And E-Waste</h2>', '<h2>10. Old Lights And E-Waste</h2>'],
                ['If you want to hand over an old device for recycling,', 'If you want to hand over old lights or adapters for recycling,'],
                ['End of life electronics are routed to an authorised recycler', 'End of life lights and adapters are routed to an authorised recycler'],
                // Section 7 already says nothing is installed; the FAQ contradicted it.
                ["<h3>Do you install the lights?</h3>\n<p>A brand authorised technician, arranged after delivery. The delivery agent only hands over the carton.</p>",
                 "<h3>Do you install the lights?</h3>\n<p>No. Everything in the catalogue is supplied ready to plug in, as section 7 explains. The delivery agent only hands over the carton.</p>"],
            ],
        ],
    ];
}

/** FAQ question (exact) => list of [old, new] pairs applied to the answer. */
function festive_policy_faq_edits(): array
{
    return [
        'What is your return window?' => [
            ['the original brand box with its serial number sticker intact.', 'the original brand box with its barcode label intact.'],
        ],
        // Mirrors section 4 of the Return Policy, which already lists these.
        'Which products cannot be returned?' => [
            ['Sealed in-ear earphones and earbuds cannot be returned once the seal is broken, unless the unit is defective. We also cannot accept activated software licences, used consumables such as printer ink, products damaged after delivery, items marked final sale, and devices still locked to an account such as Find My or a Google account.',
             'Batteries supplied with diyas and tea lights cannot be returned once opened, unless they are faulty on first use. We also cannot accept products damaged after delivery, lights fitted or wired into place where the fault is cosmetic, and items marked final sale.'],
        ],
    ];
}

/**
 * Apply [old, new] pairs to $text. "already" = the new text is present and the
 * old is gone; "missing" = neither is there (the page was edited by hand), which
 * is reported rather than guessed at.
 */
function festive_policy_apply(string $text, array $pairs, array &$tally, string $where): string
{
    foreach ($pairs as [$old, $new]) {
        if ($old !== '' && str_contains($text, $old)) {
            $text = str_replace($old, $new, $text);
            $tally['applied']++;
        } elseif ($new === '' || str_contains($text, $new)) {
            $tally['already']++;
        } else {
            $tally['missing'][] = $where . ': ' . mb_substr($old, 0, 70) . '…';
        }
    }
    return $text;
}

function festive_policy_run(bool $dryRun = false): array
{
    $tally = ['applied' => 0, 'already' => 0, 'missing' => [], 'rows' => 0];

    foreach (festive_policy_page_edits() as $slug => $columns) {
        $page = Database::fetch('SELECT `id`, `content`, `meta_description` FROM `pages` WHERE `slug` = :s LIMIT 1', ['s' => $slug]);
        if (!$page) {
            $tally['missing'][] = "page $slug not found";
            continue;
        }

        $changes = [];
        foreach ($columns as $column => $pairs) {
            $before = (string) $page[$column];
            $after = festive_policy_apply($before, $pairs, $tally, $slug . '.' . $column);
            if ($after !== $before) {
                $changes[$column] = $after;
            }
        }

        if ($changes !== [] && !$dryRun) {
            Database::update('pages', $changes, '`id` = :id', ['id' => (int) $page['id']]);
        }
        $tally['rows'] += $changes !== [] ? 1 : 0;
    }

    foreach (festive_policy_faq_edits() as $question => $pairs) {
        $faq = Database::fetch('SELECT `id`, `answer` FROM `faqs` WHERE `question` = :q LIMIT 1', ['q' => $question]);
        if (!$faq) {
            $tally['missing'][] = "faq \"$question\" not found";
            continue;
        }

        $after = festive_policy_apply((string) $faq['answer'], $pairs, $tally, 'faq ' . $faq['id']);
        if ($after !== $faq['answer']) {
            if (!$dryRun) {
                Database::update('faqs', ['answer' => $after], '`id` = :id', ['id' => (int) $faq['id']]);
            }
            $tally['rows']++;
        }
    }

    // Pages and FAQs are read through the cache; a stale copy would keep
    // serving the old wording until it expired.
    if (!$dryRun) {
        if (function_exists('admin_after_write')) {
            admin_after_write();
        } elseif (function_exists('cache_bust')) {
            cache_bust();
        }
    }

    return $tally;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dry = in_array('--dry', $argv, true);
    $result = festive_policy_run($dry);

    printf('%sEdits applied   : %d%sAlready applied : %d%sRows changed    : %d%s',
        $dry ? '(dry run - nothing written)' . PHP_EOL : '',
        $result['applied'], PHP_EOL, $result['already'], PHP_EOL, $result['rows'], PHP_EOL);
    foreach ($result['missing'] as $miss) {
        echo '  ! not found, left alone: ', $miss, PHP_EOL;
    }
    exit(0);
}
