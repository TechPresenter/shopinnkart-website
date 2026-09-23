<?php
/**
 * ShopInnKart - Festive & decorative lighting catalogue.
 *
 * Eleven products sourced from the operator's own Amazon listings. Product
 * facts (LED counts, lengths, power source, modes, materials) are taken from
 * those listings; the descriptions and feature bullets below are written fresh
 * rather than copied, so the store is not publishing duplicate marketing copy.
 *
 * Idempotent: re-running updates the existing rows by SKU instead of inserting
 * a second copy, skips images that are already on disk, and leaves
 * `published_at` alone on a row that already has one - it is the date the
 * catalogue went live and "New arrivals" is sorted by it.
 *
 * There is no --dry here, unlike the other festive seeds: this one always
 * writes.
 *
 *     php database/seeds/festive-lighting-products.php            import / refresh
 *     php database/seeds/festive-lighting-products.php --images   also re-download images
 */

declare(strict_types=1);

if (!defined('SIK_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/includes/init.php';
}

// ---------------------------------------------------------------------------
//  Taxonomy
// ---------------------------------------------------------------------------

/** parent => [children]. Created only if absent. */
function festive_category_tree(): array
{
    return [
        'Festive & Decor Lighting' => [
            'description' => 'String lights, curtain lights, diyas and decorative lamps for Diwali, Christmas, weddings and everyday room decor.',
            'children' => [
                'String & Curtain Lights' => 'Hanging curtain lights, fairy lights and LED strings for windows, walls and backdrops.',
                'Diyas & LED Candles'     => 'Flameless, smokeless LED diyas and tea lights that are safe around children and pets.',
                'Lamps & Projectors'      => 'Night lamps, mood lighting and star projectors for bedrooms and nurseries.',
            ],
        ],
    ];
}

function festive_brands(): array
{
    return [
        'Lexton'          => 'Decorative lighting for Indian homes and festivals.',
        'fizzytech'       => 'Affordable fairy lights and festive decoration lighting.',
        'HUNCHA'          => 'Flameless LED diyas and home decor accessories.',
        'luxentia'        => 'LED tea lights and festive decor essentials.',
        'The Purple Tree' => 'Diwali and festive decoration specialists.',
        'MIRADH'          => 'Remote-controlled curtain lights and party backdrops.',
        'Toy Imagine'     => 'Night lights, projectors and room decor for children.',
        'TakshHaven'      => 'Decorative lamps and gifting lighting.',
    ];
}

// ---------------------------------------------------------------------------
//  Products
//
//  price      = MRP shown on the listing (products.price is the list price)
//  sale_price = what the store actually charges (null when there is no MRP to
//               strike through, so the storefront does not invent a discount)
// ---------------------------------------------------------------------------
function festive_products(): array
{
    return [
        // ------------------------------------------------------------- 1
        [
            'sku' => 'SIK-FLT-1001', 'asin' => 'B0FG2QFCWF',
            'name' => 'Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft',
            'brand' => 'Lexton', 'category' => 'String & Curtain Lights',
            'price' => 1999.00, 'sale_price' => 279.00, 'stock' => 120,
            'rating' => 3.9, 'rating_count' => 5,
            'short' => 'A 10 x 3 ft leaf-vine curtain carrying 180 warm white LEDs, with eight lighting patterns and brightness control on an in-line button — no remote or batteries to lose.',
            'description' => '<p>Artificial leaf vines threaded with 180 warm white LEDs, sized at 10 x 3 feet to cover a window, a headboard wall or a mandap backdrop in one go. The greenery reads as decor even in daylight, so the curtain earns its place on the wall before you switch it on.</p>'
                . '<p>Eight lighting patterns — steady, twinkle, fade, chase and the rest — cycle from a button on the wire itself, which also steps the brightness down for a quiet evening. There is no remote to hunt for and no batteries to replace: it runs straight off a wall socket.</p>'
                . '<p>Rated for indoor use, which makes it a natural fit for living rooms, bedrooms, pooja corners and party backdrops through Diwali, Christmas and wedding season.</p>',
            'features' => [
                '180 warm white LEDs across a 10 x 3 ft leaf-vine curtain',
                '8 lighting patterns including steady, twinkle and slow fade',
                'Brightness adjusts from the same in-line button controller',
                'Plug-powered — no remote and no batteries required',
                'Artificial leaf styling that decorates the wall even when switched off',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Leaf curtain string light', 'Theme' => 'Artificial leaf', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of LEDs' => '180', 'Light Colour' => 'Warm White', 'Light Source' => 'LED', 'Lighting Modes' => '8', 'Brightness Control' => 'Yes, in-line button'],
                'Power' => ['Power Source' => 'Corded electric (plug powered)', 'Controller' => 'In-line button on the wire'],
                'Dimensions & Weight' => ['Size' => '10 x 3 feet', 'Weight' => '160 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor', 'Occasions' => 'Diwali, Christmas, weddings, anniversaries'],
                'In The Box' => ['Contents' => '1 x 180 LED leaf curtain string light'],
            ],
        ],
        // ------------------------------------------------------------- 2
        [
            'sku' => 'SIK-FLT-1002', 'asin' => 'B08HPDWMDV',
            'name' => 'Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White',
            'brand' => 'Lexton', 'category' => 'String & Curtain Lights',
            'price' => 999.00, 'sale_price' => 289.00, 'stock' => 150,
            'rating' => 4.1, 'rating_count' => 4981,
            'short' => 'Twelve stars — six large, six small — on a 138 LED warm white curtain, with eight flashing modes and IP44 weatherproofing for balconies and porches.',
            'description' => '<p>Six big stars and six small ones hang from a single 138 LED curtain, giving the display some depth instead of a flat row of identical shapes. The warm white tone sits closer to candlelight than to daylight, which is what keeps it flattering indoors.</p>'
                . '<p>Eight flashing modes cover the full range: combination, waves, sequential, slow-glo, chasing, slow fade, twinkle and steady-on. Steady-on is the one most people settle on; the rest are there for the party.</p>'
                . '<p>The wires and lamp housings are IP44 rated, so this is one of the few curtain lights here that is genuinely happy on a balcony railing or under a porch, not just behind glass.</p>',
            'features' => [
                '12 stars — 6 large and 6 small — on one curtain',
                '138 warm white LEDs',
                '8 flashing modes including twinkle, chase and steady-on',
                'IP44 rated wiring for covered outdoor use',
                'Plug in and go — no assembly needed',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Star curtain light', 'Theme' => 'Stars', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of LEDs' => '138', 'Light Colour' => 'Warm White', 'Light Source' => 'LED', 'Lighting Modes' => '8'],
                'Power' => ['Power Source' => 'Corded electric', 'Wattage' => '5 W', 'Controller' => 'Manual control'],
                'Dimensions & Weight' => ['Weight' => '400 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor and covered outdoor', 'Weather Rating' => 'IP44', 'Occasions' => 'Diwali, Christmas, weddings'],
                'In The Box' => ['Contents' => '1 x 12-star curtain light (6 big + 6 small)'],
            ],
        ],
        // ------------------------------------------------------------- 3
        [
            'sku' => 'SIK-FLT-1003', 'asin' => 'B0F3XVDCX9',
            'name' => 'fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour',
            'brand' => 'fizzytech', 'category' => 'String & Curtain Lights',
            'price' => 559.00, 'sale_price' => 395.00, 'stock' => 200,
            'rating' => 4.0, 'rating_count' => 2418,
            'short' => 'A 138 LED multicolour star curtain with eight lighting modes — the loud, celebratory option for Diwali and birthday walls.',
            'description' => '<p>Where the warm white curtains are about atmosphere, this one is about colour. 138 SMD LEDs in a star-studded curtain throw a full multicolour wash across a wall or window, which is exactly what you want behind a birthday table or a Diwali rangoli.</p>'
                . '<p>Eight modes let you dial it from a slow colour fade up to a full chase. SMD diodes sit flush in the strand rather than bulging out of it, so the curtain hangs flat and packs away without tangling into a ball.</p>'
                . '<p>Light enough at 100 g to hang from removable hooks, and rated for both indoor and sheltered outdoor use.</p>',
            'features' => [
                '138 multicolour SMD LEDs in a star curtain layout',
                '8 lighting modes from slow fade to full chase',
                'Flat-sitting SMD diodes that resist tangling',
                'Indoor and sheltered outdoor use',
                'Only 100 g — hangs from removable adhesive hooks',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Star fairy curtain light', 'Theme' => 'Stars', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of LEDs' => '138', 'Light Colour' => 'Multicolour', 'Light Source' => 'LED (SMD)', 'Lighting Modes' => '8'],
                'Power' => ['Power Source' => 'Corded electric', 'Wattage' => '5 W', 'Voltage' => '220 V'],
                'Dimensions & Weight' => ['Weight' => '100 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor and outdoor', 'Occasions' => 'Diwali, Christmas, birthdays, weddings, engagements'],
                'In The Box' => ['Contents' => '1 x 138 LED star fairy curtain light'],
            ],
        ],
        // ------------------------------------------------------------- 4
        [
            'sku' => 'SIK-FLT-1004', 'asin' => 'B0BTTJS75Z',
            'name' => 'fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White',
            'brand' => 'fizzytech', 'category' => 'String & Curtain Lights',
            'price' => 599.00, 'sale_price' => 249.00, 'stock' => 240,
            'rating' => 3.9, 'rating_count' => 3753,
            'short' => 'Three metres of warm white string with 15 snowflake shades — a small accent light for a shelf, a mirror or a bedside frame.',
            'description' => '<p>A short, deliberate string rather than a wall-filling curtain: 3 metres carrying 15 snowflake-shaped shades in warm white. It is the right scale for edging a mirror, running along a shelf or framing a headboard, where a 138 LED curtain would simply be too much.</p>'
                . '<p>The snowflake shades diffuse each LED into a soft shape instead of a hard point of light, so it photographs well and does not glare in a small room.</p>'
                . '<p>At 100 g the whole string is light enough for adhesive hooks or washi tape, and a button controller handles power without needing a wall switch within reach.</p>',
            'features' => [
                '15 snowflake-shaded LEDs across 3 metres',
                'Warm white tone that stays soft in small rooms',
                'Shaped diffusers instead of bare point-source LEDs',
                'Button controller on the wire',
                'Light enough for adhesive hooks — 100 g total',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Snowflake fairy string light', 'Theme' => 'Snowflake', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of LEDs' => '15', 'Light Colour' => 'Warm White', 'Light Source' => 'LED'],
                'Power' => ['Power Source' => 'Corded electric', 'Wattage' => '5 W', 'Voltage' => '220 V AC', 'Controller' => 'Button control'],
                'Dimensions & Weight' => ['Length' => '3 metres', 'Weight' => '100 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor and outdoor', 'Occasions' => 'Diwali, Christmas, birthdays, baby showers, anniversaries'],
                'In The Box' => ['Contents' => '1 x 3 m snowflake fairy string light'],
            ],
        ],
        // ------------------------------------------------------------- 5
        [
            'sku' => 'SIK-FLT-2001', 'asin' => 'B0FQ5HK8DH',
            'name' => 'HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow',
            'brand' => 'HUNCHA', 'category' => 'Diyas & LED Candles',
            'price' => 149.00, 'sale_price' => null, 'stock' => 300,
            'rating' => 0.0, 'rating_count' => 0,
            'short' => 'Six battery-powered LED diyas with a flickering flame effect — no smoke, no wax and no open flame, so they are safe on a rangoli or around children.',
            'description' => '<p>Six flameless tea lights that flicker like a real wick without any of the consequences: no smoke, no melted wax on the floor, and nothing hot to knock over. That combination is what makes them usable on a rangoli, along a staircase edge or on a low table where children and pets actually go.</p>'
                . '<p>Each candle runs on its own replaceable button cell and switches on underneath, so you can place them and forget them for the evening. The acrylic body picks up and scatters the light, which gives a softer glow than a bare LED.</p>'
                . '<p>Standard tea light dimensions, so they drop straight into holders you already own.</p>',
            'features' => [
                'Pack of 6 flameless LED tea lights',
                'Flickering flame effect in a warm yellow tone',
                'No smoke, no wax and nothing hot to touch',
                'Individual on/off switch and replaceable button cell',
                'Fits standard tea light holders and diya stands',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Flameless LED tea light', 'Style' => 'Modern', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of Candles' => '6', 'Light Colour' => 'Warm Yellow', 'Light Source' => 'LED', 'Effect' => 'Flickering flame'],
                'Power' => ['Power Source' => 'Battery powered (button cell, included)'],
                'Dimensions & Weight' => ['Weight' => '7 g per candle'],
                'Materials' => ['Body' => 'Acrylic / plastic'],
                'In The Box' => ['Contents' => '6 x LED tea light candles with batteries'],
            ],
        ],
        // ------------------------------------------------------------- 6
        [
            'sku' => 'SIK-FLT-2002', 'asin' => 'B0GYT34RGD',
            'name' => 'luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White',
            'brand' => 'luxentia', 'category' => 'Diyas & LED Candles',
            'price' => 349.00, 'sale_price' => 249.00, 'stock' => 260,
            'rating' => 0.0, 'rating_count' => 0,
            'short' => 'Six 3 cm warm white LED diyas in acrylic bodies, with a simple on/off switch — sized to fit the tea light holders you already own.',
            'description' => '<p>A compact 3 cm tea light, which matters more than it sounds: it is the standard size, so these sit properly in existing holders, lanterns and diya stands rather than rattling around or perching on top.</p>'
                . '<p>Warm white rather than yellow, giving a cleaner light that suits a temple shelf or a dinner table as readily as a festival display. The acrylic housing spreads the LED out instead of leaving a visible bright spot.</p>'
                . '<p>Flameless and smokeless throughout, with a single switch per candle. Nothing to trim, nothing to melt, and nothing that needs watching once the room empties.</p>',
            'features' => [
                'Pack of 6 LED tea lights in a 3 cm standard size',
                'Warm white glow suited to temple shelves and dinner tables',
                'Flameless and smokeless — safe around children and elders',
                'Simple ON/OFF switch on each candle',
                'Drops into existing tea light holders and lanterns',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Flameless LED tea light', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of Candles' => '6', 'Light Colour' => 'Warm White', 'Light Source' => 'LED'],
                'Power' => ['Power Source' => 'Battery powered', 'Controller' => 'ON/OFF switch'],
                'Dimensions & Weight' => ['Diameter' => '3 cm', 'Weight' => '130 g (pack)'],
                'Materials' => ['Body' => 'Acrylic / plastic'],
                'In The Box' => ['Contents' => '6 x LED tea light candles, instruction manual'],
            ],
        ],
        // ------------------------------------------------------------- 7
        [
            'sku' => 'SIK-FLT-1005', 'asin' => 'B0922XQMYD',
            'name' => 'The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m',
            'brand' => 'The Purple Tree', 'category' => 'String & Curtain Lights',
            'price' => 1299.00, 'sale_price' => 398.00, 'stock' => 180,
            'rating' => 4.1, 'rating_count' => 15192,
            'short' => 'Twelve hanging diya shades on a 2.5 m, 138 LED curtain — the traditional Diwali motif in a form you can hang and forget.',
            'description' => '<p>Twelve diya-shaped shades hang from a 2.5 metre curtain lit by 138 LEDs, keeping the festival motif while skipping the oil, the wicks and the refilling. It is the most explicitly Diwali piece in this range, and the review count suggests it is the one people come back for.</p>'
                . '<p>Eight modes run from a button controller, with a warm yellow tone chosen to read as lamplight rather than as electric white. Hung in a window, the diya silhouettes are legible from the street, which a plain string never manages.</p>'
                . '<p>At 50 g it is the lightest curtain here — adhesive hooks or a curtain rod will hold it without complaint.</p>',
            'features' => [
                '12 hanging diya shades on a 2.5 m curtain',
                '138 LEDs in a warm yellow, lamplight-style tone',
                '8 lighting modes via button controller',
                'Diya silhouettes read clearly from outside a window',
                'Just 50 g — the lightest curtain in the range',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Diya curtain light', 'Theme' => 'Diya', 'Country of Origin' => 'India'],
                'Lighting' => ['Number of LEDs' => '138', 'Number of Diyas' => '12', 'Light Colour' => 'Warm Yellow', 'Lighting Modes' => '8'],
                'Power' => ['Power Source' => 'Corded electric', 'Wattage' => '5 W', 'Voltage' => '220 V', 'Controller' => 'Button control'],
                'Dimensions & Weight' => ['Length' => '2.5 metres', 'Weight' => '50 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor', 'Occasions' => 'Diwali, Christmas, weddings, home decoration'],
                'In The Box' => ['Contents' => '1 x diya curtain light (12 diyas)'],
            ],
        ],
        // ------------------------------------------------------------- 8
        [
            'sku' => 'SIK-FLT-1006', 'asin' => 'B09J3H1HHB',
            'name' => 'MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White',
            'brand' => 'MIRADH', 'category' => 'String & Curtain Lights',
            'price' => 399.00, 'sale_price' => null, 'stock' => 140,
            'rating' => 3.9, 'rating_count' => 234,
            'short' => 'A 3 x 3 metre wall of 300 warm white LEDs with a remote for brightness and mode, running off any USB port or power bank.',
            'description' => '<p>Three metres square and 300 LEDs deep, this is a backdrop rather than an accent — the size photographers use behind a sweetheart table or a photo booth. Warm white keeps skin tones flattering in pictures, which the cooler multicolour version does not.</p>'
                . '<p>The remote is what separates it from cheaper curtains: brightness and mode change from across the room, so you can set it once the guests arrive rather than reaching behind the drape.</p>'
                . '<p>USB power is the other practical win. It runs from a phone adapter, a laptop or a power bank, which means the backdrop does not have to live next to a wall socket.</p>',
            'features' => [
                '300 warm white LEDs across a 9.8 x 9.8 ft (3 x 3 m) curtain',
                'Remote control for brightness and lighting mode',
                'USB powered — runs from an adapter, laptop or power bank',
                'Photo-friendly warm tone for backdrops and booths',
                'Low-draw LEDs at roughly 4 W',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Fairy curtain backdrop light', 'Style' => 'Modern'],
                'Lighting' => ['Number of LEDs' => '300', 'Light Colour' => 'Warm White', 'Light Source' => 'LED'],
                'Power' => ['Power Source' => 'USB powered', 'Wattage' => '4 W', 'Controller' => 'Remote control'],
                'Dimensions & Weight' => ['Size' => '9.8 x 9.8 feet (3 x 3 m)', 'Weight' => '150 g'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor', 'Occasions' => 'Diwali, Navratri, Christmas, New Year, weddings'],
                'In The Box' => ['Contents' => '1 x 300 LED curtain light, 1 x remote control'],
            ],
        ],
        // ------------------------------------------------------------- 9
        [
            'sku' => 'SIK-FLT-1007', 'asin' => 'B0CHZDXSFV',
            'name' => 'MIRADH 300 LED Fairy Curtain Lights — USB, Remote Controlled, Multicolour',
            'brand' => 'MIRADH', 'category' => 'String & Curtain Lights',
            'price' => 1999.00, 'sale_price' => 399.00, 'stock' => 140,
            'rating' => 3.8, 'rating_count' => 141,
            'short' => 'The multicolour version of the 300 LED USB curtain — eight modes, remote brightness control, and rated for sheltered outdoor use.',
            'description' => '<p>Same 300 LED curtain and same remote as the warm white model, swapped to full multicolour. This is the one for a kids\' party, a Navratri setup or anywhere the colour is the point rather than the mood.</p>'
                . '<p>Eight modes cycle from a slow cross-fade through to a fast chase, all adjustable from the remote along with brightness. Copper-cored strands stay flexible in the cold and coil back into their box without kinking.</p>'
                . '<p>Rated for indoor and sheltered outdoor use, and USB powered, so a balcony railing or a garden gazebo is within reach of a power bank.</p>',
            'features' => [
                '300 multicolour LEDs with 8 lighting modes',
                'Remote control for brightness and mode',
                'USB powered — adapter, laptop or power bank',
                'Flexible copper-cored strands that resist kinking',
                'Suitable for indoor and sheltered outdoor use',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Fairy curtain backdrop light', 'Style' => 'Festive decorative lighting'],
                'Lighting' => ['Number of LEDs' => '300', 'Light Colour' => 'Multicolour', 'Lighting Modes' => '8', 'Light Source' => 'LED'],
                'Power' => ['Power Source' => 'USB powered', 'Controller' => 'Remote control', 'Brightness' => 'Adjustable'],
                'Dimensions & Weight' => ['Weight' => '200 g'],
                'Materials' => ['Strand Core' => 'Copper'],
                'Usage' => ['Indoor / Outdoor' => 'Indoor and sheltered outdoor', 'Occasions' => 'Diwali, Navratri, Christmas, birthdays, anniversaries'],
                'In The Box' => ['Contents' => '1 x 300 LED curtain light, 1 x remote control'],
            ],
        ],
        // ------------------------------------------------------------- 10
        [
            'sku' => 'SIK-FLT-3001', 'asin' => 'B0D4ZCG5K9',
            'name' => 'Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes',
            'brand' => 'Toy Imagine', 'category' => 'Lamps & Projectors',
            'price' => 699.00, 'sale_price' => 351.00, 'stock' => 90,
            'rating' => 3.9, 'rating_count' => 269,
            'short' => 'An astronaut-shaped star projector with four light modes, three brightness levels and an auto-off timer — built for a child\'s bedtime rather than a party.',
            'description' => '<p>A star projector in an astronaut housing that throws a galaxy across the ceiling. Four projection modes and three brightness levels mean it works as a full light show at bedtime and as a dim nightlight an hour later.</p>'
                . '<p>The timer is the feature parents actually buy it for: it shuts itself off after the child is asleep, so nobody has to creep back in. That single detail is what separates it from the projector toys that stay on until morning.</p>'
                . '<p>Suggested for ages 3 to 12, and it holds up as room decor when it is switched off — which is more than most character nightlights manage.</p>',
            'features' => [
                '4 projection modes with 3 dimmable brightness levels',
                'Auto-off timer so it does not run all night',
                'Astronaut housing that works as daytime room decor',
                'Touch control, USB powered',
                'Suggested for ages 3 to 12',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Star projector night light', 'Style' => 'Astronaut / space', 'Country of Origin' => 'India'],
                'Lighting' => ['Light Source' => 'LED', 'Projection Modes' => '4', 'Brightness Levels' => '3'],
                'Power' => ['Power Source' => 'USB powered', 'Wattage' => '5 W', 'Controller' => 'Touch control', 'Timer' => 'Built-in auto shut-off'],
                'Dimensions & Weight' => ['Weight' => '300 g'],
                'Materials' => ['Body' => 'ABS / plastic'],
                'In The Box' => ['Contents' => 'Projector lamp, USB cable, user manual'],
            ],
        ],
        // ------------------------------------------------------------- 11
        [
            'sku' => 'SIK-FLT-3002', 'asin' => 'B0FSKL2CMM',
            'name' => 'TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable',
            'brand' => 'TakshHaven', 'category' => 'Lamps & Projectors',
            'price' => 1550.00, 'sale_price' => 259.00, 'stock' => 110,
            'rating' => 4.0, 'rating_count' => 198,
            'short' => 'A faceted crystal-effect bedside lamp cycling 16 RGB colours, controlled by touch or remote, and cordless once charged.',
            'description' => '<p>A faceted diamond-cut body that scatters the LED into points of light across the surface it sits on. At 5 cm it is a bedside or shelf piece rather than a room light, and it is as much an object as it is a lamp.</p>'
                . '<p>Sixteen RGB colours and several effects switch from the touch base or the bundled remote. The rechargeable battery is what makes it genuinely portable — charge it over USB, then move it to a balcony, a dinner table or a restaurant setting with no cable trailing behind.</p>'
                . '<p>The packaging and price point make it a common gifting choice for birthdays, anniversaries and Diwali.</p>',
            'features' => [
                '16 RGB colours with multiple lighting effects',
                'Touch base and bundled remote control',
                'USB rechargeable — runs cordless once charged',
                'Faceted crystal-effect body that scatters light',
                'Compact 5 cm footprint for a bedside or shelf',
            ],
            'specs' => [
                'General' => ['Product Type' => 'Decorative table lamp', 'Style' => 'Modern', 'Country of Origin' => 'India'],
                'Lighting' => ['Light Source' => 'LED', 'Colours' => '16 RGB', 'Effects' => 'Multiple colour-changing modes'],
                'Power' => ['Power Source' => 'USB rechargeable battery', 'Controller' => 'Touch and remote control'],
                'Dimensions & Weight' => ['Dimensions' => '5 x 5 x 5 cm', 'Weight' => '320 g'],
                'Materials' => ['Body' => 'Plastic', 'Shade' => 'Silicone'],
                'In The Box' => ['Contents' => 'Crystal diamond table lamp, remote control, USB charging cable, user manual'],
            ],
        ],
    ];
}

