# Deploying ShopInnKart to Hostinger (or any cPanel host)

Four things have to line up: the files, the database, the credentials and the
writable folders. Miss the last one and the site loads but cannot save an image.

> Looking for the short version - the ordered list of what is left to do and
> which parts only you can do? That is **[GO-LIVE.md](GO-LIVE.md)**. This page
> is the detail behind it.

---

## 1. The database

### Create it

Hostinger → **Databases → MySQL Databases**

1. Create a database. Hostinger prefixes the name, so you end up with something
   like `u123456789_shopinnkart`.
2. Create a database user, e.g. `u123456789_shop`.
3. **Add the user to the database** and give it *All Privileges*. This step is
   easy to skip and produces "Access denied for user" on every page.
4. Write down the four values — you need them in step 3 below:

   | | Example | Where it comes from |
   |---|---|---|
   | Host | `localhost` | Hostinger's panel; it is usually `localhost`, occasionally a hostname |
   | Database | `u123456789_shopinnkart` | what you just created |
   | User | `u123456789_shop` | what you just created |
   | Password | — | what you set |

### Import the data

Generate the file on your machine:

```bash
php database/export-for-hosting.php
```

That writes **`database/shopinnkart-import.sql`** — one file containing every
table's structure, plus your products, categories, settings, pages, menus,
homepage sections, coupons, combos and admin accounts. Left out, so the live
site starts clean: local rubbish (test carts, logs, the notification queue) and
this machine's **test customers, orders, payments, invoices, reviews, contact
messages and newsletter sign-ups**. The mock test courier is switched off in the
file.

Because those rows are left out, the totals they produced are **zeroed** in the
same file: `products.sold_count`, `products.rating_avg`, `products.rating_count`,
`combos.sold_count`, `coupons.used_count`, `deals.stock_sold` and
`flash_sale_products.stock_sold`. Your catalogue, prices and coupons arrive
exactly as you set them — only the counters start at zero, which is what they
should say on a store that has not sold anything yet. Left as they were, a new
shop would open showing "best seller" ordering and star ratings with no orders
and no reviews behind them, and a coupon capped at 1500 uses would arrive with
1487 already spent and refuse itself after thirteen more.

To move a store that is already trading, keep the customers and orders — and
with them the counters, which now have their rows to stand on:

```bash
php database/export-for-hosting.php --with-customers
```

Then in Hostinger:

1. **Databases → phpMyAdmin** and open it
2. **Select your database in the left-hand list first** — this matters
3. **Import** tab → choose `shopinnkart-import.sql` → **Go**

> **Do not import `database/schema.sql`.** It begins with `CREATE DATABASE
> shopinnkart;` and `USE shopinnkart;`, so it ignores whichever database you
> selected and tries to create its own — which a shared host will not let you
> do. `shopinnkart-import.sql` has neither statement, which is the whole reason
> it exists.

If the upload is refused for size, either gzip the file (phpMyAdmin accepts
`.sql.gz`) or use Hostinger's SSH access:

```bash
mysql -u u123456789_shop -p u123456789_shopinnkart < shopinnkart-import.sql
```

---

## 2. The files

Upload everything **except** what `.gitignore` lists. The short version of what
must NOT go up as-is:

- `config/db.local.php` — you will write a new one on the server
- `config/app.key.php` — see the warning in step 4
- `storage/logs/*`, `storage/cache/*`, `storage/backups/*`
- `database/shopinnkart-import.sql` — import it, then delete it from the server

Upload by whichever route you prefer:

- **File Manager** — zip the project locally, upload the zip to `public_html`,
  extract there
- **FTP** — any client, into `public_html`
- **Git** — Hostinger can pull from a repository; the repo is
  `https://github.com/TechPresenter/shopinnkart-website`

Put the project **at the document root** (`public_html`), not in a subfolder,
unless you also change `BASE_PATH`.

---

## 3. Database details — where to change them

**`config/db.local.php`** is the only file the application reads for database
credentials. It is not in the repository, so create it on the server. Start
from the template:

```bash
cp config/db.local.example.php config/db.local.php
```

Then edit it:

```php
<?php
return [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'u123456789_shopinnkart',   // your database
    'user' => 'u123456789_shop',          // your database user
    'pass' => 'your-database-password',
];
```

Environment variables take precedence over this file when they are set, so a
host that offers them works too:

```
DB_HOST, DB_NAME, DB_USER, DB_PASS
```

`config/config.php` reads `db.local.php` and nothing else — there is no second
place to change, and `config/db.production.php` is **not** loaded automatically.
It is only a saved copy of credentials; copying it over `db.local.php` is one
way to fill this in.

---

## 4. Before you open it to the public

**Writable folders.** Set these to 755 (or 775 if the host needs it):

```
storage/  storage/logs/  storage/cache/  storage/invoices/  uploads/  config/
```

**Production mode is the default — keep it.** The site runs in production mode
unless `config/db.local.php` says `'env' => 'development'` (or the host sets
`APP_ENV=development`). Never put that line on the live server: development mode
prints stack traces and server paths to visitors, and the admin login page
shows the default admin email and password.

**The site URL is detected automatically** from the domain the visitor used, so
there is nothing to edit. Just open the site by its real domain.

**Install SSL.** Hostinger → *SSL* → issue the free certificate. Then open
*Admin → Security → Settings* and, on the **HTTPS and HSTS** card, set
*Plain HTTP requests* to **Always redirect to HTTPS**.

This used to be three commented-out lines in `.htaccess` that nobody ever
uncommented, so the switch now lives in the panel (`sec_force_https`) where you
can see whether it is on and turn it off again in seconds if the certificate
breaks. The three settings on that card:

