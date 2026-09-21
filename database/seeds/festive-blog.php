<?php
/**
 * ShopInnKart - Replace the electronics blog with lighting content.
 *
 * All six published posts were written for the seeded electronics store:
 * smartphone and laptop buying guides, a noise-cancelling headphone
 * comparison, a Sony WH-1000XM5 long-term review, a mesh Wi-Fi setup walkthrough
 * and a Wi-Fi 7 / USB4 explainer. Three are is_featured = 1, so they render on
 * the homepage blog rail as well as blog.php and blog-post.php. The category
 * "Tech Explained" and two category descriptions were equally stale.
 *
 * A NOTE ON WHAT IS *NOT* WRITTEN HERE
 * -----------------------------------
 * The original post #4 was a first-person long-term review ("Six Months With
 * the Sony WH-1000XM5"). It is not replaced with an equivalent - no
 * "Two Diwalis With the MIRADH Curtain" - because the store has not run that
 * test, and a shop inventing lived experience is the same fabrication problem
 * as an invented customer testimonial. It becomes a comparison of products the
 * store actually lists, which is a claim it can stand behind.
 *
 * Every specification mentioned below is generic to the category, not asserted
 * of a particular SKU, so nothing here can contradict a product page.
 *
 *     php database/seeds/festive-blog.php          apply
 *     php database/seeds/festive-blog.php --dry    report only
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

/** id => [category_id, is_featured, title, slug, excerpt, content, meta_title, meta_description, focus_keyword] */
function festive_blog_plan(): array
{
    return [
        1 => [
            1, 1,
            'How to Choose Festive String Lights: Length, LED Count and Modes',
            'how-to-choose-festive-string-lights',
            'Length, LED density, warm white versus multicolour, and how many metres a room actually needs — the four numbers that decide whether a strand looks right.',
            '<p>Most string light listings lead with a mode count. Modes are the least important number on the box. Four other things decide whether a strand looks the way you pictured it.</p>'
            . '<h2>Length, measured against the wall</h2>'
            . '<p>Measure the run before you order. A 5 metre strand around a standard door frame is generous; the same 5 metres across a 3 metre wall reads as sparse unless you drape it in swags. For a full wall backdrop, plan on the wall width multiplied by the number of vertical drops you want.</p>'
            . '<h2>LED count tells you density, not brightness</h2>'
            . '<p>Two 5 metre strands with 50 and 100 LEDs put out different looks, not different amounts of light. The 100 LED version reads as a continuous line; the 50 LED one reads as distinct points. Points photograph better against a plain wall. A continuous line suits an outline — a doorway, a railing, a headboard.</p>'
            . '<h2>Warm white, cool white and multicolour</h2>'
            . '<p>Warm white sits around 2700K and flatters skin and wood. Cool white is closer to 6000K and reads clinical indoors, though it cuts through daylight better on a balcony. Multicolour is festive but hard to photograph — the colour shifts between exposures. If you are lighting a space for photographs, warm white is the safer default.</p>'
            . '<h2>Power source</h2>'
            . '<p>USB strands run from a power bank, which matters if the nearest socket is across the room. Mains strands are brighter and do not need recharging. Battery strands are the most flexible and the shortest-lived — plan on replacing cells during a long festival.</p>'
            . '<h2>Then look at modes</h2>'
            . '<p>Eight modes is standard. In practice most people use two: steady on, and a slow fade. A remote is worth more than the mode count, because it saves reaching behind furniture for the controller.</p>',
            'How to Choose Festive String Lights | ShopInnKart',
            'Length, LED count, colour temperature and power source — the four numbers that decide whether a string light looks right in your room.',
            'string lights',
        ],
        2 => [
            1, 1,
            'Curtain Lights vs Fairy Lights: Which Suits Your Room',
            'curtain-lights-vs-fairy-lights',
            'One makes a backdrop, the other makes an outline. Picking the wrong one is the most common festive lighting mistake.',
            '<p>They are sold side by side and they do different jobs. A curtain light fills a plane; a fairy light traces a line. Almost every disappointing setup is one used where the other belonged.</p>'
            . '<h2>Curtain lights fill a wall</h2>'
            . '<p>A curtain is a horizontal wire carrying vertical drops. It is designed to be seen as a field of light — behind a bed, across a photo corner, over a window. Sizing is width by drop: a 3 metre by 3 metre curtain covers a standard bedroom wall. Hang it too narrow and the drops bunch; too wide and you see the gaps.</p>'
            . '<h2>Fairy lights trace an edge</h2>'
            . '<p>A single strand follows something: a banister, a door frame, the rim of a shelf, the inside of a jar. On a bare wall a fairy light has nothing to describe and looks like a stray cable. Give it an edge and it does the work of a much larger installation.</p>'
            . '<h2>Star and snowflake curtains are a third thing</h2>'
            . '<p>These carry moulded shapes at intervals rather than bare points. The shapes read clearly from across a room and disappear in a close-up photograph, which is the opposite of how bare LEDs behave. They suit a window seen from outside, or a wall seen from the far side of a room.</p>'
            . '<h2>Controllers</h2>'
            . '<p>A curtain has more LEDs, so the controller matters more. A remote is close to essential on a curtain mounted above head height. On a short fairy strand within arm\'s reach, an inline button is fine.</p>'
            . '<h2>If you only buy one</h2>'
            . '<p>For a first purchase, a curtain does more visible work. A fairy strand is the better second buy, because by then you know which edges in the room want tracing.</p>',
            'Curtain Lights vs Fairy Lights | ShopInnKart',
            'A curtain light fills a wall, a fairy light traces an edge. How to pick the right one for your room, with sizing guidance for both.',
            'curtain lights',
        ],
        3 => [
            1, 0,
            'LED Diyas or Wax Diyas? How to Decide',
            'led-diyas-or-wax-diyas',
            'Safety around children and pets, reusability, and the one situation where a real flame still wins.',
            '<p>This is not a question with one answer. Both belong in a Diwali setup, and the choice is usually about where in the house a particular diya sits.</p>'
            . '<h2>Where LED wins</h2>'
            . '<p>Anywhere a flame is a risk or a nuisance: a low shelf a toddler can reach, a balcony rail in wind, a corridor where a dupatta passes, near curtains, or in a home with a cat. LED diyas also survive being knocked over, which is the failure mode that actually happens.</p>'
            . '<h2>Where a flame wins</h2>'
            . '<p>The puja thali. For many families the lit wick is the point of the ritual, not a lighting effect, and no LED substitutes for it. Use real diyas where the ceremony calls for them and LED everywhere else — that is what most households end up doing.</p>'
            . '<h2>Reusability</h2>'
            . '<p>An LED diya lasts several festivals. The failure point is the button cell, not the LED, so store them with the cells removed. A cell left in over a year can leak and corrode the contacts, which is what usually kills a set that seemed fine when packed away.</p>'
            . '<h2>Colour</h2>'
            . '<p>Look for a warm yellow flicker rather than a steady white. A flicker that varies slightly in brightness reads as a flame from a few feet away; a perfectly regular pulse does not. Cool white LED diyas look like appliances.</p>'
            . '<h2>Disposal</h2>'
            . '<p>Button cells do not belong in household waste. Indian cities have battery collection points — use them when a set finally dies.</p>',
            'LED Diyas or Wax Diyas? | ShopInnKart',
            'Safety around children and pets, reusability across festivals, and the one place a real flame still belongs.',
            'led diyas',
        ],
        4 => [
            2, 1,
            'Decorative Table Lamps and Projectors Compared: Which Fits Your Space',
            'table-lamps-and-projectors-compared',
            'A crystal table lamp and a galaxy projector do very different things to a room. What each is actually for.',
            '<p>Both are sold as "mood lighting" and they are not interchangeable. One is an object you look at; the other disappears and changes the surfaces around it.</p>'
            . '<h2>A crystal or diamond table lamp is an object</h2>'
            . '<p>It sits on a surface and is meant to be seen — the facets throw small points of colour onto the immediate area, but the lamp itself is the decoration. That means placement is about sightlines: a side table, a console, a shelf at eye level. On the floor or behind furniture it stops doing anything.</p>'
            . '<p>Look for touch or remote control if it will live on a bedside table, and check whether it is USB rechargeable — a lamp with a trailing mains cable limits where it can go.</p>'
            . '<h2>A galaxy projector is an effect</h2>'
            . '<p>It projects onto walls and ceiling, so the room does the work and the unit should be inconspicuous. It needs throw distance and a reasonably plain surface: a textured or dark ceiling absorbs the effect. Projectors are the better choice for a child\'s room or a party wall, and the worse choice for a room you want to read in.</p>'
            . '<h2>Ambient light is the deciding factor</h2>'
            . '<p>A projector only reads in a dim room. If the space has streetlight through thin curtains, the effect washes out and you will stop using it. A table lamp works at any ambient level because it is a light source rather than a projected image.</p>'
            . '<h2>Buying for a gift</h2>'
            . '<p>A table lamp is the safer gift: it works in any room, at any time of day, without the recipient having to darken the space.</p>',
            'Table Lamps vs Galaxy Projectors | ShopInnKart',
            'A crystal table lamp is an object you look at; a galaxy projector changes the room around it. How to choose between them.',
            'decorative table lamp',
        ],
        5 => [
            3, 0,
            'How to Hang a Curtain Light Backdrop Without Damaging the Wall',
            'hang-curtain-lights-without-damaging-wall',
            'Adhesive hooks, tension methods and planning around the socket — how to put up a light wall in a rented flat and take it down cleanly.',
            '<p>The lights are the easy part. Getting them onto a wall you are not allowed to drill, and off it again without taking the paint, is where most of the effort goes.</p>'
            . '<h2>Plan from the socket outward</h2>'
            . '<p>Decide where the plug goes before anything else. The controller usually sits 20 to 30 cm from the plug end, and it needs to be reachable if there is no remote. Running the strand the wrong way and discovering the cable is a metre short is the most common setup mistake.</p>'
            . '<h2>Adhesive hooks, applied properly</h2>'
            . '<p>Removable adhesive hooks hold a curtain light easily — the whole assembly weighs very little. What matters is preparation: wipe the wall, let it dry fully, press the hook for thirty seconds, then wait an hour before hanging anything. Most failures are hooks loaded immediately after sticking.</p>'
            . '<p>On a distempered or limewashed wall no adhesive will hold, and pulling one off takes a patch of finish with it. Use a tension rod across a window recess, or hang from an existing curtain rail instead.</p>'
            . '<h2>Spacing the top wire</h2>'
            . '<p>Hooks every 50 to 60 cm along the top wire keep the drops vertical. Fewer than that and the wire sags between them, which makes the drops splay outward and the whole backdrop look uneven — visible immediately in photographs even when it looks fine to the eye.</p>'
            . '<h2>Removal</h2>'
            . '<p>Pull adhesive strips slowly, straight down along the wall rather than outward. Outward is what lifts paint.</p>'
            . '<h2>Do not run strands under rugs or through doorways</h2>'
            . '<p>Compression damages the cable and a doorway crossing becomes a trip hazard with a live cable in it. Route along the skirting and use a socket on the same wall wherever possible.</p>',
            'How to Hang Curtain Lights Without Damaging Walls | ShopInnKart',
            'Adhesive hooks, tension rods and hook spacing — how to put up a light backdrop in a rented flat and remove it cleanly.',
            'hang curtain lights',
        ],
        6 => [
            4, 0,
            'SMD, Rice LED and IP Ratings: Lighting Jargon Decoded',
            'lighting-jargon-decoded',
            'What the abbreviations on a fairy light box actually mean for brightness, colour and whether it survives a balcony.',
            '<p>Lighting listings carry a handful of terms that are rarely explained. Here is what each one changes about the product in your hand.</p>'
            . '<h2>Rice LED vs SMD LED</h2>'
            . '<p>Rice LEDs are the small bullet-shaped bulbs moulded into the wire — cheap, warm, and directional, meaning they look brightest viewed end-on. SMD LEDs are flat chips mounted on the strand; they spread light more evenly and are usually brighter for the same power. A rice strand sparkles, an SMD strand glows. For a backdrop seen from one angle, SMD is more even. For a strand wound through a jar or a plant, rice reads better.</p>'
            . '<h2>Copper wire vs PVC wire</h2>'
            . '<p>Copper strands are hair-thin and bendable, so they hold a shape when wrapped — good in jars, around stems, along a frame. PVC strands are thicker and spring back to straight, which is what you want for a long run that should hang cleanly.</p>'
            . '<h2>IP ratings</h2>'
            . '<p>Two digits: solids, then water. IP20 means no water protection at all — indoors only. IP44 survives splashing from any direction, which covers a sheltered balcony but not direct monsoon rain. IP65 handles low-pressure jets and is the first rating genuinely suitable for exposed outdoor use. If a listing does not state a rating, assume indoor.</p>'
            . '<h2>Colour temperature in Kelvin</h2>'
            . '<p>Lower is warmer. 2700K is the yellow of an incandescent bulb; 4000K is neutral; 6000K is the blue-white of daylight. Festive lighting is almost always 2700K to 3000K. A strand described only as "white" without a number is usually cool white.</p>'
            . '<h2>Modes</h2>'
            . '<p>The standard eight are combination, in-wave, sequential, slo-glo, chasing/flash, slow fade, twinkle/flash and steady on. They are the same eight on nearly every controller, so the count is not a differentiator.</p>',
            'Lighting Jargon Decoded: SMD, Rice LED, IP Ratings | ShopInnKart',
            'What SMD, rice LED, copper wire, IP ratings and Kelvin values actually mean for the lights you are buying.',
            'led lighting terms',
        ],
    ];
}