/**
 * Gallery image URLs per ASIN, captured from the operator's own listings.
 * Downloaded to uploads/products/ on import so the storefront is self-hosted
 * and never hotlinks.
 */
function festive_image_map(): array
{
    return [
        'B0FG2QFCWF' => [
            'https://m.media-amazon.com/images/I/51sn52oxalL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/51zaaEQYT0L._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/51MosgyB3XL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/51ZBS9iciLL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61VPATEKjkL._SL1500_.jpg',
        ],
        'B08HPDWMDV' => [
            'https://m.media-amazon.com/images/I/81nDvbf7fPL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61Wnzg9znyL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71H2zyO6MNL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71+B9Tr-SaL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71N9gXnMvvL._SL1500_.jpg',
        ],
        'B0F3XVDCX9' => [
            'https://m.media-amazon.com/images/I/614tL1tZ8qL._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/61xrdkNcTmL._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/71-LxI4eD+L._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/61SqSe80ACL._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/71-VZ8KtF1L._SL1080_.jpg',
        ],
        'B0BTTJS75Z' => [
            'https://m.media-amazon.com/images/I/71boc6-gfrL._SL1499_.jpg',
            'https://m.media-amazon.com/images/I/81Umgn0TwWL._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/81ZQ3DUwIAL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71amxMN+0mL._SL1080_.jpg',
            'https://m.media-amazon.com/images/I/71cYxF738KL._SL1080_.jpg',
        ],
        'B0FQ5HK8DH' => [
            'https://m.media-amazon.com/images/I/614vvgjEIhL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/718X6OqDJnL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71qFJ00pzgL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/714rvIv64BL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/712CWsKK25L._SL1500_.jpg',
        ],
        'B0GYT34RGD' => [
            'https://m.media-amazon.com/images/I/71IVkobrqyL._SL1340_.jpg',
            'https://m.media-amazon.com/images/I/71+FpnqMHsL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71NsHoFIMrL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61tHzpGmVTL._SL1500_.jpg',
        ],
        'B0922XQMYD' => [
            'https://m.media-amazon.com/images/I/61PHabsvbEL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61FxKa4pyeL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71TY1qLj7AL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61reJro3NuL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61oqRUX84pL._SL1098_.jpg',
        ],
        'B09J3H1HHB' => [
            'https://m.media-amazon.com/images/I/71JJbXIr9bS._SL1200_.jpg',
            'https://m.media-amazon.com/images/I/71tDmFlT9QL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71z5hyi6Q8L._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/61VeklVV7YL._SL1001_.jpg',
            'https://m.media-amazon.com/images/I/61+q+Aj284S._SL1001_.jpg',
        ],
        'B0CHZDXSFV' => [
            'https://m.media-amazon.com/images/I/71orOt9JOLL._SL1200_.jpg',
            'https://m.media-amazon.com/images/I/71jcgdynNeL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/714yIKC-PCL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71PECvhfHWL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71bLYo2Ql7L._SL1500_.jpg',
        ],
        'B0D4ZCG5K9' => [
            'https://m.media-amazon.com/images/I/61ZYU+cnZRL._SL1000_.jpg',
            'https://m.media-amazon.com/images/I/61i+LP7hYxL._SL1000_.jpg',
            'https://m.media-amazon.com/images/I/61MoC67GWOL._SL1000_.jpg',
            'https://m.media-amazon.com/images/I/616LcGGauQL._SL1000_.jpg',
            'https://m.media-amazon.com/images/I/61jICW1WThL._SL1000_.jpg',
        ],
        'B0FSKL2CMM' => [
            'https://m.media-amazon.com/images/I/81EQY4TR8PL._SL1500_.jpg',
            'https://m.media-amazon.com/images/I/71vSIWzVZeL._SL1472_.jpg',
            'https://m.media-amazon.com/images/I/61SFL+G0xSL._SL1366_.jpg',
            'https://m.media-amazon.com/images/I/51CGb9vDXOL._SL1024_.jpg',
            'https://m.media-amazon.com/images/I/6103nWrJ1dL._SL1079_.jpg',
        ],
    ];
}

