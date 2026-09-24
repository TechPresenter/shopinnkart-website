# Go-live checklist

`HOSTING.md` next to this file explains **how** to put ShopInnKart on a server
and why each step exists. This page is the shorter thing: the ordered list of
what is left, with the steps only you can do marked **(you)**.

Tick them in order. Nothing below takes long on its own; the order is what
keeps you from doing the same work twice.

---

## 1. Decisions I cannot make for you

These are sitting on the local copy right now. None of them block the deploy —
but each one ships as it stands if you do not change it, so read them before
the site is public rather than after.

- [ ] **Coupon amounts.** The discounts on *Marketing → Coupons* are
      placeholders, picked so there was something to test the pricing engine
      with. Check every amount and every expiry against what you actually mean
      to give away.
- [ ] **Trust badges.** The old sitewide badges promised things the store does
      not do — free returns, 24/7 support. They now match reality. Read the new
      wording on the homepage and on a product page and tell me if any of it
      still claims more than you offer.
- [ ] **Courier selection.** *Shipping → Settings* scores couriers on price,
      speed and reliability and picks one. The weights, the auto-ship switch
      and the ceiling on COD orders are all my guesses until you say otherwise.
- [ ] **CAPTCHA.** *Security → Settings → Bot protection* already works without
      one: honeypot field, timing check, flood limits. Turnstile or hCaptcha
      needs an account and two keys. Decide whether you want that at launch, or
      only if spam actually starts.
- [ ] **Analytics.** The built-in analytics stores no cookie and no IP address,
      so it needs no consent banner. Google Analytics and Meta Pixel are
      separate boxes on *Settings → Analytics*, empty on purpose — fill them in
      only if you accept the cookie-consent obligations that come with them.

---

## 2. Put it on the server

Full instructions: `HOSTING.md`, sections 1 to 3.

- [ ] **(you)** Create the database and its user in hPanel. Write down all
      three values.
- [ ] **(you)** Upload the files — Git pull, or File Manager.
- [ ] **(you)** Create `config/db.local.php` **on the server** with those three
      values. It is git-ignored, so a Git deploy never carries it up, and
      without it the site falls back to `root` with no password and shows
      *"Access denied for user 'root'@'localhost'"* to every visitor. That is
      the single most likely way a first deploy goes wrong.
- [ ] **(you)** Import `database/shopinnkart-import.sql`.

      I regenerate that file with `php database/export-for-hosting.php`. It
      carries the catalogue, pages, settings and admins, and deliberately
      leaves behind this machine's carts, test orders, logs, analytics, API
      tokens and trusted devices — a laptop's credentials have no business on
      a live site.

- [ ] **(you)** Make `storage/`, `uploads/` and `config/` writable — 755, or
      775 if the host insists.
- [ ] **(you)** Open the site by its real domain. The site URL is worked out
      from the address the visitor used, so there is nothing to edit.

---

## 3. The first hour, before anyone else has the address

- [ ] **(you)** **Change the admin password first, before anything else.**
      *System → Admin Users*. Minimum 12 characters.

      This is not routine hygiene. The repository at
      `github.com/TechPresenter/shopinnkart-website` answers an anonymous
      request, so it is **public** — and the seeded pair
      `admin@shopinnkart.com` / `Admin@123` is printed in its README and
      seeded into the dump you just imported. Until you change it, the live
      admin panel opens for anyone who reads the repository, and hiding the
      login address below does not help: they have the key, not just the door.
      Change the password before the domain is reachable, and consider making
      the repository private as well.
- [ ] **(you)** **Delete `install.php`** from the server. It refuses to run on
      a populated database and records the attempt, but a wizard that can reset
      administrator #1 has no business in a public folder at all.
- [ ] **(you)** **Install SSL** (hPanel → SSL), then *Security → Settings →
      HTTPS* → *Always redirect*. Leave HSTS at **300 seconds** for a day or
      two before raising it to a year. It is the one setting that is genuinely
      hard to undo: a browser told "https only for a year" will not accept
      plain http from you until that year is up.
- [ ] **(you)** **Run the self-test** over SSH:

      ```
      /usr/bin/php <project>/bin/security-selftest.php
      ```

      It answers from the server the questions only the server can answer: does
      this host honour `.htaccess` (some do not, and then `/config/` is
      readable), is `install.php` gone, is HTTPS really on, can the app store
      an encrypted secret. Exit code 0 means clean.
- [ ] **(you)** **Set up two-step sign in on your own account first** — account
      menu → *My Security* — and save the ten backup codes somewhere that is
      not the same laptop. Only then require it of a role on *Security →
      Settings → Two-step sign in*. The screen refuses to let you require it of
      other people before you use it yourself; that is deliberate, so the flow
      is proved on this server before it becomes compulsory for somebody who is
      not in the room.
