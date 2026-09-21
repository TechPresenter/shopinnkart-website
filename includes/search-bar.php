<?php
/**
 * ShopInnKart - Search bar component.
 *
 * The single search field used everywhere: header, mobile drawer, search page,
 * 404. Rendering it from one place is what guarantees the microphone, the
 * suggestions panel and the keyboard behaviour are identical on every surface.
 *
 * Usage:
 *     search_bar(['variant' => 'header', 'id' => 'sikSearchInput']);
 *     search_bar(['variant' => 'page', 'value' => $query, 'autofocus' => true]);
 *
 * Options:
 *   variant   header | page | drawer   visual size
 *   id        unique input id (required when more than one is on a page)
 *   value     prefilled query
 *   placeholder
 *   autofocus
 *   suggestions  false to render the field without the live dropdown
 */

declare(strict_types=1);

function search_bar(array $options = []): void
{
    static $instance = 0;
    $instance++;

    $variant     = $options['variant'] ?? 'header';
    $id          = $options['id'] ?? 'sikSearch' . $instance;
    $panelId     = $id . 'Panel';
    $value       = (string) ($options['value'] ?? ($_GET['q'] ?? ''));
    $autofocus   = !empty($options['autofocus']);
    $suggestions = $options['suggestions'] ?? true;
    $placeholder = $options['placeholder'] ?? 'Search for phones, laptops, headphones…';

    // The mic is a progressive enhancement: the button renders for everyone and
    // voice-search.js removes it when the browser has no Speech Recognition,
    // so a form still works without JavaScript at all.
    $voice = $options['voice'] ?? true;

    // The shortcut row under the field. Only the header asks for it: on
    // /search the results are already the answer, and in the drawer there is
    // no room. popular_searches() lives in header-actions.php, which a page
    // rendering a bare search field may not have loaded.
    $popular = !empty($options['popular']) && function_exists('popular_searches')
        ? popular_searches(6)
        : [];
    ?>
    <div class="sik-search sik-search--<?= e_attr($variant) ?>" data-search>
        <form action="<?= e(url('search.php')) ?>" method="get" role="search"
              aria-label="Search products" autocomplete="off">
            <div class="sik-search__field">
                <span class="sik-search__leading" aria-hidden="true"><?= icon('search') ?></span>

                <label for="<?= e_attr($id) ?>" class="sik-sr">Search products</label>
                <input type="search"
                       id="<?= e_attr($id) ?>"
                       name="q"
                       class="sik-search__input"
                       placeholder="<?= e_attr($placeholder) ?>"
                       value="<?= e($value) ?>"
                       autocomplete="off"
                       autocapitalize="off"
                       spellcheck="false"
                       enterkeyhint="search"
                       <?= $autofocus ? 'autofocus' : '' ?>
                       <?= $suggestions ? 'data-search-input' : '' ?>
                       <?= $suggestions ? 'aria-controls="' . e_attr($panelId) . '"' : '' ?>>

                <button type="button" class="sik-search__clear" data-search-clear-input
                        aria-label="Clear search" tabindex="-1" hidden>
                    <?= icon('close', 'w-4 h-4') ?>
                </button>

                <?php if ($voice): ?>
                    <button type="button" class="sik-search__voice" data-voice-search
                            data-voice-target="#<?= e_attr($id) ?>"
                            aria-label="Search by voice"
                            title="Search by voice">
                        <span class="sik-search__voice-idle"><?= icon('mic') ?></span>
                        <span class="sik-search__voice-live" aria-hidden="true">
                            <span class="sik-wave"><i></i><i></i><i></i><i></i></span>
                        </span>
                    </button>
                <?php endif; ?>

                <button type="submit" class="sik-search__submit" aria-label="Search">
                    <span class="sik-search__submit-label">Search</span>
                    <?= icon('search') ?>
                </button>
            </div>
        </form>

        <?php if ($popular !== []): ?>
            <div class="sik-search__popular">
                <span class="sik-search__popular-label">Popular</span>
                <?php foreach ($popular as $popularItem): ?>
                    <a href="<?= e($popularItem['url']) ?>"><?= e($popularItem['label']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($suggestions): ?>
            <?php /* Not role="listbox": the panel renders links and headings, not
                     options.

                     The panel itself is deliberately NOT a live region. It used
                     to be, and because search.js replaces its entire innerHTML
                     on every keystroke, a screen reader re-read the whole
                     suggestion list — eight products with price, MRP and
                     discount, plus categories and brands — on each character,
                     interrupting itself each time. The count goes to the
                     dedicated status element below instead. */ ?>
            <div class="sik-suggest" id="<?= e_attr($panelId) ?>" data-search-results
                 aria-label="Search suggestions"></div>
            <p class="sik-sr" data-search-status role="status" aria-live="polite"></p>
        <?php endif; ?>

        <?php if ($voice): ?>
            <!-- Live region: what the microphone is doing, announced to screen readers. -->
            <p class="sik-search__voice-status" data-voice-status role="status" aria-live="polite" hidden></p>
        <?php endif; ?>
    </div>
    <?php
}