| Setting | What it does |
|---|---|
| *Plain HTTP requests* | **Automatic** (default) never redirects but treats `https://` as the real address once it works; **Always redirect** sends every http request to https; **Off** does neither |
| *Remember "HTTPS only" for* | HSTS. Start at **300** (five minutes). Once the site has run happily on https for a day or two, raise it to **31536000** (one year) |
| *Include subdomains* | Only if every subdomain already has a certificate |

Localhost, `127.0.0.1` and private LAN addresses are exempt whatever you set,
so switching it on cannot lock you out of a development copy. Turning HSTS on
is the one setting that is hard to undo: a browser that has been told "https
only for a year" will not accept http from you until that year is up, which is
why the card starts at five minutes.

Switching *Always redirect* on also marks the session and remember-me cookies
`Secure`, so they stop travelling over plain http as well.

HTTPS is not optional decoration:

- browsers refuse the microphone on plain http, so **voice search cannot work**
  without it
- payment gateways will not accept an http callback URL
- an http checkout asking for an address is flagged "Not secure" by the browser

**Delete `install.php` from the server.** The installer can no longer do
anything — it asks the *database* whether the store already has tables, not
just whether `storage/installed.lock` exists, so on a live site it refuses to
run and records the attempt as a `platform.installer_probe` security event.
That closes the hole, but a wizard that resets administrator #1 has no business
sitting in a public document root at all, so delete the file. *Admin → Security
→ Settings* shows **Present** on the *Deployment* card until you do, and the
self-test below reports it too.

**Run the security self-test.** Over SSH, from the project root:

```
/usr/bin/php /home/<your-user>/domains/<your-domain>/public_html/bin/security-selftest.php
```

It answers from the server itself the questions only the live host can answer:
does this host honour `.htaccess` (some do not, and then `/config/` and
`/storage/` are readable), is `install.php` still there, is HTTPS on, can the
app store an encrypted secret at all. Exit code 0 means clean, 1 means
something needs attention, so it can also go on a cron. The same checks are on
the *Security → Settings* screen if you would rather read them there.

**The application key.** `config/app.key.php` encrypts stored SMTP passwords.
Either copy your existing file up, or let the app generate a fresh one — in
which case re-enter the SMTP password in *Settings → Email*, because the old
ciphertext cannot be read with a new key.

**Change the admin password** from the seeded one, in *System → Admin Users*,
the moment the import finishes. The seeded password is printed in the public
README, so until you change it anyone can sign in to your admin panel. Admin
passwords have to be at least **12** characters (shoppers: 10), and the panel
refuses anything on the common-password list.

Setting a password for another admin — or for a customer, from their record —
now signs that account out of every other device it is open on and forgets its
"remember me" browsers. That is the point of the reset when someone calls to
say their account was taken over: the intruder loses their session at the same
moment the password changes. You stay signed in yourself.

**Email.** Nothing is sent until SMTP is filled in under *Settings → Email*
(Hostinger → *Emails* gives you the host, port and mailbox password). Then add
a cron job so queued mail goes out every minute — Hostinger → *Advanced → Cron
Jobs*:

```
/usr/bin/php /home/<your-user>/domains/<your-domain>/public_html/bin/send-queued-emails.php
```

**Payments.** Only Cash on Delivery is switched on. To take online payments,
enter the gateway's **live** keys under *Settings → Payment* and set its mode
to live.

**Migrations.** There is no `database/migrate.php`. Every file in
`database/migrations/` is its own CLI script, run from the project root in file
name order — the README's *Development workflow* lists them. They are
idempotent, so re-running one changes nothing. `shopinnkart-import.sql` already
carries whatever your machine has applied, so a fresh import needs none of them;
run them over SSH after any later `git pull`, oldest first:

```
/usr/bin/php /home/<your-user>/domains/<your-domain>/public_html/database/migrations/2026_09_22_shipping_fixes.php
```

Without `2026_09_21_shipping_hub.php` and `2026_09_22_shipping_fixes.php` there
is no shipping hub at all — no `shipments` tables and no Shiprocket row to
configure — and the admin simply does not show it rather than failing loudly.

**Couriers.** Leave *Shipping → Integrations* switched off until you have a
courier account. The mock courier is for testing only; the import switches it
off for you. Orders can still be shipped by typing the tracking number on the
order page. Once a courier is live, give it the webhook URL shown on its
*Configure* page, and add a second cron job so tracking keeps moving even when
a webhook is missed — every 30 minutes:

```
/usr/bin/php /home/<your-user>/domains/<your-domain>/public_html/bin/refresh-shipments.php --quiet
```

Delivered is what marks a COD order paid, so without webhooks or this job a
parcel only moves when someone presses *Refresh* on it. The job never asks the
mock courier — it is a simulator that reports a delivery forty hours after
booking, and a cron that believed it would settle leftover test orders on its
own — so leftover test shipments stay where they are until someone presses
*Refresh*. If you let the app
generate a new application key, re-enter each courier's password and webhook
secret too: the Configure page flags the ones it can no longer read.

---

## 5. Check it worked

| Check | Expected |
|---|---|
| Open the site | Homepage renders with products |
| `/admin/` | Login page, then the dashboard |
| Add to cart → checkout | Totals calculate, order places |
| *Admin → Catalogue → Products*, edit an image | Upload succeeds (folder permissions) |
| *Admin → System → Error Log* | Empty |

If a page says **"Access denied for user 'root'@'localhost' (using password:
NO)"**, the server has no `config/db.local.php` — the app fell back to XAMPP's
`root` with no password. This is what happens after a Git deploy, because the
file is git-ignored. Create it on the server (step 3) with File Manager.

If it says "Access denied" for **your** database user, the user was not attached
to the database in step 1.3, or the password in `db.local.php` is wrong.

If the site loads but every image 404s, the project is in a subfolder and
`BASE_PATH` does not match.
