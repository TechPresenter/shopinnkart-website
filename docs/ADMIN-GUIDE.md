# ShopInnKart — Admin Guide

Task-oriented instructions for running the store day to day.
For installation, configuration and deployment, see [`../README.md`](../README.md).
For the live API reference, open `/api/` in a browser.

---

## Signing in

`https://your-store/admin` → email or username + password.

After five failed attempts an account locks for 15 minutes. Every attempt,
successful or not, is recorded in **System → Login History**.

---

## Roles and who can do what

| Role | Can do |
|---|---|
| **Super Admin** | Everything, including settings, admin users and roles |
| **Manager** | Catalogue, orders, customers, marketing, homepage, reports — not settings or admin users |
| **Order Manager** | Orders, returns, customer lookups, reports |
| **Product Manager** | Products, categories, brands, attributes, stock, reviews |
| **Content Manager** | Homepage, banners, pages, FAQ, blog, newsletter |
| **Support Manager** | Customers, reviews, newsletter, order lookups |

Permissions are `module.action` strings on the role (`products.edit`, `orders.view`, …).
Edit them under **System → Roles** with the permission matrix.

Two rules the system enforces and you cannot override:

- You cannot delete your own admin account.
- You cannot delete or demote the last active Super Admin.

---

## Products

### Adding a product

**Catalog → Products → Add Product.**

Fill in at minimum: name, SKU, category, price, stock. The slug is generated
from the name — leave it alone unless you have a reason.

| Field | What it does |
|---|---|
| Price | The MRP, shown struck through when a sale price is set |
| Sale price | The actual selling price. Leave blank for no discount |
| Cost price | Internal only, used in reports. Never shown to customers |
| Stock | Ignored when the product has variants — the parent stock becomes the sum of variant stock |
| Low stock threshold | Below this, the product shows "Only N left" and appears in stock alerts |
| Tax rate | GST %. Prices are tax-inclusive by default (Settings → Tax) |
| Published at | Set a future date to schedule the product. It stays hidden until then |
| Badge text | Overrides the automatic badge, e.g. `EXCLUSIVE` |

### Variants

Add rows under **Variants** — each needs its own SKU, price and stock, plus the
attribute values that identify it (Colour, Storage, RAM, Size, Model). Mark one
as default; that is what a customer gets when they add to cart from a card
without choosing.

Once variants exist the parent stock is computed, not entered.

### Specifications

The **Specifications** repeater drives the spec table and the comparison page.
Use consistent `spec_key` names across products in a category — the compare
table lines rows up by key, so "Battery" and "Battery Life" become two rows.

### Bulk work

- **Bulk actions** on the list: activate, deactivate, feature, mark new/best seller, delete.
- **Import** (`Products → Import`): CSV matched on SKU, so re-importing updates rather than duplicates. Every row is validated before anything is written.
- **Export**: exports whatever the current filter shows, not the whole catalogue.

### Deleting

A product that appears in a live order cannot be deleted — deactivate it
instead. This is deliberate: deleting it would blank the product name on that
customer's order history.

---

## Orders

### The pipeline

`Pending → Confirmed → Processing → Packed → Shipped → Out for Delivery → Delivered`
plus `Cancelled`, `Returned`, `Refunded`.

COD orders auto-confirm on placement (Settings → Store → Auto-Confirm COD).

### Changing status

Open the order → status dropdown → optionally add a note. The system then:

- Records the change in the order history with your name against it
- Marks COD orders **paid** when you set them to Delivered
- **Returns stock** when you move an order to Cancelled, Returned or Refunded
- Releases the coupon redemption so the customer can use it again

Stock is only returned once — moving Cancelled → Returned will not double-credit it.

### Shipping details

Courier name, tracking number and estimated delivery are edited on the order
screen. They appear on the customer's tracking page as soon as you save.

### Returns

**Orders → Returns** lists returned and refunded orders and lets you move a
delivered order into either state with a reason.

---

## Stock

**Catalog → Inventory** is the working screen: filter by low or out of stock,
adjust a line with a reason, or bulk-set levels.

Every change is journalled to `stock_movements` — order, cancel, return,
manual adjust, restock, import — so you can always answer "why is this number
what it is". The history is on the product's view screen.

When something goes from zero back into stock, everyone who left a
"notify me" request is queued an email automatically.

---

## Coupons

**Marketing → Coupons.**

| Type | Behaviour |
|---|---|
| Percentage | % off the eligible subtotal. Set **Maximum discount** or a 50% coupon on a ₹1,20,000 laptop costs you ₹60,000 |
| Fixed | Flat amount off, capped at the subtotal |
| Free shipping | Waives shipping, no other discount |

**Restrictions** narrow a coupon to specific products, categories, brands or
customers, or to first orders only. No restrictions = the whole catalogue.