// ---------------------------------------------------------------------------
//  Import
// ---------------------------------------------------------------------------

/** Amazon serves these only to a browser-looking client. */
function festive_download_image(string $url, string $destination): bool
{
    if (is_file($destination) && filesize($destination) > 2048) {
        return true;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8', 'Referer: https://www.amazon.in/'],
    ]);
    $bytes = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($bytes) || $code !== 200 || strlen($bytes) < 2048) {
        return false;
    }

    // Never trust the extension — confirm it really is an image before writing.
    $info = @getimagesizefromstring($bytes);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return false;
    }

    return @file_put_contents($destination, $bytes) !== false;
}

function festive_seed_run(bool $refreshImages = false): array
{
    $report = ['categories' => 0, 'brands' => 0, 'created' => 0, 'updated' => 0, 'images' => 0, 'failed' => []];

    // ---- Categories -------------------------------------------------------
    $categoryIds = [];
    foreach (festive_category_tree() as $parentName => $parent) {
        $parentId = (int) Database::fetchColumn('SELECT `id` FROM `categories` WHERE `name` = :n LIMIT 1', ['n' => $parentName]);
        if ($parentId === 0) {
            $parentId = Database::insert('categories', [
                'name' => $parentName, 'slug' => slugify($parentName),
                'description' => $parent['description'],
                'sort_order' => 90, 'show_in_menu' => 1, 'status' => STATUS_ACTIVE,
            ]);
            $report['categories']++;
        }

        foreach ($parent['children'] as $childName => $childDescription) {
            $childId = (int) Database::fetchColumn('SELECT `id` FROM `categories` WHERE `name` = :n LIMIT 1', ['n' => $childName]);
            if ($childId === 0) {
                $childId = Database::insert('categories', [
                    'parent_id' => $parentId, 'name' => $childName, 'slug' => slugify($childName),
                    'description' => $childDescription,
                    'sort_order' => 0, 'show_in_menu' => 1, 'status' => STATUS_ACTIVE,
                ]);
                $report['categories']++;
            }
            $categoryIds[$childName] = $childId;
        }
    }

    // ---- Brands -----------------------------------------------------------
    $brandIds = [];
    foreach (festive_brands() as $name => $description) {
        $id = (int) Database::fetchColumn('SELECT `id` FROM `brands` WHERE `name` = :n LIMIT 1', ['n' => $name]);
        if ($id === 0) {
            $id = Database::insert('brands', [
                'name' => $name, 'slug' => slugify($name), 'description' => $description,
                'sort_order' => 0, 'status' => STATUS_ACTIVE,
            ]);
            $report['brands']++;
        }
        $brandIds[$name] = $id;
    }

    // ---- Products ---------------------------------------------------------
    $uploadDir = UPLOAD_PATH . '/products';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $images = festive_image_map();

    foreach (festive_products() as $item) {
        $existingId = (int) Database::fetchColumn('SELECT `id` FROM `products` WHERE `sku` = :s LIMIT 1', ['s' => $item['sku']]);

        $row = [
            'name'              => $item['name'],
            'slug'              => slugify($item['name']),
            'sku'               => $item['sku'],
            'brand_id'          => $brandIds[$item['brand']] ?? null,
            'category_id'       => $categoryIds[$item['category']] ?? null,
            'short_description' => $item['short'],
            'description'       => $item['description'],
            'price'             => $item['price'],
            'sale_price'        => $item['sale_price'],
            'stock'             => $item['stock'],
            'low_stock_threshold' => 10,
            'tax_rate'          => 18.00,
            // 9405 covers decorative lighting sets under the Indian HSN schedule.
            'hsn_code'          => '9405',
            'warranty'          => '6 Month Seller Warranty',
            'cod_available'     => 1,
            'free_shipping'     => 0,
            'status'            => STATUS_ACTIVE,
            'is_new_arrival'    => 1,
            'rating_avg'        => $item['rating'],
            'rating_count'      => $item['rating_count'],
            'meta_title'        => mb_substr($item['name'], 0, 160),
            'meta_description'  => mb_substr($item['short'], 0, 300),
        ];

        if ($existingId > 0) {
            // published_at is deliberately NOT in $row: it is the date the
            // catalogue went live, and "New arrivals" is sorted by it. Writing
            // it here re-dated all eleven products to the moment of the last
            // refresh and shuffled that shelf, so a correction to one
            // description changed the shop front. A product that somehow has
            // none still gets one, which is what keeps it visible.
            Database::update('products', $row, '`id` = :id', ['id' => $existingId]);
            Database::query(
                'UPDATE `products` SET `published_at` = :now WHERE `id` = :id AND `published_at` IS NULL',
                ['now' => date('Y-m-d H:i:s'), 'id' => $existingId]
            );
            $productId = $existingId;
            $report['updated']++;
        } else {
            $productId = Database::insert('products', ['published_at' => date('Y-m-d H:i:s')] + $row);
            $report['created']++;
        }

        // Features and specs are rewritten wholesale — they are derived data.
        Database::delete('product_features', '`product_id` = :p', ['p' => $productId]);
        foreach ($item['features'] as $i => $feature) {
            Database::insert('product_features', ['product_id' => $productId, 'feature' => $feature, 'sort_order' => $i]);
        }

        Database::delete('product_specifications', '`product_id` = :p', ['p' => $productId]);
        $order = 0;
        foreach ($item['specs'] as $group => $pairs) {
            foreach ($pairs as $key => $value) {
                Database::insert('product_specifications', [
                    'product_id' => $productId, 'spec_group' => $group,
                    'spec_key' => $key, 'spec_value' => $value, 'sort_order' => $order++,
                ]);
            }
        }

        // ---- Images -------------------------------------------------------
        $urls = $images[$item['asin']] ?? [];
        $saved = [];

        foreach (array_values($urls) as $i => $url) {
            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
            $filename = strtolower($item['sku']) . '-' . ($i + 1) . '.' . $extension;
            $absolute = $uploadDir . '/' . $filename;

            if ($refreshImages && is_file($absolute)) {
                @unlink($absolute);
            }

            if (festive_download_image($url, $absolute)) {
                $saved[] = 'uploads/products/' . $filename;
                $report['images']++;
            } else {
                $report['failed'][] = $item['sku'] . ' image ' . ($i + 1);
            }
        }

        if ($saved !== []) {
            Database::update('products', [
                'main_image'   => $saved[0],
                'hover_image'  => $saved[1] ?? null,
            ], '`id` = :id', ['id' => $productId]);

            Database::delete('product_images', '`product_id` = :p', ['p' => $productId]);
            foreach ($saved as $i => $path) {
                Database::insert('product_images', [
                    'product_id' => $productId,
                    'image'      => $path,
                    'alt_text'   => mb_substr($item['name'], 0, 200),
                    'sort_order' => $i,
                ]);
            }
        }
    }

    return $report;
}