/** id => [name, slug, description] */
function festive_blog_categories(): array
{
    return [
        1 => ['Buying Guides',      'buying-guides',      'Practical guides that help you match the right lights to your room and the occasion.'],
        2 => ['Comparisons',        'comparisons',        'Two products side by side, and which one suits which space.'],
        3 => ['How To',             'how-to',             'Hanging, powering and styling walkthroughs for festive lighting.'],
        4 => ['Lighting Explained', 'lighting-explained', 'Jargon decoded, so a fairy light box stops being a wall of numbers.'],
    ];
}

function festive_blog_run(bool $dryRun = false): array
{
    $report = ['posts' => [], 'categories' => [], 'missing' => []];

    foreach (festive_blog_categories() as $id => [$name, $slug, $description]) {
        $row = Database::fetch('SELECT `id`, `name` FROM `blog_categories` WHERE `id` = :id', ['id' => $id]);
        if (!$row) {
            $report['missing'][] = 'category ' . $id;
            continue;
        }
        $report['categories'][] = $row['name'] . ' -> ' . $name;
        if (!$dryRun) {
            Database::update('blog_categories', [
                'name'        => $name,
                'slug'        => $slug,
                'description' => $description,
            ], '`id` = :id', ['id' => $id]);
        }
    }

    foreach (festive_blog_plan() as $id => $p) {
        [$categoryId, $featured, $title, $slug, $excerpt, $content, $metaTitle, $metaDescription, $keyword] = $p;

        $row = Database::fetch('SELECT `id`, `title` FROM `blog_posts` WHERE `id` = :id', ['id' => $id]);
        if (!$row) {
            $report['missing'][] = 'post ' . $id;
            continue;
        }

        $report['posts'][] = ['id' => $id, 'from' => (string) $row['title'], 'to' => $title];

        if (!$dryRun) {
            Database::update('blog_posts', [
                'category_id'      => $categoryId,
                'is_featured'      => $featured,
                'title'            => $title,
                'slug'             => $slug,
                'excerpt'          => $excerpt,
                'content'          => $content,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDescription,
                'focus_keyword'    => $keyword,
                // The seeded rows carry electronics OG copy too; clearing lets
                // the renderer fall back to the title/description above rather
                // than serving a stale social card.
                'og_title'         => null,
                'og_description'   => null,
            ], '`id` = :id', ['id' => $id]);
        }
    }

    if (!$dryRun) {
        if (function_exists('admin_after_write')) {
            admin_after_write();
        } elseif (function_exists('cache_bust')) {
            cache_bust();
        }
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $result = festive_blog_run(in_array('--dry', $argv, true));

    foreach ($result['categories'] as $c) {
        echo '  category  ', $c, PHP_EOL;
    }
    echo PHP_EOL;
    foreach ($result['posts'] as $p) {
        printf('  post #%d%s      %s%s   -> %s%s', $p['id'], PHP_EOL, $p['from'], PHP_EOL, $p['to'], PHP_EOL);
    }
    foreach ($result['missing'] as $m) {
        echo '  MISSING: ', $m, PHP_EOL;
    }
    printf('%sPosts rewritten: %d   Categories: %d%s',
        PHP_EOL, count($result['posts']), count($result['categories']), PHP_EOL);
    exit(0);
}
