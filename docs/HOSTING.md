# Deploying ShopInnKart to Hostinger (or any cPanel host)

Four things have to line up: the files, the database, the credentials and the
writable folders. Miss the last one and the site loads but cannot save an image.

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

That writes **`database/shopinnkart-import.sql`** — one file containing all 89
tables with their structure, plus your products, categories, settings, pages,
menus, homepage sections, coupons and combos. Local rubbish (test carts, logs,
the notification queue) is left out, so the live site starts clean.

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

**Turn off debug.** In `config/config.php` set `APP_DEBUG` to `false`, or a
visitor sees a stack trace when anything goes wrong.

**Set the site URL.** `SITE_URL` in `config/config.php` should be your real
domain, otherwise emails and canonical tags point at the wrong host.

**Install SSL.** Hostinger → *SSL* → issue the free certificate, then force
https. This is not optional decoration:

- browsers refuse the microphone on plain http, so **voice search cannot work**
  without it
- payment gateways will not accept an http callback URL
- an http checkout asking for an address is flagged "Not secure" by the browser

**The application key.** `config/app.key.php` encrypts stored SMTP passwords.
Either copy your existing file up, or let the app generate a fresh one — in
which case re-enter the SMTP password in *Settings → Email*, because the old
ciphertext cannot be read with a new key.

**Change the admin password** from the seeded one, in *System → Admin Users*.

---

## 5. Check it worked

| Check | Expected |
|---|---|
| Open the site | Homepage renders with products |
| `/admin/` | Login page, then the dashboard |
| Add to cart → checkout | Totals calculate, order places |
| *Admin → Catalogue → Products*, edit an image | Upload succeeds (folder permissions) |
| *Admin → System → Error Log* | Empty |

If every page says "Access denied for user", the database user was not attached
to the database in step 1.3.

If the site loads but every image 404s, the project is in a subfolder and
`BASE_PATH` does not match.
