# ShopInnKart

**Festive & decorative lighting e-commerce platform — shopinnkart.com**

Core PHP 8 · MySQL 8 / MariaDB 10.4 · PDO · REST API · hand-built Tailwind-compatible CSS · vanilla JavaScript.

No framework. No Composer. No npm. No jQuery. No CDN. Drop it on any PHP host and it runs.

---

## Contents

- [What it is](#what-it-is)
- [Features](#features)
- [Workflows](#workflows)
- [Requirements](#requirements)
- [Installation](#installation)
- [Project layout](#project-layout)
- [Configuration](#configuration)
- [How it fits together](#how-it-fits-together)
- [Admin panel](#admin-panel)
- [REST API](#rest-api)
- [Theming](#theming)
- [Security model](#security-model)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)

---

## What it is

A complete storefront and back office for selling festive and decorative lighting in India —
string lights, curtain lights, flameless diyas, crystal table lamps and galaxy projectors:

| Area | What you get |
|---|---|
| Storefront | Dynamic homepage builder, mega menu, AJAX search, filters, product pages with variants, cart, coupons, guest + account checkout, wishlist, compare, reviews, blog, CMS pages |
| Admin | Dashboard, catalogue CRUD with variants and CSV import/export, order pipeline, customers, coupons, deals, flash sales, banners, popups, homepage/menu/footer builders, reports, settings, RBAC, audit logs, backups |
| Platform | REST API, notification queue, file cache, stock journal, payment-gateway abstraction, multi-vendor / multi-language / multi-currency ready schema |

Everything a customer sees is database-driven. Changing a price, a banner, a menu item or the
homepage layout never requires touching a file.

---

## Features

Everything below is built and working. Nothing here is a roadmap item.

### Storefront

**Catalogue & discovery**
- Category, brand and tag browsing with a mega menu built from the admin
- AJAX search with autocomplete, recent searches, popular-search chips and product suggestions
- Voice search via the browser's own Speech Recognition — no audio leaves the browser, and the
  button removes itself where the engine is missing or the page is not on `https`
- Faceted filters (price, brand, rating, stock, attributes) with sort and pagination
- Product pages with variants, an image gallery, specification tables, reviews and related rows

**Offers & pricing**
- Coupons with type (percent/fixed/free shipping), minimum spend, usage caps, per-user caps,
  date windows and category/brand/product restrictions
- **Combo offers** — several products sold as one basket line at a set price. The shopper sees one
  card; the warehouse still sees each component. The struck-through figure is what the components
  cost *today*, never an inflated MRP
- Deals, flash sales with countdowns, and coupon/scratch-card offer strips
- Apply and remove a coupon from **both** the cart and the checkout summary, without losing a
  half-typed address

**Cart to order**
- Guest and account checkout, saved addresses, COD and gateway-ready payment selection
- Live totals: subtotal, coupon discount, combo discount, delivery, payment handling fee, GST
- Order confirmation, invoice PDF, order tracking by number, cancellation and return requests

**Account & engagement**
- Registration, login, email verification, password reset, profile and address book
- Wishlist, compare, recently viewed, product reviews with moderation
- Blog, CMS pages, FAQ, contact form, newsletter

### Admin panel

208 screens. Highlights:

| Area | What it does |
|---|---|
| **Dashboard** | Revenue, orders, customers and stock at a glance with trend charts |
| **Catalogue** | Products with variants, images, SEO and stock; categories, brands, attributes; CSV import/export; inventory screen |
| **Orders** | Status pipeline, item-level detail, invoices, shipping and tracking fields, returns |
| **Marketing** | Coupons, combo offers, deals, flash sales, banners, popups, newsletter |
| **Content** | Homepage designer, menu builder, footer builder, pages, FAQ, blog, testimonials |
| **Appearance** | Header/theme customisation with a live preview, floating buttons |
| **Reports** | Sales, products, orders, customers, marketing |
| **Settings** | 14 tabs — store, payment, shipping, tax, email, SEO, social, theme, widgets, invoice, redirects |
| **System** | Admin users, roles and permissions, activity log, error log, login history, backups, maintenance mode |

**Homepage designer** — a three-pane builder: a live storefront preview on the left, a
drag-ordered section list in the middle, and a Content / Style / Visibility inspector on the right.
Reordering uses Pointer Events, so it works with a mouse, a finger or a pen; move up/down buttons
remain for keyboard and screen-reader users. Sections carry per-device and per-audience visibility
plus a schedule window, so a Diwali band can switch itself on and off unattended.

**Role-based access** — every admin screen declares the permission that opens it, and the sidebar
only renders links the signed-in admin can actually use. Hiding a link is cosmetic; the page guard
is the real gate.

### Platform

- REST API (55 endpoints) for cart, wishlist, compare, search, reviews, widgets and notifications
- Payment gateway abstraction — a registry with an interface check, so a new gateway is one class
- File-backed cache with explicit invalidation on every admin write
- Notification queue with email templates; invoices rendered to PDF with TCPDF
- Stock journal, audit log, error log and scheduled backups
- Schema prepared for multi-vendor, multi-language and multi-currency

### Front-end engineering

- One design system in `assets/css/app.css`: three-layer tokens (inputs → roles → scales),
  a 1.200 type scale, documented contrast ratios and a `color-mix` focus ring
- Dark theme by re-pointing the same tokens, with a pre-paint script so there is no flash
- Zero horizontal overflow at 320px across every storefront page
- Admin measured at 390px across 89 screens: no unreachable content, and tap targets at or above
  the WCAG 2.2 minimum
- No framework, no build step, no npm, no CDN. Vanilla JavaScript in 12 modules.

---

## Workflows

### Order lifecycle

```
Cart  →  Checkout  →  Payment  →  Pending
                                    ↓
                              Processing  →  Shipped  →  Delivered
                                    ↓            ↓
                               Cancelled    Return requested  →  Returned  →  Refunded
```

Each transition writes an order status history row, adjusts the stock journal and queues the
customer notification for that step. Stock is committed at order placement, not at add-to-cart, so
an abandoned basket never holds inventory.

### Content workflow

```
Admin → Content → Homepage Builder → Designer
   │
   ├─ Add Section        pick a widget type (20 available)
   ├─ Content tab        heading, copy, data source, artwork
   ├─ Style tab          layout, columns per breakpoint, carousel, colour, spacing
   ├─ Visibility tab     device, audience, schedule window, enable/disable
   └─ drag to reorder    saved as one request; the live preview follows
```

Sections render into four zones — homepage, shop top, product page and cart — so the same builder
drives every merchandising surface. A disabled section still shows in the designer canvas, marked
*Hidden*, because that is where you decide to switch it on.

### Coupon workflow

```
Admin creates coupon  →  restrictions attached  →  optionally "show as an offer"
                                                             ↓
Shopper applies in cart or checkout  →  validate_coupon() re-checks every rule
                                                             ↓
                              totals recalculated  →  usage recorded at order placement
```

The coupon is re-validated at checkout and again at order placement, so a code that expires or hits
its cap between basket and payment cannot slip through.

### Development workflow

```bash
# 1. Point a local database at the app
cp config/db.local.example.php config/db.local.php   # then edit it

# 2. Import the schema
mysql -u root shopinnkart < database/schema.sql

# 3. Apply the migrations, oldest first (see the list below)
php database/migrations/2026_08_13_invoice_email_system.php
php database/migrations/2026_08_14_email_verification_returns.php
php database/migrations/2026_09_14_storefront_redesign.php
php database/migrations/2026_09_21_combo_offers.php
php database/migrations/2026_09_21_shipping_hub.php
php database/migrations/2026_09_22_security.php
php database/migrations/2026_09_22_shipping_fixes.php
php database/migrations/2026_09_23_security_admin.php
php database/migrations/2026_09_23_security_auth.php
php database/migrations/2026_09_23_security_platform.php

# 4. Clear the file cache after any direct SQL write
rm -f storage/cache/*.cache
```

`database/schema.sql` contains `CREATE DATABASE` and `USE`, so it ignores whatever database you
name on the command line — comment those two lines out before importing into a scratch schema.

There is no migration runner: each file in `database/migrations/` is its own CLI script, run from
the project root. They are idempotent and never `DROP`, so running one twice is a no-op and running
the whole list after any `git pull` is the safe habit. File name order is apply order — a later one
may depend on an earlier one's tables. The command is the same on a live host over SSH.

The two newest pairs matter most, because nothing else creates what they add:

| Migration | Without it |
|---|---|
| `2026_09_21_shipping_hub.php` | no `shipping_providers`, `shipments` or `shipment_events` — the shipping hub is simply absent from the admin |
| `2026_09_22_shipping_fixes.php` | no `shipments.courier_id`, no `shipping_providers.webhook_slug`, and no Shiprocket row to configure |
| `2026_09_22_security.php`, `2026_09_23_security_*.php` | no `auth_version` columns (a password change no longer ends the other sessions), no security permissions for the role editor, no "remember me" token table, and none of the platform security settings |

A database built from `database/schema.sql` alone is a *hub-less, pre-hardening* install: it starts
and serves pages, so the gap is easy to miss.

### Deployment workflow

1. Upload everything except the ignored files (see `.gitignore`)
2. Copy `config/db.local.example.php` to `config/db.local.php` with production credentials, or set
   `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` environment variables, which take precedence
3. Make `storage/`, `storage/logs/`, `storage/cache/`, `uploads/` and `config/` writable
4. Point the document root at the project root; `.htaccess` handles the rewrites
5. **Run the migrations** against the live database — every file in `database/migrations/`, in name
   order, exactly as in the development workflow above. A host with SSH runs them directly; without
   SSH, run them locally first and let `php database/export-for-hosting.php` carry the result. Skip
   this and the site still starts, so the gap shows up later as a missing shipping hub or a security
   setting that will not save
6. **Serve over https.** Voice search, and any future browser feature that needs a secure context,
   is refused on plain http
7. Set `APP_DEBUG` to `false` in `config/config.php`

---

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.0 | 8.2+ |
| MySQL | 5.7 | 8.0 (MariaDB 10.4+ also supported) |
| Extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `json`, `session` | plus `gd`, `openssl` |
| Web server | Apache with `mod_rewrite` | Apache or Nginx |
| Disk | 100 MB | 1 GB+ for uploads |

Writable directories: `storage/`, `storage/logs/`, `storage/cache/`, `uploads/`, `config/`.

---

## Installation

### Option A — the installer (recommended)

1. Copy the project into your web root (e.g. `htdocs/ecomweb` or a virtual host root).
2. Open `http://your-host/install.php`.
3. Work through the five steps: requirements → database → schema import → administrator → done.
4. **Delete `install.php`.** The installer locks itself with `storage/installed.lock`, but
   removing the file is the real safeguard.

The installer writes your credentials to `config/db.local.php`. That file is git-ignored and
blocked by `.htaccess`.

### Option B — by hand

```bash
# 1. Create the database and import everything (schema + demo data)
mysql -u root -p < database/schema.sql

# 2. Point the app at it
cp config/db.local.example.php config/db.local.php   # then edit it
#    …or set DB_HOST / DB_NAME / DB_USER / DB_PASS as environment variables

# 3. Make the runtime folders writable
chmod -R 775 storage uploads
```

> On Windows, import with `cmd /c "mysql -u root < database\schema.sql"` rather than piping
> through PowerShell — PowerShell re-encodes the stream and corrupts the ₹ symbol.

### Default credentials

| | |
|---|---|
| Admin | `admin@shopinnkart.com` / `Admin@123` |
| Demo customers | the seeded addresses / `Test@123` |

**Change both before the store is reachable from the internet.**

---

## Project layout

```
/                     Customer-facing pages. There is deliberately no /pages folder —
                      index.php, shop.php, product.php, cart.php … all live at the root.
/api/                 REST endpoints, grouped by resource
/admin/               Admin backend (the only place admin UI lives)
/admin/includes/      Admin auth gate, layout partials, admin helpers
/includes/            Shared frontend PHP: bootstrap, helpers, widgets, auth, cart, orders
/config/              config.php, constants.php, database.php  (+ db.local.php after install)
/assets/css|js|images Design system and artwork
/uploads/             User uploads (never executed — see .htaccess)
/database/schema.sql  The single source of truth for structure AND demo data
/storage/             logs, cache, backups
```

### Key files

| File | Role |
|---|---|
| `includes/init.php` | Boots config, error handling, PDO, session, every helper. Everything requires this first. |
| `config/database.php` | The `Database` class. Every query in the app goes through it. |
| `includes/product-functions.php` | `query_products()` and `product_effective_price()` — the catalogue and pricing brain. |
| `includes/cart-functions.php` | Cart, wishlist, compare, coupon validation, totals. |
| `includes/order-functions.php` | `create_order()` — the only place an order is written. |
| `includes/widgets.php` | The widget engine that renders the homepage and every builder zone. |
| `admin/includes/auth.php` | `admin_require()` — the authorisation gate on every admin page. |

---

## Configuration

`config/config.php` reads, in order of precedence:

1. Environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV`)
2. `config/db.local.php` (written by the installer)
3. Built-in development defaults

`SITE_URL` is auto-detected, so the project runs from a sub-folder without edits.

Set `APP_ENV=production` on a live server. That hides error detail from visitors and routes it
to `storage/logs/` and the `error_logs` table instead.

Everything else — store name, currency, tax, shipping, theme colours, SEO, email — lives in the
`settings` table and is edited from **Admin → Settings**. Nothing business-related is hard-coded.

---

## How it fits together

### Request lifecycle

```
Browser → .htaccess rewrite → page.php
            └── includes/init.php   config → errors → PDO → session → helpers
            └── seo_set([...])      page declares its metadata
            └── includes/header.php  theme tokens, SEO tags, announcement, header, mega menu
            └── page body            queries via helpers, renders with .sik-* classes
            └── includes/footer.php  footer, drawers, popups, JS config, scripts
```

### The widget system

One row in `homepage_sections` = one widget instance. The row carries its type, data source,
layout, responsive column counts, styling, schedule and visibility rules. `render_zone('home')`
walks the rows in order and calls the matching renderer in `includes/widgets.php`.

Adding a new widget type means adding one `case` to `render_widget()` and one function. The
admin builder needs no code change.

Zones: `home`, `shop_top`, `product_bottom`, `cart`, `checkout`, `footer_top`.

### Pricing — read this before touching money

`product_effective_price($product, $variant)` is the single place a price is decided.
Precedence: **active flash sale → active deal → variant sale price → variant price →
product sale price → product price.**

`create_order()` re-runs it inside the checkout transaction, with the product and variant rows
locked `FOR UPDATE`. Nothing a browser sends can change what a customer is charged.

### Order creation

`create_order()` runs one transaction that:

1. Re-reads every cart line with a row lock
2. Rejects unavailable products and insufficient stock
3. Recomputes prices, coupon discount, shipping, tax and the grand total
4. Writes the order and its items
5. Moves stock and journals every movement to `stock_movements`
6. Records coupon usage and burns down deal / flash-sale allocations
7. Empties the cart

Any failure rolls the whole thing back. Notifications and gateway hand-off happen *after* the
commit so a mail problem can never lose an order.

---

## Admin panel

`/admin` — sign in, then everything is gated by role permissions.

### Roles

Six roles ship with the schema: Super Admin, Manager, Order Manager, Product Manager,
Content Manager, Support Manager. Permissions are `module.action` strings
(`products.edit`, `orders.view`, …) stored as a JSON array on the role. Super Admin holds `["*"]`.

Every page calls `admin_require('module.action')` before producing output. Hiding a sidebar link
is cosmetic; that call is the actual check.

### Builders

| Builder | Table(s) | What it controls |
|---|---|---|
| Homepage | `homepage_sections` | Every storefront section: type, data source, layout, columns, schedule, visibility |
| Menu | `menus`, `menu_items` | Main nav, mega menus, mobile drawer, footer menus |
| Footer | `footer_columns`, `footer_links` | Footer columns and their contents |
| Popup / Pop-in | `popups` | Newsletter, coupon, exit-intent, cart-reminder overlays with targeting and frequency |
| Banner | `banners` | Hero slides and promotional bands |

Any admin write calls `cache_bust()`, so storefront caches clear immediately.

---

## REST API

Base: `/api/`. Browse `/api/index.php` in a browser for the live reference.

Every response uses the same envelope:

```json
{ "success": true,  "message": "Product added successfully", "data": { } }
{ "success": false, "message": "Invalid request", "errors": { "field": "reason" } }
```

| Status | Meaning |
|---|---|
| 200 | Handled |
| 401 | Sign-in required (`code: "auth"`) |
| 403 | Not permitted, or a stale CSRF token (`code: "csrf"`) |
| 404 | No such record |
| 405 | Wrong verb — see the `Allow` header |
| 422 | Validation failed; `errors` is keyed by field |
| 429 | Rate limited — see `Retry-After` |

Reads are `GET`. Writes are `POST` and **must** carry the CSRF token as the `X-CSRF-Token`
header or a `csrf_token` field. From the browser, `SIK.get()` / `SIK.post()` handle that for you.

```js
const result = await SIK.post('cart/add.php', { product_id: 12, quantity: 1 });
if (result.success) SIK.toast(result.message, 'success');
```

---

## Theming

**Admin → Settings → Theme.** Colours, radius, container width, card and button styles,
typography, header and footer style, plus custom CSS/JS.

Those values are printed as CSS custom properties in `includes/header.php`:

```css
--sik-primary  --sik-accent  --sik-navy  --sik-text  --sik-muted
--sik-border   --sik-surface --sik-soft  --sik-radius --sik-container
```

`assets/css/app.css` is written entirely against those tokens, so changing a colour in the admin
restyles the whole storefront. Never hard-code a colour in a PHP file.

`assets/css/tailwind.css` is a hand-authored Tailwind-v3-compatible utility layer. It is static
output — there is no build step, no `@tailwind` directives and no PostCSS. If you add utilities,
add them to that file directly.

---

## Security model

| Threat | Mitigation |
|---|---|
| SQL injection | PDO prepared statements everywhere. Table/column names come from allowlists, never user input. |
| XSS | `e()` on every dynamic echo; `sanitize_html()` for admin-authored rich text. |
| CSRF | Per-session token required on every state-changing request (`csrf_require()` / `api_require_csrf()`). |
| Session fixation | Id regenerated on login and every 30 minutes. HttpOnly, SameSite=Lax, Secure on HTTPS. |
| Password storage | `password_hash()` / `password_verify()`, rehashed when PHP's default cost moves. |
| Brute force | Account lockout after 5 failures; per-session rate limits on auth, checkout and public writes. |
| Privilege escalation | `admin_require('module.action')` on every admin page and action. |
| Malicious uploads | Extension + sniffed MIME + decode check, generated filenames, SVG script stripping, and `.htaccess` blocking execution under `/uploads`. |
| Price tampering | Prices are never read from the request. `create_order()` re-reads them under a row lock. |
| Information leak | Production hides error detail; login and password-reset responses never reveal whether an account exists. |

Audit trails: `activity_logs` (admin actions), `login_history` (all attempts), `error_logs`,
`stock_movements` (every stock change).

---

## Deployment

### Apache

`.htaccess` ships with clean URLs, security headers, compression, cache headers and directory
blocks. Ensure `AllowOverride All` is set for the document root and `mod_rewrite`,
`mod_headers`, `mod_deflate` and `mod_expires` are enabled.

### Nginx

There is no `.htaccess` equivalent, so translate the rules:

```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }

location ~ ^/(config|storage|database|includes|admin/includes)/ { deny all; }
location ~ /\.                                                  { deny all; }
location ~* ^/uploads/.*\.(php|phtml|pl|py|jsp|asp|sh|cgi)$      { deny all; }

location ~* \.(css|js|svg|png|jpe?g|webp|gif|ico|woff2?)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

Plus the clean-URL rewrites for `/product/`, `/category/`, `/brand/`, `/page/` and `/blog/`.

### Go-live checklist

- [ ] `APP_ENV=production`
- [ ] `install.php` deleted
- [ ] Admin password changed; demo customers removed
- [ ] HTTPS enforced (uncomment the redirect and HSTS lines in `.htaccess`)
- [ ] SMTP configured in **Settings → Email**, and a test mail sent
- [ ] Store name, contact details, GSTIN, policies reviewed
- [ ] Shipping rates, tax rate and serviceable pincodes set
- [ ] `storage/` and `uploads/` writable but not web-executable
- [ ] A real backup schedule in place (the built-in backup is a convenience, not a strategy)

---

## Troubleshooting

**Blank page / 500**
Check `storage/logs/app-YYYY-MM-DD.log` and **Admin → System → Error Log**. In development
(`APP_ENV=development`) the trace is shown on screen instead.

**"Something went wrong. Please try again." on every page**
The database is unreachable. Verify `config/db.local.php` (or your environment variables) and
that MySQL is running. `storage/logs/db-error.log` has the connection error.

**₹ shows as `â‚¹`**
The dump was imported through a pipe that re-encoded it. Re-import with
`cmd /c "mysql -u root < database\schema.sql"` on Windows, or `mysql -u root < …` on Linux.
Confirm with `SELECT HEX(setting_value) FROM settings WHERE setting_key='currency_symbol';` —
it must be `E282B9`.

**Clean URLs 404**
`mod_rewrite` is off, or `AllowOverride None`. Everything still works via the `.php` URLs while
you fix it.

**"Your session expired" on every AJAX action**
The session cookie is not coming back. Check that the cookie path matches your sub-folder
(`BASE_PATH` in `config/config.php`) and that the clock is right if you are behind a proxy.

**Uploads fail**
`uploads/` is not writable, or the file exceeds `upload_max_filesize` / `post_max_size`.
`.htaccess` raises those to 8M/12M where `mod_php` allows it.

**Changes in the admin do not appear on the storefront**
Server cache. Any admin write should call `cache_bust()`; clear it manually from
**Admin → System → Maintenance → Clear cache**.

**Emails are not sent**
Mail is queued to `notification_queue` and delivered over SMTP by PHPMailer. Check, in order:

1. **Settings → Email → Transport** is `SMTP` (not `Log to file`, which writes `.eml` files to
   `storage/logs/mail/` and delivers nothing), and the host/credentials are filled in.
2. **Settings → Email → Verify SMTP credentials** — opens a real session and authenticates
   without sending, and reports the server's own error if it fails.
3. **Settings → Email Log** — every send attempt is recorded there with its status, the SMTP
   reply and the exact failure reason. Failed rows can be retried individually or in bulk.
4. The queue is draining. Schedule the worker (see below); the storefront fallback only runs on
   roughly one page view in six and does nothing on a store with no traffic.

**Mail is slow or arrives in bursts**
Schedule the worker instead of relying on the page-view fallback, then turn
**Settings → Email → Send from page views** off:

```
* * * * * /usr/bin/php /path/to/ecomweb/bin/send-queued-emails.php --prune >> storage/logs/mail-worker.log 2>&1
```

On Windows use Task Scheduler with `C:\xampp\php\php.exe` and the script path, repeating every
minute.

**Where SMTP credentials live**
Precedence is environment variables → `config/mail.local.php` → **Settings → Email**. Copy
`config/mail.local.example.php` to keep production credentials out of the database. A password
entered in the admin panel is encrypted with the key in `config/app.key.php` and is never
rendered back into the page. If any field is being overridden by a higher-precedence source, the
Email settings screen says so.

**Invoices**
An invoice is raised automatically when an order is confirmed or a payment succeeds, and its PDF
is written to `storage/invoices/` (blocked from the web; served only through
`invoice-download.php` after an ownership check). Numbering, company details, GSTIN and terms are
under **Settings → Invoice**. Numbering fields lock once the first invoice is issued so the
series cannot develop a gap or a repeat.

**Payment webhooks**
One endpoint handles every gateway:

```
https://your-store/api/payments/webhook.php?gateway=razorpay
```

Swap `razorpay` for `stripe`, `cashfree` or `payu`. It refuses everything unless the gateway is
**active** in Settings → Payment *and* its signing secret is set, and unless the signature over
the raw body verifies — an unauthenticated URL that can mark orders paid is otherwise a way to
get free orders. Secrets are read from the gateway's `config` JSON (`webhook_secret` for
Razorpay and Stripe, `secret_key` for Cashfree, `merchant_salt` for PayU), or from
`SIK_WEBHOOK_SECRET_<GATEWAY>` in the environment, which wins.

Send the order number through as gateway metadata so the callback can find it: Razorpay
`notes.order_number`, Stripe `metadata.order_number`, Cashfree `order_tags.order_number`, PayU
`txnid`. Replays are safe — a repeated delivery returns 200 and changes nothing.

**Returns and exchanges**
Customers raise a request from **My Orders → order → Return or exchange** inside the return
window (`return_window_days`, default 7, counted from delivery). Staff approve or reject it on
**Orders → Returns**, which emails the customer the decision.

Approving deliberately does **not** move the order or restock anything — the goods are still with
the customer at that point. Marking the order Returned or Refunded, which is what restocks it and
reverses the coupon, stays on the same screen and is done once the parcel is physically back.

**Email verification**
New accounts get a confirmation link and an unverified-address banner in the account area, with a
rate-limited resend. Sign-in is deliberately **not** blocked: guest checkout is on by default, so
blocking would not stop the purchase — it would only push the customer out of their account and
lose them the order history and invoices. Turn the whole thing off with `email_verification_enabled`.

---

## FAQ

**Can I run it in a sub-folder?**
Yes. `SITE_URL` and `BASE_PATH` are detected at runtime. No configuration needed.

**How do I add a payment gateway?**
Implement `PaymentGatewayInterface` (see `CodGateway` in `includes/order-functions.php`),
register it with `PaymentGatewayFactory::register('razorpay', RazorpayGateway::class)`, and add
the matching row in **Settings → Payment**. Checkout needs no changes.

**How do I add a homepage section?**
**Admin → Content → Homepage Builder → Add Section.** Pick a widget type and data source. To add
a genuinely new *type*, add a `case` to `render_widget()` and a renderer function.

**Is it multi-vendor?**
The schema is ready — `vendors`, `vendor_payouts`, and `vendor_id` on products and order items —
and `settings.multivendor_enabled` gates it. The vendor-facing dashboard is not built; the point
is that enabling it later does not require rebuilding products or orders.

**Multi-language / multi-currency?**
Same story: `languages`, `translations` and `currencies` exist with feature flags. English and
INR are active. The plumbing is there so adding a second one is content work, not surgery.

**Where is the product data seeded from?**
`database/schema.sql`, which holds structure *and* demo data. It is the only SQL file in the
project by design. The installer can clear the demo catalogue while keeping settings, menus,
pages and the homepage layout.

**Are the product images real?**
No. Every image is an original SVG placeholder generated for this project. No third-party
photography or brand artwork is included. Brand logos are plain text wordmarks used to identify
the manufacturer of a product, not reproductions of any trademark.