// ---------------------------------------------------------------------------
//  CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    // Every other festive seed takes --dry and reports without writing. This
    // one is a full importer with no preview mode, and it used to accept --dry
    // silently and then write anyway - so a "dry run" of the whole seed folder
    // quietly re-imported eleven products and 54 images. Refuse the flags this
    // script does not implement rather than ignoring them.
    $unknown = array_values(array_filter(
        array_slice($argv, 1),
        static fn (string $a): bool => $a !== '--images'
    ));
    if ($unknown !== []) {
        fwrite(STDERR, 'This seed has no ' . implode(' / ', $unknown) . ' mode; the only flag is --images.' . PHP_EOL);
        fwrite(STDERR, 'Running it always writes. Nothing was done.' . PHP_EOL);
        exit(2);
    }

    $result = festive_seed_run(in_array('--images', $argv, true));

    printf(
        'Categories added: %d%sBrands added: %d%sProducts created: %d, updated: %d%sImages saved: %d%s',
        $result['categories'], PHP_EOL, $result['brands'], PHP_EOL,
        $result['created'], $result['updated'], PHP_EOL, $result['images'], PHP_EOL
    );

    foreach ($result['failed'] as $failure) {
        echo '  ! could not fetch ', $failure, PHP_EOL;
    }

    exit(0);
}