`Usage limit` is the total across all customers; `Per-user limit` is per account.
Both are enforced at checkout, not just when applied to the cart.

**Coupon → Usage** shows who redeemed it and on which order.

---

## Deals and flash sales

- **Deal of the Day** — one headline product with a countdown and a stock
  progress bar. The storefront shows whichever deal is currently inside its
  time window; expired deals disappear on their own.
- **Flash Sale** — a set of products at sale prices for a window, with a
  per-product stock cap. When a product's cap is exhausted it drops out of the
  sale while the sale continues.

Pricing precedence, highest priority first:
**flash sale → deal → variant sale price → product sale price → price.**
The cheapest applicable price always wins.

---

## Homepage and content

### Homepage Builder

**Content → Homepage Builder.** Each row is one section. Reorder with the
sort-order field or the move buttons, toggle sections on and off, and schedule
them with start/end dates.

Per section you control: the widget type, where its data comes from
(auto, manual pick, category, brand, tag, new, best, trending, deal, flash),
how many items, grid or carousel, columns at desktop/tablet/mobile, autoplay,
background and padding, and who sees it (all / desktop / mobile, guests / signed-in).

Set **Lazy load** on sections far down the page — they then load over AJAX when
the visitor scrolls to them.

### Menu Builder

**Content → Menu Builder.** Pick a location (main, mobile, footer columns),
then add items. An item can link to a category, brand, CMS page or a custom URL.

Mark a top-level item as **mega** to give it a multi-column panel; its children
become the columns, and you can attach featured products and a promo image.

The header shows the first six top-level items inline and moves the rest into a
"More" dropdown, so adding items never breaks the layout.

### Footer, popups and banners

- **Footer Builder** — columns of type links / about / contact / newsletter / payment / html.
- **Popups** — newsletter, coupon, promo, cart reminder. Control the trigger
  (timed, scroll %, exit intent), how often a visitor sees it, which pages it
  appears on, and device/auth targeting. Impressions and conversions are tracked
  per popup.
- **Banners** — hero slides and promotional bands, with separate desktop and
  mobile artwork and optional scheduling.

Any content change clears the storefront cache immediately.

---

## Reviews

**Reviews** with tabs for pending / approved / rejected.

By default only customers who bought the product can review it, and reviews wait
for approval (Settings → Store). Approving recalculates the product's average
rating and emails the customer.

---

## Reports

All reports share a date-range control and export to CSV.

Revenue **excludes** cancelled, returned and refunded orders everywhere, so the
numbers on the dashboard, the sales report and the customer report always agree.

- **Sales** — revenue over time, AOV, discount given, tax and shipping collected, with period-on-period deltas
- **Products** — best sellers by units and revenue, most viewed, high-views/low-sales, low and out of stock
- **Orders** — status mix, fulfilment funnel, average time to delivery, cancellation reasons
- **Customers** — new vs returning, top spenders, repeat rate, geography
- **Marketing** — coupon performance, deal and flash sale results, newsletter growth, popup conversion, and top search terms — **check the zero-result searches**, they tell you what customers wanted and you did not stock

---

## Settings worth getting right first

| Setting | Why it matters |
|---|---|
| **Store → Currency & grouping** | Indian grouping renders ₹1,24,999 rather than ₹124,999 |
| **Tax → Prices include tax** | On by default. Turning it off adds GST on top at checkout and changes every displayed price |
| **Shipping → Free shipping threshold** | Drives the "add ₹X more" progress bar in the cart |
| **Shipping → Pincodes** | Controls serviceability and COD availability per PIN code. Import in bulk by CSV |
| **Payment** | Only Cash on Delivery has a working implementation. The other rows are placeholders for future gateways and are marked as such — do not enable one until it is integrated |
| **Email** | Until SMTP is configured, mail queues but does not send. Use the test button |
| **Theme** | Colours, radius, container width, card and button styles. Changes apply storefront-wide instantly |

---

## Maintenance

**System → Maintenance** shows PHP version, required extensions, disk and cache
usage, and directory writability. From here you can clear the cache, prune old
log files and drain the notification queue.

**System → Backup** dumps the database to `/storage/backups` and lets you
download it. It is a convenience for before-you-change-something moments —
not a substitute for server-level backups.

**Maintenance mode** (Settings → General) takes the storefront offline for
everyone except signed-in admins. The admin panel stays reachable.

---

## When something looks wrong

1. **System → Error Log** — application errors with file, line and stack trace
2. **System → Activity Log** — who changed what, and when
3. **System → Login History** — failed sign-ins and their source IP
4. `storage/logs/app-YYYY-MM-DD.log` on disk if the admin itself will not load

A storefront change that has not appeared is almost always the cache:
**System → Maintenance → Clear cache**.
