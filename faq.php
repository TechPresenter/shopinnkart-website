<?php
/**
 * ShopInnKart - Frequently Asked Questions.
 *
 * Questions come from the `faqs` table. The `faq` CMS row supplies the banner
 * and meta only; its long-form copy stays on /page/faq so the two do not
 * repeat each other.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once INCLUDES_PATH . '/content-functions.php';

$page       = cms_page('faq');
$categories = faq_categories();

$term     = trim((string) input('q', ''));
$category = trim((string) input('category', ''));
if ($category !== '' && !in_array($category, $categories, true)) {
    $category = '';
}

$faqs   = faq_list($category, $term);
$groups = faq_group_by_category($faqs);

if ($page !== null) {
    cms_page_seo($page);
} else {
    seo_set([
        'title'       => 'Frequently Asked Questions',
        'description' => 'Answers on ordering, delivery, payments, returns and warranty support.',
        'canonical'   => url('faq'),
    ]);
}
// A filtered view is a slice of the same page, so keep one canonical URL.
seo_set(['canonical' => url('faq')]);
if ($faqs !== []) {
    seo_add_schema(seo_faq_schema($faqs));
}

require INCLUDES_PATH . '/header.php';

echo cms_page_banner($page ?? [
    'title'        => 'Frequently Asked Questions',
    'banner_image' => null,
    'updated_at'   => null,
], [
    'subtitle' => 'Ordering, delivery, payments, returns and warranty - answered in one place.',
    'updated'  => false,
]);
?>

<div class="sik-container sik-section sik-section--sm">
    <div style="max-width:820px;margin-inline:auto">

        <form method="get" action="<?= e(url('faq.php')) ?>" role="search" style="margin-bottom:var(--sp-5)">
            <?php if ($category !== ''): ?>
                <input type="hidden" name="category" value="<?= e($category) ?>">
            <?php endif; ?>
            <div class="sik-search__field">
                <label class="sik-sr" for="faqSearch">Search the FAQs</label>
                <input class="sik-search__input" id="faqSearch" type="search" name="q"
                       placeholder="Search for a question&hellip;" autocomplete="off"
                       value="<?= e($term) ?>" data-faq-search>
                <button class="sik-search__submit" type="submit" aria-label="Search FAQs">
                    <?= icon('search', 'w-4 h-4') ?>
                </button>
            </div>
        </form>

        <?php if ($categories !== []): ?>
            <div class="sik-pills" style="margin-bottom:var(--sp-5)">
                <a class="sik-pill<?= $category === '' ? ' is-active' : '' ?>"
                   href="<?= e(url_with(['category' => null, 'page' => null])) ?>">All topics</a>
                <?php foreach ($categories as $name): ?>
                    <a class="sik-pill<?= $category === $name ? ' is-active' : '' ?>"
                       href="<?= e(url_with(['category' => $name, 'page' => null])) ?>"><?= e($name) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($term !== '' || $category !== ''): ?>
            <p style="font-size:13px;color:var(--sik-muted);margin-bottom:var(--sp-4)">
                <span data-faq-count><?= count($faqs) ?></span> question<?= count($faqs) === 1 ? '' : 's' ?>
                <?php if ($term !== ''): ?> matching &ldquo;<?= e($term) ?>&rdquo;<?php endif; ?>
                <?php if ($category !== ''): ?> in <?= e($category) ?><?php endif; ?>
                &middot; <a href="<?= e(url('faq.php')) ?>" style="color:var(--sik-primary-ink)">Show everything</a>
            </p>
        <?php endif; ?>

        <?php if ($faqs === []): ?>
            <div class="sik-empty">
                <?= icon('search', 'w-12 h-12') ?>
                <h2 class="sik-empty__title">
                    <?= $term !== '' || $category !== '' ? 'No questions match that' : 'No questions published yet' ?>
                </h2>
                <p class="sik-empty__text">
                    <?php if ($term !== '' || $category !== ''): ?>
                        Try a shorter search, pick another topic, or ask us directly.
                    <?php else: ?>
                        Add questions in the admin FAQ manager and they will be grouped here automatically.
                    <?php endif; ?>
                </p>
                <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                    <a class="sik-btn sik-btn--primary" href="<?= e(url('contact.php')) ?>">Ask our team</a>
                    <?php if ($term !== '' || $category !== ''): ?>
                        <a class="sik-btn sik-btn--outline" href="<?= e(url('faq.php')) ?>">Show all questions</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div data-acc-single data-faq-groups>
                <?php foreach ($groups as $groupName => $items): ?>
                    <section data-faq-group style="margin-bottom:var(--sp-7)">
                        <h2 style="font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;
                                   color:var(--sik-navy);margin-bottom:var(--sp-3)">
                            <?= e($groupName) ?>
                        </h2>
                        <?php foreach ($items as $faq): ?>
                            <?php $bodyId = 'faqAnswer' . (int) $faq['id']; ?>
                            <div class="sik-acc__item" data-acc-item data-faq-item>
                                <button type="button" class="sik-acc__head" data-acc-toggle
                                        aria-expanded="false" aria-controls="<?= e($bodyId) ?>">
                                    <span><?= e($faq['question']) ?></span>
                                    <?= icon('chevron-down', 'w-4 h-4') ?>
                                </button>
                                <div class="sik-acc__body" id="<?= e($bodyId) ?>">
                                    <?= nl2br(e($faq['answer']), false) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="sik-empty" data-faq-none hidden style="padding-block:var(--sp-7)">
                <?= icon('search', 'w-12 h-12') ?>
                <h2 class="sik-empty__title">Nothing matches that yet</h2>
                <p class="sik-empty__text">Clear the search box to see every question, or send us the question
                    and we will answer it.</p>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('contact.php')) ?>">Ask our team</a>
            </div>
        <?php endif; ?>

        <div style="margin-top:var(--sp-7);border:1px solid var(--sik-border);border-radius:var(--sik-radius);
                    padding:var(--sp-6);text-align:center;background:var(--sik-soft)">
            <h2 style="font-size:16px;font-weight:700">Still stuck?</h2>
            <p style="font-size:13.5px;color:var(--sik-muted);margin:var(--sp-2) 0 var(--sp-4);line-height:1.6">
                Our support team answers order, delivery and warranty questions within one business day.
            </p>
            <div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap">
                <a class="sik-btn sik-btn--primary" href="<?= e(url('contact.php')) ?>">
                    <?= icon('headset', 'w-4 h-4') ?> Contact support
                </a>
                <a class="sik-btn sik-btn--outline" href="<?= e(url('track-order.php')) ?>">Track an order</a>
            </div>
        </div>
    </div>
</div>

<script>
    /* Live filtering over the already-rendered questions. The form still
       submits without JavaScript, so this only removes a round trip. */
    (function () {
        var input = document.querySelector('[data-faq-search]');
        var wrap  = document.querySelector('[data-faq-groups]');
        if (!input || !wrap) return;

        var items  = Array.prototype.slice.call(wrap.querySelectorAll('[data-faq-item]'));
        var groups = Array.prototype.slice.call(wrap.querySelectorAll('[data-faq-group]'));
        var none   = document.querySelector('[data-faq-none]');
        var count  = document.querySelector('[data-faq-count]');

        function apply() {
            var term = input.value.trim().toLowerCase();
            var shown = 0;

            items.forEach(function (item) {
                var match = term === '' || item.textContent.toLowerCase().indexOf(term) !== -1;
                item.hidden = !match;
                if (match) shown++;
            });
            groups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-faq-item]:not([hidden])');
            });

            if (none) none.hidden = shown !== 0;
            if (count) count.textContent = String(shown);
        }

        input.addEventListener('input', apply);
    })();
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
