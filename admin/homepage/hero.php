<?php
/**
 * ShopInnKart Admin - Hero banners shortcut.
 *
 * The hero widget renders whatever sits in Marketing → Banners at position
 * "hero", so there is nothing to manage here: send the admin straight to the
 * screen that owns those rows. The permission checked is the destination's,
 * otherwise this would redirect people into a 403.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = admin_require('banners.view');

redirect(admin_url('banners/?position=hero'));