- [ ] **(you)** **Hide the admin login** — *Security → Settings → Hidden admin
      login address*. Pick the secret part, bookmark the address it gives you,
      and pass it privately to anyone else who signs in. After that `/admin/`
      answers "page not found" like any missing page. If you lose it, run
      `php bin/admin-login-url.php` on the server and it prints it.

---

## 4. The cron jobs

hPanel → *Advanced → Cron Jobs*. Replace `<project>` with the full path to the
project root. The site works without all six — but each one is a job that
otherwise only happens when somebody is watching.

| When | Command | What stops without it |
|---|---|---|
| every minute | `/usr/bin/php <project>/bin/send-queued-emails.php` | **No email leaves the site at all** — orders, password resets, invoices |
| every 5 minutes | `/usr/bin/php <project>/bin/security-monitor.php --quiet` | Nobody is told about a break-in attempt |
| every 30 minutes | `/usr/bin/php <project>/bin/refresh-shipments.php --quiet` | Tracking only moves when someone presses *Refresh* — and delivery is what marks a COD order paid |
| `10 0 * * *` | `/usr/bin/php <project>/bin/analytics-rollup.php --quiet` | Yesterday's visits never become the totals every report reads |
| `15 3 * * *` | `/usr/bin/php <project>/bin/backup.php --quiet` | There is no backup |
| `40 3 * * *` | `/usr/bin/php <project>/bin/prune-logs.php --quiet` | Logs grow without a ceiling, and the retention window you set is a promise nothing keeps |

On a large catalogue, add
`15 3 * * * /usr/bin/php <project>/bin/sitemap-build.php` as well, so the first
visitor of the day is not the one who waits for the sitemap to build.

---

## 5. Switch the outside world on

- [ ] **(you)** **Email.** *Settings → Email* — host, port and mailbox password
      from hPanel → *Emails*. Nothing is sent until this is filled in: the
      worker refuses the whole queue rather than burning each message's
      retries, so mail waits instead of being lost, and the count is visible
      on that screen.

      **Change the two addresses on the same screen while you are there.** They
      ship as `no-reply@shopinnkart.com` and `orders@shopinnkart.com`, which is
      not a domain you own. Mail sent *from* a domain you do not control fails
      SPF and DKIM at the receiving end, so order confirmations land in spam or
      are refused outright — and the admin notifications go to a mailbox that
      does not exist. Set both to addresses on your own domain, then send
      yourself a test from that screen before you trust it.
- [ ] **(you)** **Payments.** Only Cash on Delivery is on. Live gateway keys go
      in *Settings → Payment*, and the mode has to be set to live as well.
- [ ] **(you)** **Courier.** Leave *Shipping → Integrations* off until you have
      an account. Then give the courier the webhook URL shown on its
      *Configure* page. The mock courier is a simulator for testing, and the
      import switches it off for you.
- [ ] **(you)** **Search Console.** *SEO → Sitemap* gives you both verification
      routes and the submission URLs for Google and Bing. Verify the site, then
      submit `/sitemap.xml`.
- [ ] **(you)** **Store address and phone**, on *Settings → Store*, if you want
      Google to show the shop as a local business. While those are empty the
      structured data leaves the claim out altogether — which is the correct
      behaviour, and also means the claim is simply missing.
- [ ] **(you)** **The application key.** `config/app.key.php` encrypts stored
      SMTP and courier passwords. Either copy yours up, or let the server
      generate a fresh one and re-enter those passwords — old ciphertext cannot
      be read with a new key. The *Configure* screens flag whatever they can no
      longer read.

---

## 6. Prove it worked

| Check | Expected |
|---|---|
| The homepage | Products render; no stack trace anywhere on the site |
| Your hidden admin address | The login form, then the dashboard |
| `/admin/login.php` | "Page not found" |
| Add to cart → checkout → place a COD order | Order number like `SIK-20260923-AWNJ7D`, confirmation email within a minute |
| *Admin → System → Error Log* | Empty |
| *Admin → Security → Settings* | Every card green; *Deployment* no longer says *Present* |
| `/sitemap.xml` and `/robots.txt` | Both load, and the sitemap lists real product URLs |

---

## 7. Things that are off on purpose

Not oversights — decisions, written down so you can reverse them knowingly:

- **The mock courier** is disabled by the import. It is a simulator that
  reports a delivery forty hours after booking.
- **Google Analytics, Meta Pixel and GTM** are empty. See section 1.
- **CAPTCHA** is unconfigured; the rest of the bot protection is on.
- **Search Console API submission** is not built. The screen hands you the
  submission URLs instead, because the API would need a Google Cloud project
  to save two clicks.
- **Order numbers changed** to `SIK-<date>-<random>`. The old sequential ones
  told any customer how many orders the store had ever taken.

---

## 8. If something is wrong

`HOSTING.md` section 5 names the three failures worth naming: the `root`
password error, the wrong database user, and every image 404ing because the
project sits in a subfolder. For anything else, *Admin → System → Error Log*
first, then `bin/security-selftest.php`.
