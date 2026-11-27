# MAF Inventory

A Laravel 12 + Inertia + Vue 3 admin baseline: username login, roles and
permissions, an audit trail, a settings module, a dashboard, and a token API.

Everything here is driven by configuration rather than scattered code. Adding
a permission, a setting or a whole module means editing one declared list and
following one pattern, which this file describes.

---

## 1. Requirements

| Tool | Version used | Note |
|---|---|---|
| PHP | 8.4 | 8.2 is the minimum for Laravel 12. Needs the `bcmath`, `gd`, `zip` and `xmlwriter` extensions: `bcmath` does the money and quantity sums (without it every Sales screen is a 500), GD shrinks photos and cuts the tab icons, `zip` and `xmlwriter` write the Reports spreadsheet. `composer.json` requires all four, so `composer install` refuses a server without them |
| Composer | 2.x | |
| Node | 22+ | 24 is what this was built on |
| MySQL / MariaDB | 10.4+ | XAMPP's bundled MariaDB is fine |

## 2. Setup

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Create the database (empty is enough)
mysql -u root -e "CREATE DATABASE db_maf_prototype
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Tables, permissions and the first admin
php artisan migrate --seed

# 5. Run it
npm run dev          # in one terminal
php artisan serve    # in another
```

Open <http://127.0.0.1:8000> and sign in:

| Username | Password |
|---|---|
| `admin` | `admin123` |

**Change that password before anyone else can reach the site.**

### Settings worth checking in `.env`

```ini
APP_TIMEZONE=Asia/Manila     # the database stores Manila time
DB_DATABASE=db_maf_prototype
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=false  # set to true once you serve over HTTPS
```

### Everyday commands

```bash
php artisan test              # the whole suite
php artisan permissions:sync  # after editing config/permissions.php
php artisan migrate:fresh --seed
npm run build                 # production assets
```

---

## 3. Folder structure

Only the parts that differ from a stock Laravel app are listed.

```
app/
  Concerns/Auditable.php         Drop into a model and it logs itself
  Console/Commands/SyncPermissions.php
  Enums/AuditAction.php          The eight audited actions
  Http/
    Controllers/
      Api/V1/                    The token API
      *Controller.php            The web modules
    Middleware/
      HandleInertiaRequests.php  Props shared with every page
      SecurityHeaders.php
    Requests/                    One folder per module; rules live here
    Resources/                   API output shapes
  Models/
  Observers/AuditObserver.php    Create, update and delete logging
  Policies/                      One per model; authorization lives here
  Services/
    AuditService.php             Writes the trail
    DashboardMetrics.php         Cached dashboard figures
  Support/
    ApiResponse.php              The one JSON shape
    ApiExceptions.php            Turns any error into that shape
    MasterInput.php              Tidies a master form before its rules run
    PermissionRegistry.php       Reads config/permissions.php
    Settings.php                 Cached settings reader and writer
    SettingsSchema.php           Reads config/settings.php
    TableQuery.php               Server-side search, sort and paging
    ThemeStyles.php              Theme settings -> CSS variables

config/
  permissions.php                EVERY permission, grouped by module
  settings.php                   EVERY setting, grouped and typed

resources/
  css/app.css                    Design tokens (Tailwind v4 @theme)
  js/
    Components/                  DataTable, form fields, shell pieces
    Layouts/                     AppLayout (signed in), SignInLayout (the
                                 login and kiosk sign-in, GO Enterprises:
                                 the client's mockup, design/login-mockup.png
                                 — header bar with the round logo, green
                                 hero with public/images/login-hero.png,
                                 white card on the right; `goe-*` tokens,
                                 SignInField, SignInIcon, Inter 800;
                                 sized in `--u` so it fits the window),
                                 GuestLayout (error page). All three
                                 carry DeveloperCredit — see "The
                                 developer's credit line" below
    Pages/                       One folder per module
    composables/
    lib/
      swal.js                    Every alert in the app
      charts.js                  ApexCharts, loaded on demand
    navigation.js                The sidebar menu

routes/
  web.php                        The app
  api.php                        /api/v1

tests/Feature/                   The suite is feature tests, by module
```

### The master modules

Phase 6 added five of them. They all follow section 4 below, so read one and
you have read them all. `SupplierController` is the shortest worked example.

| Module | Screens | What it is |
|---|---|---|
| Products | list, detail | The product master: SKU, barcode, brand, model or size, price, stock, reorder level, preferred supplier |
| Categories | list | What a product is grouped under |
| Units | list | What a product is counted in, for example pcs |
| Locations | list | The store, the warehouse and any other place that holds stock (phase 31.1) |
| Suppliers | list, detail | Who goods are bought from |
| Customers | list, detail | Who goods are sold to |

None of them has a create or edit page: both forms open in a dialog on the
list itself. See step 6 below.

Categories and units have no detail screen either: their table already shows
everything they hold.

Three rules run through all five:

- **Stock is never typed in.** `products.on_hand` is outside `$fillable` and
  outside both form requests. It moves only through a posted document, which
  arrives with the ledger in phase 7. The edit form prints it as a locked
  figure.
- **A record in use is switched off, never deleted.** A category holding
  products, a product holding stock and the walk-in customer are all refused
  with a reason the user can read. The foreign keys carry `restrictOnDelete`
  behind that as a backstop.
- **Codes are stored in capitals.** A SKU or a supplier code goes through
  `MasterInput`, because MySQL compares text without regard to case and
  SQLite, which the tests run on, does not. Without it a duplicate would be
  caught in production but not in a test.

The walk-in customer is found by its `is_walk_in` flag, never by its name, so
an admin may rename it to suit the business.

#### A supplier's VAT registration

`suppliers.is_vat_registered` records whether that supplier charges VAT. It
shows as a **VAT / Non-VAT** pill after Phone on the list, and the pill is
the switch: clicking it asks first, the way the Status toggle does.

It is **not** the same thing as `tax.vat_registered` in Settings. That one is
a view and stores nothing. This is a record — a fact about the supplier.

- The route is `PATCH suppliers/{supplier}/vat`, gated by
  **`suppliers.update`**. It is an ordinary edit of a field, so it needs no
  permission of its own and nothing to sync on deploy.
- The pill is the switch rather than a fifth action icon. The table standard
  keeps blue, green, red and orange for View, Edit, Delete and Warning, so a
  new icon would have no colour it is allowed to wear.
- The column is **filtered, never sorted**. An index on a true/false column
  earns nothing, and `PerformanceTest` fails on a sorted column without one.
- `PartyResource` serves customers as well, so the field is sent for
  suppliers only.
- **It changes no figure on any document.** Whether a purchase from a
  Non-VAT supplier should skip the VAT split is a separate decision, and was
  deliberately not taken.

#### Brand, model, barcode and preferred supplier (phase 30)

Four optional fields on the product, added by
`2026_11_20_000001_add_product_details.php`. Every product already on the
books simply has none of them.

| Field | Rule |
|---|---|
| `brand` | Free text, up to 100 characters. The form offers the brands already used (a `<datalist>`, not a pick list), so "Boysen" is not also saved as "BOYSEN". Indexed: the Product Master filters on it, and the API sorts on it. |
| `model` | "Model or size": free text, up to 100 characters — 4 L, 2.0 mm, B-100. |
| `barcode` | Up to 64 printable characters, no space. **One product per barcode** (unique; any number may have none). Stored in capitals through `MasterInput`, for the same reason as a SKU. |
| `preferred_supplier_id` | A **suggestion**, like a purchase request's supplier: active when picked, kept when the supplier is switched off later, **cleared when the supplier is deleted** (`nullOnDelete`) — it never blocks a delete. |

**Finding a product by them.** `Product::searchWords()` joins the brand, the
model and the barcode, and every product list a form reads sends it as
`keywords` beside the label (`sellingOptions()`, `buyingOptions()`, the
adjustment form). `ComboBox` searches the label and the keywords, and on
Enter picks the row whose `barcode` is **exactly** what was typed, so a scan
of "123" never picks the product labelled "41234". A new product list for a
form selects `Product::SEARCH_COLUMNS` and sends `keywords` and `barcode` the
same way.

**At the till** the search box reads the name, SKU, brand, model and barcode
(`lib/scan.js`, `matchesProduct()`). A barcode scanner is a keyboard: it types
the code and presses Enter, and Enter adds the product whose barcode — or,
typed by hand, SKU — is exactly that (`scannedProduct()`), whatever category
tab is open, then empties the box for the next scan. A code nobody has says
so. The tiles show the brand and model under the name.

**On a purchase request** the first line whose product has a preferred
supplier fills in *Preferred supplier* (`lib/usualSupplier.js`), and says so
under the box — until somebody picks a supplier, or *Any supplier*, by hand;
from then on the form leaves their choice alone. A purchase order and a
delivery do not change their supplier by themselves: the supplier is picked
first there.

**The API** sends `barcode`, `brand`, `model` and `preferred_supplier`, and
`GET /api/v1/products?barcode=` finds exactly one product, for a phone that
scanned a label.

No new permission: the fields are part of `products.create` and
`products.update`.

#### Locations (phase 31.1)

The places that hold stock: the store and the warehouse. Shaped exactly like
Departments — the form in a dialog on the list, no detail screen, switched
off rather than deleted once something names one.

| Column | Rule |
|---|---|
| `name` | Required, unique, up to 120 characters |
| `type` | `LocationType`: **Store** or **Warehouse**. Since phase 31.4 the till sells only from a Store |
| `address`, `description` | Optional |
| `is_active` | An inactive location will not be offered on a new document; `Location::options($keep)` still lists the one an old document names |

*Main Store* and *Warehouse* are put in by the migration
(`2026_11_20_000002_record_locations.php`), not a seeder, so a server
deployed without `db:seed` has them. `locations.view/create/update/delete/status`:
**Admin keeps the list, Staff read it.** `data:reset` empties it like every
master.

#### Stock per location (phase 31.2)

Every batch (`inventory_layers.location_id`) and every movement
(`inventory_movements.location_id`) names a location, and `location_stocks`
holds each product's figure at each place. **The total stays on
`products.on_hand`**, which every list and report still reads (since 31.4
the selling and adjusting forms and the reservations read the place's figure);
the ledger writes both, in the same transaction, and nothing else writes
either.

- **Only `post()` takes a location** (`$at`, the default when null). A void
  (`reverseReceipt`, `reverseIssue`), a sales return (`restoreConsumed`) and a
  purchase return (`issueFromLayers`) follow the batches they touch, wherever
  those sit — never the default.
- **A sale eats only its own location's batches**, oldest first, and is
  refused past that location's figure. When the rest sits elsewhere it says
  so: *There is not enough [X] at Main Store: 5 needed, 2 there. 8 more is at
  other locations; move it here first.*
- The product rows stay the one lock. The per-location rows are read with a
  locking read after them and written with their exact new figure.
- `balance_after` on a movement stays the product's total, so the stock card
  and the Movements screen read exactly as before.
- **One location is the default** (`locations.is_default`). Every document
  posts there until phases 31.3 and 31.4 let it name one. The migration made
  **Main Store** the default and put everything already on the books there.
  The default never switches off and is never deleted, and **it does not
  change while it holds stock** — the till would otherwise sell from an empty
  place while the screen still showed the stock. Rename it instead.
- A location **holding stock** is not switched off; one named on any batch or
  movement is **never deleted**. The list reads both with one sub-select each
  (`Location::withUsage()`).
- The Stock screen narrows to one location (*At …* beside *All locations*;
  *In stock* and *Out of stock* ask about that location, low stock stays on
  the total), and the product page and dialog show *By location*.
- `inventory:verify` checks each location against the batches sitting there,
  and their sum against each product's total
  (`InventoryLedger::reconcileLocations()`).

#### Receive at, and Deliver to (phase 31.3)

A delivery says where the goods went on the shelf: **Receive at** on the
purchase form, required, any active location (`purchases.location_id`).
`PurchasePoster` reads it with a shared lock — after the order row, before
the document number — and passes it to the ledger, so the batches and the
location's figure land there. A form sent with no `location_id` key at all
(a tab opened before 31.3) posts at the default; one sent with the box empty
is refused. A void needs no location: the ledger takes the goods out of
wherever their batches sit.

A purchase order may say **Deliver to** (`deliver_to_location_id`,
optional; the form starts on the default; orders placed from an award
deliver to the default). It prints on the order with the location's address,
and only when set — an order from before 31.3 reprints exactly as it did.
**Receive delivery** starts *Receive at* on the order's place, unless that
place was switched off since, else on the default; the receiver may change
it when the truck went elsewhere.

The purchase list shows and filters *Received at*; the purchase page, its
dialog and `GET /api/v1/purchases/{id}` (`location`) say it.

The order says where its goods **really arrived** too (21 November 2026):
its dialog has a **Received at** row — the places of the deliveries that
still stand, a voided one left out — and its page names the place on each
delivery in the Deliveries list. Both read `purchases.location_id`; the
paper is not changed, and *Deliver to* stays what was asked of the
supplier. An order from before 31.3 therefore reads *Deliver to: Not said*
beside *Received at: Warehouse*, and is never edited to fill it in.

#### Sell, return and adjust at a location (phase 31.4)

Every document that moves stock now names its place.

| Document | Where | Column |
|---|---|---|
| Sale | **Sell from** on the sale form, any active location; the till sells from a store | `sales.location_id` |
| Adjustment, opening stock too | **Location** on the form, any active location | `inventory_adjustments.location_id` |
| Sales order | **Hold at** on the form, any active location | `sales_orders.location_id` |
| Sales return | Back into the batches its sale ate: where it was sold (*Back to*) | none — read off the sale |
| Purchase return | Out of the batch its delivery made: where it was received (*Taken from*) | none — read off the delivery |

- **The same three rules as *Receive at*.** A form sent with **no
  `location_id` key at all** (a tab opened before 31.4) posts at the default;
  one sent empty, or naming a location switched off, is refused. The poster
  reads the location with a **shared lock** before the document number —
  `SalePoster::sellingLocation()`, `AdjustmentPoster::location()`,
  `SalesOrderDesk::holdingLocation()` — and hands it to the ledger.
  `matchesSaved()` compares it, so the same form sent twice at another
  place is another document.
- **The forms show that place's figures.** The sale, order and adjustment
  forms start on the default (a sale fulfilling an order, on the order's
  place) and reload their product list with `?location=` when the box
  changes; *on hand* and the stock warnings are that place's. A line asking
  for more than the place holds is refused there, and says how much more is
  at other locations. `Location::startingAt()` is the one rule for where a
  form starts.
- **The till sells only from a store.** It reads `?location=`, keeps only an
  active **Store**, and falls back to the default (or the first store); with
  no store on the list it sells from the default. Two or more stores give
  the header a *Selling from* picker, locked while the basket holds
  anything; the tablet remembers its store in the browser, as a convenience
  only. A till sale returns to `/pos?location=` where it was sold.
- **Reservations are held at a location.** `Reservations::reserved()` takes
  a `locationId`, and `Reservations::atLocation()` reads a place's figures in
  one grouped query. Approving weighs what is free **at the order's place**
  (and refuses a place switched off since); a sale is weighed against what
  orders hold **at its own place**, both in the form request and again in
  `SalePoster` after the ledger has moved the stock. A Main Store
  reservation never blocks a Warehouse sale. The Stock screen shows
  *Reserved* and *Available* for the location chosen (the category column
  gives way); with none chosen they are the totals.
- **Quotations stay location-free**: they reserve nothing. Turned into a
  sale, the sale form says what is short at the place picked.
- The sales and adjustments lists show and filter the place; each page and
  dialog says it; `GET /api/v1/sales/{id}` and `/adjustments/{id}` send
  `location`.
- **A location named only by an order is never deleted**: a pending sales
  order, or a purchase order's *Deliver to*, counts as history
  (`Location::hasHistory()`, and `named_on_orders` on the list).
- The migration set every sale and adjustment already on the books to where
  its first movement happened (the posting, never a void after it), and
  every sales order to the default.

#### Transfers between locations (phase 31.5)

Goods received at the warehouse reach the store through a **transfer**
(Inventory → Transfers, `/transfers`). It is a posted document shaped like
an adjustment: posted on save, never edited or deleted, prefix
`documents.transfer_prefix` (**`TR`**), voided with a reason.

- **One step, no approval.** The goods leave *From* and arrive at *To* in
  the same transaction: there is no *in transit* and nothing to receive at
  the other end. Defaults taken on GO! while decision 4 stays open.
- **The same cost on both sides.** `InventoryLedger::transfer()` takes each
  line out of the source **oldest batch first**, exactly as a sale would
  (*Transfer Out*), and opens **one batch at the destination for each batch
  it ate, at that batch's own cost** (*Transfer In*). Never one averaged
  batch: that would round, and the stock's value would move by centavos.
  A batch is never moved by changing its location. Each new batch names the
  one it was split from (`inventory_layers.transferred_from_layer_id`).
  `products.on_hand` and the stock value do not change; `location_stocks`
  goes down at the source and up at the destination; a later sale at the
  destination is costed from the batches that arrived, in the source's
  order.
- **The form.** *From* starts on the first active warehouse, *To* on the
  default (never the same place); both required, active and different. The
  product list shows what is **free to move at the source** — on hand there,
  less what customer orders hold there — and reloads with `?from=` when the
  box changes. Lines are in **the product's own unit** only, as on an
  adjustment; parts only where the unit allows them. Dated not in the future
  and not before the last posted document.
- **Reserved stock stays put.** `StoreTransferRequest` refuses a line that
  takes what orders hold at the source, and `TransferPoster` asks again
  after the ledger has moved the stock, with a shared lock, and rolls back.
  The two places are read with a shared lock, in id order, before the
  number. Unlike a sale or a delivery there is **no "no key posts at the
  default" rule**: the form is new, so no older tab exists.
- **A void** (`transfers.void`) takes what arrived back off the destination
  (*Transfer Voided (-)*) and puts it back into **the very batches it left**
  (*Transfer Voided (+)*) — only while what arrived is untouched, and never
  when the destination's orders hold it. Once some of it has been sold or
  moved on, the rest goes back with **another transfer**.
- **A delivery moved away.** A purchase return takes from the delivery's own
  batch only, and so does a purchase void. Goods moved to another place by a
  transfer are not in that batch, and a transfer back opens a new batch
  rather than refilling it. So **void the transfer first**, then return or
  void the delivery. The refusal on the purchase return and on the purchase
  void names the transfer (`InventoryLedger::transfersHolding()`), and
  offers the void **only while what it brought to the other place is
  untouched**. Once some of it has been sold or moved on there — a transfer
  back counts — the refusal says plainly that those goods can no longer be
  returned or voided against this delivery. **A known limit**: a purchase
  return that follows a moved batch to its new place is not built; the
  parent link on each moved batch makes it possible later.
- **Permissions**: `transfers.view`, `.create`, `.void`. **Staff view and
  create** — moving goods from the warehouse to the store is daily work and
  changes no total; voiding stays with Admin, like every void.
- The list filters by *From*, *To*, status, staff and date; the dialog and
  page say both places and the value moved; a movement row opens the
  transfer behind it (`DocumentLink`). The Dashboard and Reports do not count
  a transfer as bought, sold or lost; the movement summary shows the pair.
  There is no API endpoint for transfers yet.

#### The count sheet (phase 32)

Stock is counted with a **count sheet** (Inventory → Stock Counts,
`/stock-counts`), one location at a time. A count is **not a posted
document**: it moves nothing itself. Approving it posts the differences as
ordinary adjustments, so the ledger, the losses on the dashboard and every
report read them exactly as they read any adjustment. Prefix
`documents.stock_count_prefix` (**`CNT`**). Built on GO! with these
defaults.

| Table | What it holds |
|---|---|
| `stock_counts` | The sheet: location, optional category, status, the cutoff, who started, handed in, sent back, approved or cancelled it, and the two adjustments it posted |
| `stock_count_lines` | One product each: `system_quantity` at the cutoff, `counted_quantity` (null = not counted, never zero), and once approved the `variance` and its `variance_value` at cost |

- **The steps.** *Counting* → *For approval* → *Approved*, or *Cancelled*
  while open. Its own enum, `StockCountStatus`, never `DocumentStatus`.
  `App\Services\StockCountDesk` is the only place a count changes: one
  transaction per step, the count row locked and its status read again
  under the lock.
- **Starting it takes the cutoff.** Every active product at that location
  (one category, when one is picked), plus anything switched off that is
  still on hand there. The product rows are read with a shared lock — the
  ledger locks them before it writes — so a document being posted is either
  wholly in the figures or wholly after them. **Only one count may be open
  at a location**: `start()` locks the location row and refuses a second,
  naming the open one; the form says so before anybody presses Start.
- **Counting is the one edit allowed**, because nothing is posted yet.
  Figures are saved in batches (`PUT /stock-counts/{id}/counts`, the lines
  changed since the last save), each with who counted it. Quantities follow
  the usual rules — up to three decimals, parts only where the unit allows
  them, never below zero. **An empty figure is "not counted", not zero**;
  type 0 only for an empty shelf.
- **Blind on the sheet.** While a count is open, somebody who may not
  approve it is sent **no book figure on the count** — not the cutoff
  figure, not the difference, not the summary of over and short. The server
  leaves them out of the payload. The approver sees everything; once a count
  is finished, everybody does (the adjustments show the same). **Only the
  sheet is blind**: Staff still hold `stock.view`, so the Stock screen and
  the till show on hand during a count. Hiding those too would be a separate
  decision. **The printed sheet**
  (`Components/CountSheetPrint.vue`, through the letterhead) never carries a
  book figure or a counted one, whoever prints it — `tests/js` guards it.
- **Handing in** needs at least one counted line. The lines left empty stay
  as the books have them when it is approved.
- **Approving** (`stock_counts.approve`) posts, dated today, at the count's
  location, reason *count*: one **increase** for what was over — at each
  product's cost now — and one **decrease** for what was short, off the
  oldest batches like any loss. Nothing different posts nothing, and the
  count is still approved. Each line's `variance` and `variance_value` are
  copied back from the costed adjustment lines. Approving one's own count is
  allowed and the trail says *their own*, as with sales orders.
- **The cutoff rule.** The difference posted is **counted less the figure at
  the cutoff**, never counted less what is there now: a sale after the
  cutoff is already on the books, so it is not taken off twice and the till
  never has to stop. What the system cannot know is whether a sale happened
  before or after somebody counted that shelf, so while a count is open the
  approver sees each line's **change since the cutoff** (what is there now
  less the cutoff figure) and a *Moved since cutoff* filter. **Count soon
  after starting**, before the shop opens if possible. A shortfall bigger
  than the location holds by then is refused by the ledger; the approval
  rolls back whole and the count keeps waiting.
- **Send back** (with a reason) returns it to *Counting* with every figure
  kept, so only the lines in doubt are counted again. **Cancel** (with a
  reason) posts nothing and frees the location: the approver any open count,
  a counter only the one they started.
- **Permissions**: `stock_counts.view`, `.create`, `.approve`. **Staff view,
  start, count, hand in and cancel their own**; approving — which posts a
  decrease, the quietest way to make a loss disappear — stays with Admin, as
  recording an adjustment does. No new dashboard card.
- A product or a location named on a count is never deleted
  (`ProductPolicy`, `Location::hasHistory()`). **A full count lists every
  active product**, so after the first one any product that was active then
  — even one entered by mistake that never moved — can only be switched off,
  never deleted. Any count, a cancelled one too, also stops `sample:clear`,
  like every other document of the user's.
- **Losses.** A count's decrease is an adjustment with the reason *count*,
  so the dashboard and Reports count it under Losses, as before. The list filters by location,
  status, date and who started it, and opens a dialog with the lines that
  differ (`StockCountModal.vue`, from the same `detail()` as the page). No
  API endpoint yet. Adjustments remain for a one-off correction; the count
  sheet is for counting.

#### Loss reports (phase 33)

A loss — goods **damaged, expired, rusted, stolen, missing** or no longer
sellable — is reported on **Inventory → Loss Reports** (`/loss-reports`),
with photos, and written off **only once an approver approves it**. A
report is not a posted document; approving it posts an ordinary
**decrease adjustment** with the report's reason, at its location, so the
ledger, the Losses on the dashboard and every report read it as any
write-off. Prefix `documents.loss_report_prefix` (**`LR`**). Built on GO!
with these defaults.

| Table | What it holds |
|---|---|
| `loss_reports` | Location, reason, status, who reported it, the answer (who, when, the note or reason), the withdrawal, and the adjustment it posted with its cost |
| `loss_report_lines` | One product each, in its own unit; the cost written back once approved |
| `loss_report_photos` | The evidence: a path on the **private** disk, never a public address |

- **The steps.** *Awaiting approval* → *Written off* (approved), or
  *Rejected*, or *Withdrawn*. Its own enum, `LossReportStatus`, never
  `DocumentStatus`. `App\Services\LossReportDesk` is the only place a
  report changes: one transaction per step, the row locked and its status
  read again.
- **Held aside, not moved.** While a report waits, its goods stay at their
  location and on the books, but **nobody may sell, move or reserve them**:
  `Reservations::reserved()` adds pending loss lines to what customer orders
  hold, so the sale form, the till, transfers and sales orders all refuse
  them already. A hold has no date: it lasts until the report is answered.
  `Reservations::heldForLosses()` reads them alone, so every refusal says
  which is which ("3 can held for a loss report"), and the Stock screen's
  **Reserved or held** column splits them on hover. **There is no separate
  holding location** — the goods are set aside where they are; a physical
  damaged-goods place would be a decision of its own.
- **Reporting** locks the product rows (ascending), then the location with
  a shared lock, then reads what is free there with a locking read, then
  takes the number — the order `SalesOrderDesk::reserve()` uses. Only what
  is free may be reported: on hand at the place, less what orders and other
  reports hold. Lines are in the product's own unit; parts only where the
  unit allows them. Dated today.
- **The reasons** are one list, `StoreAdjustmentRequest::REASONS`; phase 33
  added **Expired**, **Rusted or corroded** and **Theft**, and relabelled
  *Lost or stolen* as **Lost or missing** (the key `lost` is unchanged, so
  old adjustments read the new label). A report offers `LOSS_REASONS` — not
  *count*, not *opening stock* — and its write-off posts with the same key.
- **Photos as evidence.** Up to 5, JPG, PNG or WebP up to 4 MB, shrunk to
  1,600 px by `ImageFile` (turned upright; a JPEG, what a phone takes, is
  always rewritten, dropping the camera's notes); SVG refused. **The server's
  PHP must allow it**: `upload_max_filesize` at least `4M` and
  `post_max_size` at least `25M` (five photos and the form). Below that a
  photo fails with *could not be uploaded … try a smaller one*. Shrinking in
  the browser before upload would lift that limit; it is not built. `App\Support\LossPhoto` is the only place one is written or
  deleted. They sit in **`storage/app/private/losses`**, and are served only
  by `GET /loss-reports/{id}/photos/{photo}` to somebody holding
  `losses.view`, reading the path from the report's own photo row — never a
  name from the address. **A photo is required** for *damaged*, *expired*,
  *rusted* and *written off* while Settings → **Losses** → *A photo is
  required…* is on (the default); a theft or a missing item never needs one.
- **Approving** (`losses.approve`) writes it off today at the report's
  location, the oldest batches first, and copies each line's cost back. The
  write-off does not look at holds — the shelf is what it is — so when the
  goods have gone meanwhile (a purchase return or a void does not look at
  holds either) the ledger refuses, and the report keeps waiting. Approving
  one's own is allowed; the trail says *their own*.
- **Rejecting** (with a reason) and **withdrawing** (with a reason; the
  reporter their own, an approver any) free the goods at once.
- **A count and a held loss.** Held goods are still on the books, so a count
  started while a report waits includes them in its cutoff figure — **count
  the set-aside goods too**. Then the write-off and the count each take them
  off once, never twice.
- **Who.** `losses.view`, `losses.create`, `losses.approve`. **Staff view,
  report and withdraw their own; writing off stays with Admin.** Admin can
  still post a decrease adjustment directly: the approval rule applies to
  everyone who reports a loss, and Admin is the approver.
- A product or a location named on a report is never deleted. `data:reset`
  empties the three tables and deletes the photos by name; a **backup
  carries them** under `private/losses/` (the same *include files* switch).
  The list filters by location, reason, status, date and who reported it,
  and opens a dialog with the lines and photos (`LossReportModal.vue`, from
  the same `detail()` as the page). No dashboard card and no API endpoint
  yet.

#### Product photos

A product may carry one picture. It was the first file upload in the
system, and set the pattern the company logo follows (section 5).

- **The column holds a path, not the picture.** `products.photo_path` points
  into the `public` disk; the file sits in `storage/app/public/products` and
  is served through the storage link. `App\Support\ProductPhoto` is the only
  place a photo is written, shrunk or deleted.
- **A database export does not carry the photos.** They are files.
  `storage/app/public` has to be backed up as well, or a restore brings back
  every product with a broken picture.
- **JPG, PNG or WebP, up to 2 MB. No SVG** — it is XML, it can carry script,
  and it would be served from this system's own domain.
- **A large photograph is shrunk on the way in**, to 1200 pixels on its
  longest edge, using GD rather than a Composer package: this system is
  deployed by uploading files by hand, so a new `vendor/` is a cost every
  release. Without it the counter tablet would download thirty four-megabyte
  photographs to draw one screen of tiles.
- **A photograph taken on a phone is turned upright.** A phone saves a
  portrait shot as landscape pixels plus an EXIF note saying "turn this". A
  browser obeys that note and GD does not, so without this the dialog
  preview would be upright and the saved copy on its side. Writing the file
  out again is also what drops the EXIF block, and with it the place the
  photograph was taken.
- **Anything GD on the server cannot do is left alone.** A WebP on a GD
  built without WebP support, or a picture too large to open inside
  `memory_limit`, is stored exactly as it arrived. Running out of memory is
  a fatal error, and by then the file is already on disk, so it would leave
  a picture with no product. Keeping it full size costs one download of at
  most 2 MB.
- **`photoUrl()` returns a path from the site root**, not an absolute
  address. `Storage::url()` builds that from `APP_URL`, and an `APP_URL` left
  at its default breaks every picture on every screen while the rest of the
  system carries on working. `ProductResource` makes it absolute for the API,
  from the host the request arrived on, because a phone cannot resolve a path.
- **An edit carrying a file is posted with `_method: 'put'`.** PHP does not
  read an uploaded file on a PUT. `useFormModal` switches only when the form
  really holds one, so the four dialogs that upload nothing keep the plain
  PUT they have always used.
- Sending no file on an edit leaves the photo alone. Taking one off has to be
  said out loud, which is what `remove_photo` is for.

Photos show on the product list, the product dialog and page, the Stock
screen, and the till — where all the tiles carry a picture or none of them
do, so the grid a cashier aims at keeps one steady shape.

### Sample data

The master screens start empty. To look at them with real rows in them:

```bash
php artisan db:seed --class=SampleDataSeeder   # 2 categories, 6 products, 4 suppliers
php artisan sample:clear                       # take them away again
```

`SampleDataSeeder` is **not** called from `DatabaseSeeder`, so a normal setup
never plants invented products. It matches on SKU, code and name, so running
it twice adds nothing and overwrites no edit made on the screens.

`sample:clear` removes by SKU and code, so a sample row you have since edited
into one of your own products goes with it. Clear the samples before you
start entering real stock. The command prints what it will remove and asks
first.

The sample SKUs come from the design mock-up, so they may well be your real
SKUs too. The duplicate rule would then refuse your own entry, which is the
other reason to clear them first.

The stock arrives the way all stock does: the seeder posts one opening
adjustment, which opens a FIFO batch for each product. Nothing writes
`products.on_hand`.

`sample:clear` exists because a product holding stock and carrying a ledger
cannot be deleted from the screens, and the adjustment screens do not arrive
until phase 10. It unwinds the movements, batches and the opening document
before it removes the products.

### Demonstration data

A year of trading, so the dashboard and the reports have something to show:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder
php artisan inventory:verify
```

About thirty products, twelve suppliers, forty customers, and roughly nine
hundred documents spread over twelve months — purchases, sales, both kinds
of return, adjustments, and a couple of voided rows so the lists show what a
voided document looks like. Costs drift from delivery to delivery, so FIFO
has real batches to work through and the margin is not identical on every
sale.

Two things about it are deliberate, and are what make it worth having.

**It posts, it does not insert.** Every document goes through the same
poster a screen uses, so the batches are real, the costs are what was really
paid, and the ledger balances. Writing rows straight into the tables would
take seconds instead of a minute and would produce figures that look right
and are not. `inventory:verify` is the check that it was done properly —
run it afterwards, every time.

**It cannot backdate, so it only runs on an empty ledger.** The ledger
refuses a document dated before the last one posted, because FIFO eats
batches in posting order. History can therefore only be built forwards from
nothing, which is why the seeder refuses a database that already has
movements rather than failing halfway through and leaving a mess.

One consequence worth knowing if you change it: **a void reverses stock on
the day it is voided**, not on the document's own date. Voiding a document
from last November would write a movement dated today and every later
document in the run would then be refused. That is why the voided rows are
made last, on today's date.

Like `SampleDataSeeder`, it is **not** called from `DatabaseSeeder`. A real
setup must never plant invented sales. There is no `demo:clear` either —
start it over with `migrate:fresh`, which is the only honest way to remove a
year of interlocking documents.

### Starting the real books

When a business has been trying the system out and now wants to enter its
own data, `migrate:fresh` is the wrong tool: it would also take the user
accounts, the roles somebody set up and every setting. Use this instead:

```bash
php artisan data:reset      # shows what it will empty, then asks you to type RESET
```

| Emptied | Kept |
|---|---|
| Every document: purchases, sales, both returns, adjustments, with their lines and a sale's payments; customer payments against invoices; supplier payments against bills; purchase requests and orders; quotations and sales orders; and the sales on hold at the till | Users, roles and permissions |
| The ledger: batches, movements, what each sale drew from each batch | Every setting, including the company logo |
| Products, categories, units, suppliers, customers | The payment methods — set-up, like the settings |
| | Sessions and API tokens |
| The document counters, so numbers start again at `0001` | |
| The audit trail | |

Afterwards it puts back **one row: the walk-in customer** the till sells to,
through `MasterDataSeeder::walkInCustomer()`. Without it a counter sale has
no buyer, and `is_walk_in` cannot be set from the screens. The six standard
units are deliberately **not** put back — the business enters its own — so
add the units first: a product cannot be saved without one. The departments
(phase 24.1) go too; enter the real ones before the first purchase request.
The locations go too, but one is put back, as the walk-in customer is: a
default **Main Store**, because the ledger cannot post without a default
location (phase 31.2). Rename it to the real store on the Locations screen and
add the warehouse before the opening stock. Count each place separately: an
opening-stock adjustment names its **Location** (phase 31.4), and goods move
between places afterwards only through a transfer (phase 31.5). After go-live,
the shelves are counted with a count sheet (phase 32), never by typing over a
figure.
(The method
uses `forceCreate`, because `is_walk_in` is not fillable and a plain
`create` outside `db:seed` would drop the flag.)

How it behaves, and why:

- **The tables are named in the command, never looked up.** On MySQL,
  Laravel 12's `Schema::getTableListing()` returns the tables of *every*
  database on the server. `ResetBusinessDataTest` fails when a migration
  adds a table that is in neither `WIPED` nor `KEPT`, so a new table has to
  be placed on purpose.
- **All or nothing.** The rows are deleted in one transaction, children
  before parents. The id counters are put back to 1 afterwards, because on
  MySQL that is a schema change and would commit the transaction early.
- **Only the photos the products named** are deleted, not the whole
  `products/` folder. The disk is not tied to the database: run against a
  copy of the data, a folder sweep would delete the real photos. (It did
  once, while this command was being tested.)
- It clears the dashboard's cached figures, writes one audit row saying
  what was reset, and runs `inventory:verify`.

Back up the database **and** `storage/app/public` (and
`storage/app/private/losses`, the loss evidence) first. Run it while
nobody is using the system; `php artisan down` first is the safe way.

For GO Enterprises, a hardware and electrical supplies shop, the units come
next, in one step:

```bash
php artisan db:seed --class=HardwareUnitSeeder       # 26 units: pcs, bag, length, roll, gal, cu.m … (kg, m, ltr, cu.m, sq.m allow decimals)
php artisan db:seed --class=HardwareCategorySeeder   # 25 categories: Wires & Cables, Plumbing, Lighting …
php artisan db:seed --class=HardwareProductSeeder    # 108 starter products, no stock
php artisan db:seed --class=HardwareProductPhotoSeeder   # a "SAMPLE PHOTO" tile for each
php artisan db:seed --class=HardwareOpeningStockSeeder   # SAMPLE stock, to try the till
php artisan db:seed --class=HardwareSupplierSeeder       # 10 SAMPLE suppliers, 6 VAT and 4 Non-VAT
```

`HardwareSupplierSeeder` adds ten **invented** suppliers (`.test` e-mail
addresses, 0917 000 01xx phone numbers, 000-000-0xx-00000 TINs), so a
delivery can be tried from both a VAT and a Non-VAT supplier. It matches on
the code. `data:reset` removes them before go-live.

The sample photos are tiles — a gradient in the category's colour, an icon
for the product, a yellow hang tag with its size ("40 kg", "3/8"", "9 W")
and a "SAMPLE PHOTO" badge — kept in `database/seeders/photos/hardware`,
one WebP per SKU. Only a product with no photo gets one, so a real photo
uploaded on the Edit dialog is never replaced. They were drawn once with
headless Chrome from the app's own Lucide icons; nothing on the server draws
them.

The products carry **estimated** prices — typical Philippine retail, no
brand named — for the owner to check before the first sale. They add no
stock: the opening stock still goes in through Adjustments.

`HardwareOpeningStockSeeder` is the exception, and it is **sample stock
only**, for trying a sale before the shelves are counted. It posts one
Opening stock adjustment through `AdjustmentPoster`, as the Adjustments
screen would: four times each product's reorder level, at the product's own
cost, except eight products left at half their reorder level so the Low
Stock panel has rows. Only a product that has never held stock gets any, so
a second run adds nothing. **Run `data:reset` before go-live** — it removes
this stock and every sale tried against it — and then enter the real
opening stock.

They match on the unit's code and the category's name, so running them twice
adds nothing and keeps anything renamed, re-described or switched off on the
screen. Neither is called from `DatabaseSeeder`: another business would want
another list.

### The inventory ledger

Phase 7 built the engine every document from here on posts through. There is
one way stock moves, and `App\Services\InventoryLedger` is it.

| Table | What it holds |
|---|---|
| `inventory_layers` | Each batch taken in, what it cost, and how much is left |
| `inventory_movements` | The stock card: every move, with the running balance |
| `inventory_layer_consumptions` | Which batches each outgoing move ate |
| `inventory_adjustments` | Opening stock, breakages, recounts (screens in phase 10) |
| `stock_transfers`, `stock_transfer_lines` | Stock moved from one location to another (phase 31.5) |
| `stock_counts`, `stock_count_lines` | Count sheets (phase 32). Not in the ledger: an approved count posts adjustments |
| `loss_reports`, `loss_report_lines`, `loss_report_photos` | Losses waiting for approval (phase 33). A pending one holds its goods aside; approved, it posts a decrease adjustment |
| `location_stocks` | Each product's stock at each location (phase 31.2), beside the total on `products.on_hand` |
| `document_sequences` | One locked counter per prefix per year |

```php
app(InventoryLedger::class)->post(
    $document,                 // what is being posted, for a trail back
    'PR-2026-0001',
    $date,
    MovementType::Purchase,
    [['product_id' => 1, 'quantity' => 100, 'unit_cost' => 300]],
);
```

Four rules it keeps, all inside one transaction:

- **Stock never goes negative.** A line asking for more than the batches hold
  is refused and the whole document rolls back, including the lines that were
  fine.
- **A document posts once.** Movements already written against it mean it has
  been posted.
- **No backdating.** Costs come from the oldest batch first, in posting order,
  so a document dated before the last one would eat the wrong batches.
- **`on_hand` equals the sum of its batches, always.** Every ledger test ends
  by checking it, and `php artisan inventory:verify` checks it on demand.

FIFO is the whole of it: an incoming move opens a batch, an outgoing one eats
the oldest batches still holding something and records exactly which. That
receipt is what lets a sales return put stock back at the cost it left at.

#### Decimal quantities

Phase 14 let a quantity carry up to **three decimals** — 2.5 m of wire,
0.125 kg of nails — but only where the product's unit allows it. Each unit
has an **Allows decimals** switch on the Units screen; the hardware seeder
turns it on for kg, m, ltr, cu.m and sq.m and leaves everything else whole.
A product follows its unit, so 2.5 pcs is refused on every form, against the
line that asked for it, and again in the ledger.

| Where | What it does |
|---|---|
| Every quantity column | `DECIMAL(15,3)`. 10 is stored as 10.000 |
| `App\Support\Quantity` | All quantity arithmetic, in **whole thousandths** (2.5 is 2500), the way VAT is worked in centavos |
| `App\Casts\QuantityCast` | Reads 10.000 as the int 10 and 2.500 as 2.5, so a whole number still reads 10 everywhere |
| `Quantity::lineTotal()` | Quantity × price, half up to the centavo: 2.5 × ₱18.35 = ₱45.88 |
| `money.js` | `count()`, `toMilli()`, `lineTotal()` and `quantityStep()` — the same figures while somebody types |

Three things to keep in mind when touching a quantity:

- **Never `(int)` one, and never add two as they arrive.** `(int) '2.5'` is 2,
  and 0.1 + 0.2 in floats is 0.30000000000000004. Go through
  `Quantity::toMilli()`, add the integers, and come back with `toDb()` or
  `normalize()`.
- **A raw query skips the cast.** MySQL sends a DECIMAL as `"10.000"` and
  SQLite as a float, so a `SUM()` or a `DB::table()` read goes through
  `Quantity::normalize()` before it reaches a screen.
- **Write the exact figure, not an `increment()`.** SQLite adds a DECIMAL as a
  float, so the ledger reads a batch under its lock and writes the new
  figure it worked out.

A unit's switch cannot be turned off, and a product cannot be moved onto a
whole unit, while it holds a part — 0.5 pcs could never be sold or adjusted
away.

#### Other units: the box and the piece

Phase 15 lets a product be bought and sold in other units — a box of 100
screws, a roll of 75 m of wire — while **the stock stays counted in the
product's own unit**. The product form has an *Other units* section: the
unit, how many it holds, and an optional own price. Left empty, the box
sells at the piece price × the number it holds.

| | What is typed | What moves |
|---|---|---|
| Sale | 1 box at 450.00 | 100 pcs off the shelf; the line totals 450.00 |
| Purchase | 5 box at 400.00 | 500 pcs onto the shelf at 4.00 each |
| Sales return | 30 pcs | 30 pcs back, refunded 135.00 (30/100 of 450.00) |
| Adjustment | 3 | 3 pcs — an adjustment counts in the product's own unit only |

- **One place converts.** `App\Support\UnitConversion::resolve()` turns
  each line into the product's own unit. The form request uses it to put an
  error against the line; the poster uses it again inside the transaction
  and is the one that decides. The factor comes from `product_units`, never
  from the form.
- **The line keeps both.** `quantity` is always in the product's own unit,
  so the ledger, `returnable()`, the reports and `inventory:verify` read
  what they always read. `unit_quantity`, `unit_id` and `unit_factor` say
  what was typed; `unit_id` is null for the product's own unit. The factor
  is copied onto the line, so changing a box from 100 to 50 never rewrites
  an old document.
- **A purchase line holds two prices.** `unit_price` is per typed unit
  (400.00 a box). `unit_cost` is per piece (4.00) — the line total over the
  pieces, half up to the centavo — and is what the batch, the product's
  cost and a purchase return use.
- **Rounding.** A box of 12 bought at 100.00 is 8.33 a piece, so the batch is
  worth 99.96. A purchase return of such a line gives back its share of
  the 100.00, and the last pieces back take whatever is left, so returning
  the whole box gives back exactly 100.00.
- **Refused:** a unit the product is not sold in; a part of a unit counted
  whole (1.5 box); and a line that comes to half a piece (1.1 doz = 13.2
  pcs). A unit used as another unit, or named on a document line, cannot
  be deleted.
- **The product form sends its whole list every time.** A missing list
  means none — a multipart post with a photo leaves an empty list out — so
  any product form must send `units` or it will take them all off.

Product rows are locked in **ascending id order**. Two documents sharing
products could otherwise take each other's locks the opposite way round and
deadlock. The tests run on SQLite, which takes one writer at a time, so they
prove the arithmetic but not the locking — MySQL is the real guard.

Document numbers come from `DocumentNumber`, whose prefix, padding and yearly
reset are settings, so a business that numbers differently changes a box
rather than the code.

**A poster takes its number first, then calls the ledger.** That fixes the
order every document locks in — the sequence row, then the products in
ascending id order — so two posters can never take the same two locks the
opposite way round. Follow `AdjustmentPoster` when phase 8 adds purchases,
and wrap the outermost transaction in `attempts: 3`: the first document of a
year has to create its counter row, and two of those at once deadlock on
MySQL rather than failing cleanly.

A document carries `use Auditable;` and writes one Post row. Its lines,
batches and movements carry none — one purchase of ten lines must read as one
line in the trail, not eleven.

### Documents

Phase 8 built the first one. Sales and returns follow its shape exactly.

| Piece | What it does |
|---|---|
| `PurchaseController` | No `edit`, `update` or `destroy` — not gated, absent |
| `PurchasePoster` | Writes the document and posts it, in one transaction |
| `StorePurchaseRequest` | Every rule, including the date floor and ceiling |
| `Components/LineItems.vue` | The lines of any document; sales reuse it |

A document is **posted the moment it is saved** and never edited. A mistake
is put right two ways:

Voiding works both ways, and the ledger has a method for each:

- `reverseReceipt()` undoes an **incoming** document — a purchase. It takes
  back that document's own batches, never the oldest in the warehouse.
- `reverseIssue()` undoes an **outgoing** one — a sale. It reads the receipts
  the sale left and puts every unit back into the batch it actually came out
  of, at that batch's cost. No new batch is opened, so FIFO keeps its order.
  The trade-off, stated plainly: voiding an old sale returns stock to an old
  batch, so the next sale is costed from it again. That is truer to what
  happened than inventing a fresh batch would be.

- **Void**, while every batch it brought in is untouched. The ledger takes
  back *that document's own batches*, not the oldest in the warehouse, so it
  cannot eat another delivery's stock. It locks in this order: the document
  row, then its products in ascending id order, then its batches — the same
  product-first order posting uses, so the two can never deadlock. The
  product's cost falls back to the newest price that still stands.
  A void is dated today and has to say why.
- **Return**, once some of it has moved. That arrives in phase 10.

#### VAT

`tax.vat_registered` and `tax.vat_rate` switch every purchase, sale and
return between one plain amount and the VAT split. `App\Support\Vat` is the
only place the sum is worked out, with `vatSplit()` in
`resources/js/lib/money.js` drawing the same figures while somebody types.

- **The two sides follow different things.** Selling asks *do we charge
  VAT*: that is the Settings switch, a **view** over sales, sales returns
  and the till — every one follows it, old documents included, nothing is
  stored, and turning it off puts the screens back. Buying asks *did the
  supplier charge VAT*: that is the supplier's own flag, stamped onto
  `purchases.vat_rate` when the delivery is posted, a **record** that does
  not move. So a purchase ignores the switch: a VAT supplier splits with it
  off, and a Non-VAT supplier does not split with it on. You cannot claim
  input VAT from a supplier who charges none. A purchase return follows the
  delivery it undoes.
- `Vat::currentRate()` is the selling rate and is 0 when the switch is off.
  `Vat::rate()` is the configured rate whatever the switch says, and only
  the buying side reads it.
- **Every price typed in is VAT-inclusive.** The shelf price and the
  supplier's invoice are what changes hands, so the tax comes *out* of a
  total, never on top: `net = total ÷ 1.12`, and the VAT is what is left.
- **Both sides work in whole centavos.** Dividing floats put PHP and the
  browser one centavo apart whenever the net landed on exactly half a
  centavo: ₱42.14 gave 37.63 on the server and 37.62 on the screen. The rate
  is carried as basis points and the halving is done inside the fraction.
  `VatTest` and `tests/js/vatSplit.test.js` both hold that case.
- **Split the line total, not the unit price.** 12 × ₱120 splits to
  ₱1,285.71; splitting ₱120 first and multiplying gives ₱1,285.68.
- **A sale splits what was charged, after the discount.** So the line splits
  do not add up to the footer when a discount was given. The footer is the
  figure that counts: VAT follows the money.
- **The lines may land a centavo off the footer.** Each line is split and
  rounded on its own, the footer is split from the document total, and the
  two need not meet: PR-2026-0043's three lines add to ₱26,804.28 against a
  footer of ₱26,804.29. The footer is the figure that counts.
- **A purchase return splits its cost**, which is the only value it has.
- **An adjustment never splits.** Nothing was bought or sold.
- The till posts to `/sales` like the form does, so it splits too.

What VAT deliberately does **not** touch: the FIFO layers, the stock
valuation, the cost of goods sold, the margin, the Dashboard and the
Reports. All of those still carry VAT. Making them net of it is a change to
`InventoryLedger`, `InventoryValuation` and `InventoryMetrics`, and it was
not asked for.

### Payments at the till

Phase 16. A sale records **how it was paid**: cash, GCash, a bank transfer,
or several at once. Both sale screens take the payment the same way, through
`Components/PaymentEntry.vue`: the till opens it in a dialog when the
cashier taps **Take payment**, and the New sale form has a Payment card.

| Piece | What it does |
|---|---|
| `payment_methods` | The list the owner keeps under **Payment Methods** in the sidebar (`payment_methods.*` permissions). Cash, GCash, Maya and Bank transfer arrive with the migration, not a seeder, so a server deployed without `db:seed` still has them |
| `sale_payments` | One row per way a sale was paid. `amount` is what that payment put towards the sale, so a sale's rows add up to its total less whatever went on account (see "Selling on credit"). `tendered` is what was handed over, on the cash row only; `reference` is the GCash or bank number |
| `App\Support\PaymentSettlement` | The only place a payment is weighed against a sale. The form request asks it so the error lands under the right box; `SalePoster` asks it again inside the transaction and decides |
| `App\Support\SaleTotals` | What a sale comes to, in whole centavos. The request, the poster and the settlement all read it, so the total the cashier collects is the total stored |
| `resources/js/lib/payments.js` | `settle()` draws the same sums while somebody types, in centavos too, so the change on screen is the change stored |

The rules, for a sale of ₱1,850:

- **Cash may be more than is owed.** ₱2,000 handed over stores Cash ₱1,850,
  received ₱2,000, and ₱150 change. The till says the change again, large,
  once the sale is in, because it is what the cashier counts out.
- **Nothing else may.** Nobody gives change from GCash, so the other
  payments together may not come to more than the sale.
- **Split:** ₱1,000 GCash and ₱1,000 cash stores GCash ₱1,000 and Cash ₱850
  (received ₱1,000), ₱150 change.
- **One cash payment per sale.** Two would leave nobody sure which one the
  change came from.
- A method marked **Needs a reference number** will not post without it.
- **There is exactly one cash method**, and it can be renamed but never
  turned into another kind, switched off or deleted: the till gives change
  from it. A method any sale was paid with cannot be deleted — switch it off.
- A sale sent **without any `payments` key** is recorded as paid exactly in
  cash, which is what every sale was taken to be before phase 16. That keeps
  a browser tab opened before an update from posting a sale nobody paid for.
  An empty list is refused, unless the sale came to nothing (a full discount).
- Every sale already on the books got one Cash row for its total when the
  migration ran, so no figure moved.
- A retry with the same idempotency key counts as the same sale only if the
  payments match too. A corrected GCash number is a new form, not a repeat.

Payments are rows of the sale, like its lines: they carry no `Auditable`, and
the sale's Post row lists them (`paid_by`, `change`). A voided sale's payments
stay on it but count for nothing, like everything else about it.

What is **not** here yet: the cash drawer's opening and closing count
(stage 4) and filtering reports by payment method (stage 9). A refund on a
sales return is cash, for whatever part of it was not owed: see "Selling on
credit".

### Hold, customer and slip at the till

Phase 17, the second half of stage 2. Three things the counter needed, none
of which is a new way to sell: every sale still goes through `POST /sales`,
`StoreSaleRequest` and `SalePoster`.

**Hold and recall.** A customer goes back for one more bag of cement; the
cashier taps **Hold**, types a note if it helps ("Mang Ben, truck"), and
serves the next person. The sale waits under the **Held** tab, with who held
it and when, on every till — the customer comes back to the counter, not to
a person. **Recall** puts it back on the counter; something already there is
held first, after asking.

| Piece | What it does |
|---|---|
| `held_sales` | One row per parked basket: customer, note, discount, the total when it was held, and `lines` as JSON (`product_id`, `unit_id`, `quantity` as text, `unit_price`), `held_by`. **Not a document**: no reference number, no stock moved, nothing reserved. It is in `data:reset`'s `WIPED` list |
| `HeldSaleController` | `POST pos/holds`, `POST pos/holds/{id}/recall`, `DELETE pos/holds/{id}`, all behind `permission:kiosk.use` like the till, and behind `sales.create` in the request and the controller. No new permission |
| `resources/js/lib/holds.js` | `rebuildHold()` lays a recalled hold against the shelf **as it is now** |

The rules, and why:

- **A hold is a basket, not a sale.** A `held` status on `sales` was the
  other choice and was turned down: the row would take a number, show in the
  Sales list, the day's count and the reports, and the void and return rules
  would all have to step round it. Reserving stock for an order is stage 7.
- **Recall claims the row by deleting it**, with a plain delete on the key.
  Two tablets tapping Recall on the same hold both find it, but only one
  delete removes a row; the other is told it has gone. The trail gets one
  row saying *Recalled* or *Discarded* — the controller writes it, because a
  query delete fires no Eloquent event.
- **What comes back is checked against today.** Today's price, not the
  price when it was held; a product switched off, or a unit it is no longer
  sold in, is left off; a quantity past the shelf is cut to what is left (a
  box stays whole); a customer switched off goes back to Walk-in. Each change
  is said in one warning before the money is taken. The server checks the
  shelf again when the sale posts, as it always does.
- **JSON lines, not a child table**, so a hold never stops a product or a
  unit from being deleted. A line whose product has gone is dropped on
  recall.
- **At most 50 holds** (`HeldSale::MAX_OPEN`). Past that the counter has
  stopped holding and started storing; the request refuses the 51st.

**Choose a customer.** The counter has a **Customer** box, a `ComboBox` over
every active customer with Walk-in first (`Customer::options()`, the same
list the New sale form and the Sales filter use). It starts on Walk-in and
goes back to Walk-in after every sale, hold or clear. Naming a customer does
not put the sale on credit by itself: the cashier has to tap **On account**
(see "Selling on credit").

**Print the slip.** `Components/SaleSlip.vue` draws the customer's copy from
the sale's own `detail()` payload — the one its page and dialog already read
— so there is no new endpoint and nothing to drift. It is printed with
`window.print()` from the page the person is on (`lib/slip.js`):
`SlipPrinter.vue` keeps the slip at the end of `<body>`, hidden on screen,
and while it prints `body.printing-slip` hides everything else on paper. A
hidden frame was not used because the app refuses to be framed
(`X-Frame-Options: DENY`), and a new tab would strand a cashier on a tablet.

| Where | How |
|---|---|
| The till, after a sale with change | **Print slip** on the change alert |
| The till, after any sale | The **Last sale** bar under an empty counter |
| The sale dialog (Sales list, Today tab, the ledger) | **Print slip** beside "Open full page" |
| The sale's own page | **Print slip** in the header |

- **It never prints a cost or a margin.** The payload carries them for
  anybody with `sales.view_cost` — and the till's role usually has it — so
  the slip simply reads no cost field. `tests/js/SaleSlip.test.js` fails if
  one appears.
- **It is not an official receipt, and says so on every slip.** That line is
  written in the component, not taken from Settings, so it cannot be
  cleared: an official receipt comes from a BIR-registered system.
- Settings → Document Numbers holds **Slip paper width** (80 mm or 58 mm)
  and **Slip closing line**. They reach every screen as the shared `slip`
  prop.
- A voided sale prints with *VOIDED* across it. The VAT lines appear only
  while the VAT switch is on, like every other sale screen.
- After a till sale the controller flashes `sale` (id and reference) so the
  till can offer the slip; a recall flashes `recalled`. Both are in the
  shared `flash` props.

### Pending payment: the kiosk takes the order, the cashier takes the money

CR-2, the client's meeting of 24 November 2026; phase 17.1. The kiosk is a
tablet on the shop floor, so it should not take money. Beside **Take
payment** the till has **Pending payment**: the counter is sent to the
cashier as an order numbered `PP-2026-0001` (Settings → Document Numbers),
and the customer carries a slip to the cashier, who takes the money.

| Piece | What it does |
|---|---|
| `pending_payments` / `pending_payment_lines` | The order as sent: customer, store, lines shaped like sale lines (typed quantity, unit, factor, price), discount, total, who sent it. Never edited or deleted |
| `PendingPaymentStatus` | Pending (shown *Awaiting payment*), paid (`sale_id`, unique), cancelled (with a reason). **"Expired" is not stored**: a pending one past `hold_until` reads as expired and holds nothing |
| `App\Services\PendingPaymentDesk` | The only place one is sent, cancelled or marked paid. Sending locks the products (ascending), then the store (shared), reads what is free with locking reads, refuses the whole order if a line is not free, and only then takes the number |
| `Reservations::heldForPendingPayments()` | Its lines are added into `reserved()`, so every sale, till, transfer, sales order and loss report already refuses them. A refusal says *"8 bag waiting at the cashier"* |
| `PendingPaymentPolicy` | **Comes with the kiosk** (the user's GO! of 24 November 2026): send — anyone with `kiosk.use`, nothing else ticked; take the money and cancel anybody's — `sales.create`; cancel one's own — the sender. Only the back-office list needs `pending_payments.view` |
| `OrderSlip.vue` | The customer's slip: *NOT PAID — PAY AT THE CASHIER*, the lines, *TO PAY*, and *This is not an official receipt*, written in the component. No cost |

The rules:

- **The goods are held from the moment it is sent** (Q1), at the till's
  store, until it is paid, cancelled, or closing on the last day of
  `documents.pending_payment_hold_days` — **0, the same day**, by default
  (Q3). Nothing runs at closing: the date alone decides.
- **It is paid exactly as it was sent** (Q2). The cashier's sale goes through
  `POST /sales` with `pending_payment_id`; `StoreSaleRequest` and then
  `SalePoster` — which **locks the pending payment first**, before the
  account, the store and the number — refuse it unless it is still waiting,
  for the same customer, at the same store, with the same products, units,
  quantities, prices and discount. Two cashiers cannot both take the money.
  The sale may take **its own** hold (`exceptPending`), never anybody
  else's. To change anything, cancel it and ring it up again.
- **The cashier takes any payment**: cash with change, GCash, split, or **on
  account** for a customer with credit, with the per-sale terms (phase
  18.1). The sale is posted by the cashier; the order keeps who sent it.
- **On the till**, *Pending payment* sends the counter and offers the slip;
  the **Pending** tab lists what waits at this store, oldest first, with
  *Take payment*, *Print slip* and *Cancel*; it is read again every 30
  seconds. Paying a sent order never touches what is on the counter.
- **Sales → Pending Payments** lists them all, filtered by status, store and
  sender; a row opens in a dialog (`/pending-payments/{id}/preview`, the
  same `detail()` as the page). **The sidebar item carries a red count** of
  the orders still waiting (24 November 2026): the shared `badges` prop
  (`HandleInertiaRequests`, one indexed count, only for `pending_payments.view`)
  read by `NavLink.vue` through the item's `badge` key in `navigation.js`;
  nothing at zero, *99+* past 99, a dot on the icon when the menu is folded.

**Every module with a step waiting on staff carries one** (24 November
2026, on the user's GO!). `App\Support\SidebarBadges` is the only place the
counts are worked out, each **what this person can act on** — an approver
everything waiting for approval, anybody else their own still waiting — and
none for a screen they cannot open:

| Sidebar item | Counts | Approver (all) | Anybody else (their own) |
|---|---|---|---|
| Purchase Requests | pending | `purchase_requests.approve` | `requested_by` |
| Purchase Orders | for approval | `purchase_orders.approve` | `ordered_by` |
| Sales Orders | pending approval | `sales_orders.approve` | `created_by` |
| Pending Payments | awaiting payment | everybody who may open the list | — |
| Stock Counts | handed in for approval | `stock_counts.approve` | their own sheet still being counted |
| Loss Reports | awaiting approval | `losses.approve` | `reported_by` |

Not badged on purpose: Quotations (an open one waits on the customer), and
Collectibles, Aging and Payables (overdue is a warning, on the dashboard).
A new badge is an entry in `SidebarBadges::waiting()` and the same key as
`badge` on its navigation entry.

**The bell lists the same** (24 November 2026). It was a picture with a red
dot that never went away; now `AppTopbar.vue` reads the shared
`notifications` — the items of `SidebarBadges::waiting()` with something in
them — and shows the total in red on the bell, and under it *Waiting for
you*: each module's name and icon (from its sidebar entry), its count, the
words (*"1 purchase request waiting for your approval"*, *"2 of your loss
reports still waiting for approval"*) and a link to the list narrowed to
exactly those rows (`?status=pending`, plus `&requested_by=` for one's own).
Nothing waiting: no red mark, and *Nothing is waiting for you.*
`HandleInertiaRequests` works the list out once per request for both the
badges and the bell.
- **Receive payment in the back office** (the user's GO! of 24 November
  2026): whoever takes the money may sit at a desktop, not at the kiosk —
  and Admin has no kiosk. The green row action opens the order, and
  **Receive payment** there (and on its page) opens the till's own payment
  box (`ReceivePendingPayment.vue`, `PaymentEntry`) and posts the same
  `POST /sales` with `pending_payment_id`, so the same checks decide. It
  needs only `sales.create`. It lands on the new sale and offers its slip.
  `detail()` sends `receive` (methods, today, terms right) only to somebody
  who may use it; the till's list leaves it out. *Open at the till*
  (`/pos?location=…&pending=…`) stays for somebody with the kiosk.
- **Everybody at the kiosk has it.** First built behind a permission of its
  own, the client's *Sales Encoder* role — made on the Roles screen — never
  got it, and the button was missing. On the user's GO! of 24 November 2026
  it was tied to the kiosk instead: `pending_payments.create` and `.cancel`
  were dropped (`permissions:sync --prune` removes them where they ran), so
  every kiosk role, made now or later, shows *Pending payment* beside *Take
  payment* and the *Pending* tab with nothing ticked.
- **A kiosk-only role** (Q4): tick `kiosk.use` and leave `sales.create` off.
  That tablet shows only *Pending payment* — no *Take payment*, no *Hold*, no
  Pending tab beyond its own orders — and `POST /sales` refuses it.
- A product, unit, customer or location named on one is never deleted;
  `data:reset` empties both tables.

### Selling on credit

Phase 18, the first half of stage 3. A customer may be given a **credit
limit** and **payment terms**, and a sale to them may go **on account**,
wholly or in part, on the till and the New sale form.

| Piece | What it does |
|---|---|
| `customers.credit_limit` | How much the customer may owe at once. **Zero means no credit**, never "no limit", so every existing customer starts with none. Walk-in can never have any |
| `customers.payment_terms_days` | Their **usual** terms: where every sale on account starts (phase 18.1). A limit needs at least 1 day |
| `sales.on_account` | The part of the sale not paid at the counter, as it was posted |
| `sales.balance_due` | What is still owed of it. A return takes it down; a void clears it; a customer payment takes it down and its void puts it back (phase 19) |
| `sales.terms_days` | The days agreed on **this** sale (phase 18.1). Null on a sale with nothing on account |
| `sales.due_date` | Sale date plus the days agreed, stamped when it posts. Changing the customer's terms later never moves an old invoice |
| `sales_returns.credited_amount` | How much of a return came off what was owed. The rest went back as cash |
| `App\Support\CustomerCredit` | The only place a sale is weighed against a limit, and the only place a balance is read. The form request asks so the error lands under the box; `SalePoster` asks again with the customer's row locked and what is owed read with a locking read, and decides |
| `PaymentSettlement::settleSale()` | Splits a sale into what goes on account and what is paid now. `settleSale()` in `lib/payments.js` draws the same sums on screen |

The rules, for a sale of ₱1,850:

- **The payment rows stay real money only.** There is deliberately no Credit
  payment method: Collections adds the rows up, and a sale not paid for must
  not count as collected. A sale's rows plus `on_account` come to its total.
- **Credit is always asked for in so many pesos.** A payment that falls short
  is never taken as credit; it stays the ordinary "still to pay" error.
- **No change on a sale on account.** ₱850 on account with ₱1,500 cash is
  refused: put less on account instead.
- **A customer's balance is the sum of their sales' `balance_due`**, in whole
  centavos. No figure is kept on the customer, so there is nothing to drift.
  The index `sales_customer_balance` serves it, so adding up the walk-in
  customer does not read every counter sale.
- **Over the limit is refused**, and the message says the limit, what is owed
  and what is left. Exactly at the limit is allowed. There is no override: who
  may approve a sale over its limit is decision 2 in `SCOPE.md`. Until then,
  somebody with `customers.credit` raises the limit, and that is on the
  audit trail. A limit lowered under what is already owed stops new credit
  and reads as nothing left, never a negative.
- **A return on an unpaid sale comes off the balance first**, and only what is
  left of its value goes back as cash. That is not "customer credit instead
  of a refund" (stage 6): it only stops cash being handed over for goods
  nobody paid for.
- **A void clears the balance.**
- **`customers.credit` sets the limit and terms** (new permission, so a deploy
  runs `permissions:sync`). Admin has it through `*`; other roles get it on
  the Roles screen. An edit without it, or a form that leaves the fields out,
  keeps what the customer has. Anybody who may record a sale may charge an
  account within its limit.
- A retry with the same idempotency key is the same sale only if the amount
  on account matches too, and the days agreed.

**Terms are agreed on each sale** (phase 18.1, the user's GO! of 24
November 2026). Tapping **On account** opens two boxes: **Amount** and
**Terms (days)**. The days start on the customer's usual terms and may be
changed for this sale only; the line under them says the date it falls due,
and the usual terms beside it when they differ.

- **Fewer days, down to 0 (due the day of the sale), is anybody's to give.**
  **More days than usual needs `customers.credit`** — the permission that sets
  the terms themselves — because it is giving more credit.
  `CustomerCredit::termsRefusal()` is the one rule; the form request puts its
  error under the box and `SalePoster` asks again with the customer row
  locked, so usual terms lowered meanwhile are weighed as they are now.
  `termsRefusal()` in `lib/payments.js` draws the same rule on screen. 0 to
  365 days, whole days only.
- **A form that sends no `terms_days`, or an empty box, takes the usual
  terms**, as every sale did before (a stale tab).
- **The days are kept on the sale** (`sales.terms_days`) beside the due date.
  The sale's page, its dialog, the slip (*Terms*), the message after posting
  and the statement line name them. The migration gave every sale already on
  account the days between its date and its due date; no due date moved.
- Aging, overdue and Collectibles already read each sale's own `due_date`, so
  nothing there changed.
- **A customer with no credit is told why.** When the customer picked is not
  Walk-in and has no limit, the payment box says *"… has no credit limit, so
  this sale is paid in full now. To sell on account, set a credit limit under
  Customers."* (`creditHintFor()` in `lib/payments.js`), on the till and the
  sale form.

**The figures.** Net sales counts a sale in full on its date, on account or
not. Collections counts only what was paid at the counter, so the till lists
"On account (not taken)" beside its takings. Net cash from trading takes out
what went on account (`on_account_net` in `periodFigures()`), each line
carrying its share so the Category and Product filters still narrow it.

The sale's page, its dialog and its slip say what went on account and when it
is due. The customer list has a Balance column, sortable both ways since 24
November 2026, and an "Owing only" filter; a
customer's page has the limit, what is owed, what is left, the usual terms
and the unpaid sales, soonest due first.

Collections against invoices, the statement of account, aging, and the
overdue and over-limit flags arrived in phase 19: see the next section.

### Customer payments, the statement and aging

Phase 19, the second half of stage 3. A customer pays what they owe, and the
payment is placed against the invoices it pays, in full or in part.

| Piece | What it does |
|---|---|
| `customer_payments` | The document: customer, date, one payment method, amount, the GCash or bank number (`method_reference`). Its own number, `CP-2026-0001`, from **Customer payment prefix** under Document Numbers. Posted when saved, never edited, voided with a reason |
| `customer_payment_allocations` | Which invoices it paid, and how much of each. Not auditable on its own; the payment's Post row lists them |
| `App\Support\PaymentAllocation` | The only place a payment is weighed against invoices. The form request asks so the error lands under its row; the poster asks again with the rows locked, and decides |
| `CustomerPaymentPoster` | Writes the payment and takes it off each invoice's `balance_due`, in one transaction. A void puts it all back |
| `CustomerCredit` | Still the only place a balance is read, and now every other reading of an account too: `overdue()`, `overdueCustomers()`, `aging()`, `statement()`, `flags()`, `reconcile()` |
| `lib/allocations.js` | `fillOldestFirst()` and `weighAllocations()` draw the same sums while somebody types |

The rules:

- **The invoices add up to the payment exactly.** No invoice takes more than
  it still owes, and money that cannot be placed against an invoice is
  refused: keeping it as customer credit is stage 6.
- **One method per payment**, and a method marked *Needs a reference number*
  takes none without it (`PaymentSettlement::methodRefusal()`, the same rule
  a sale's payments follow). No change is worked out: type what was paid.
- **The date** is not in the future and not before the newest invoice it pays.
  No stock moves, so the posting-order floor does not apply.
- **The form spreads the amount soonest due first.** Any row can be typed
  over by hand; **Spread oldest first** and **Pay everything** fill it again.
- **A sale with a payment against it cannot be voided.** The payment is voided
  first. A return on a part-paid sale still comes off what is owed first,
  and only the rest is cash.
- **Locks, in this order:** the customer, then the sales it pays in ascending
  id order, then the document number. A sale on account locks the customer
  first too, so the two wait for each other. A payment's void locks the
  payment row, then its sales in id order. A sale's void reads the payments
  against it with a locking read.
- **The dashboard's caches are flushed with `DB::afterCommit()`** by the
  poster itself, because it writes `balance_due` outside the ledger.
- **Never called a receipt.** An official or collection receipt is a BIR
  document this system does not issue. Every payment screen, and the printed
  statement, says *not an official receipt*, written in the component.
- **`customer_payments.view`, `.create`, `.void`** are new permissions, so a
  deploy runs `permissions:sync`. Staff get view and create; voiding stays
  with Admin, like every other void. The statement and aging report sit
  behind `customer_payments.view`.
- A method a customer payment used cannot be deleted, like one a sale used.

**The statement of account** (`/customers/{id}/statement`, the **Statement**
button on a customer's page): the balance brought forward, then every sale on
account, every return that came off what was owed, and every payment, with
the balance after each. A quarter by default. A period that runs to today
closes on exactly what the customer owes. It prints on its own, without the
sidebar or top bar (`print:` classes in `AppLayout`), with the aging as of
today underneath.

**Aging** (`/aging`, under Insights): every balance in five buckets as of
today — not yet due (what falls due today included), 1–30, 31–60, 61–90 and
over 90 days past due. The bucket edges are worked out in PHP as dates, so the
SQL is the same on MySQL and SQLite.

**The flags.** *Overdue*: something is past its due date. *Over limit*: owing
more than the limit, which happens only when a limit is lowered under what is
owed. Both show on the customer list (with **Past due** and **Over the limit**
in the Balance filter), the customer's page, the statement and the aging
report. The till and the sale form say how much is past due under **On
account**, and still let the sale through within the limit: refusing an
overdue customer is not something the client has decided.

**The figures.** Collections now adds what customers paid against invoices,
on the day the money came in, by method, in the same query as the sale
payments. Net cash from trading adds it too. Narrowed to a category or a
product, both leave it out: one payment cannot be split between goods. The
till's day list leaves it out, because it adds up to the till's own sales;
the drawer count is stage 4. **Overdue balances** is real now: what is past
due as of today, with the Staff filter, linking to Aging. The cards' query
budget in `PerformanceTest` went from 16 to 17 for it.

**Collectibles** (`/collectibles`, under Insights; phase 19.1, asked for by
the client): every invoice still owed, one row each — sold, due, days
overdue, on account, what payments and returns took off it, and what is
still owed. Filtered by **month** (when it falls due, or when it was sold),
**customer**, **status** (past due or not yet due) and **who sold it**; the
cards above add up the filtered rows, not just the page. It reads
`CustomerCredit::openInvoices()` and `openTotals()`, so its totals equal
Aging and the Overdue card. Behind `customer_payments.view`. Export is
phase 34.

`php artisan inventory:verify` also checks every sale's balance: on account,
less return credits, less payments that still stand. A difference means
something wrote `balance_due` directly.

### Purchase requests

Phase 21, the first of stage 5 (the buying chain). Somebody asks for goods to
be bought; somebody else who may approve answers it. Nothing is bought, no
stock moves and nobody owes anything until a purchase order or a delivery
follows.

| Piece | What it does |
|---|---|
| `purchase_requests` | The request: why it is needed (required), a preferred supplier and a needed-by date (both optional), the estimated total, who raised it, the answer and who gave it, and a close reason. Its own number, `PRQ-2026-0001`, from **Purchase request prefix** under Document Numbers — not `PR`, which purchases already use; two prefixes alike would share one counter |
| `purchase_request_lines` | Shaped like a purchase line — `quantity` in the product's own unit, beside `unit_quantity` of `unit_id` at `unit_factor` — at an estimated cost per typed unit, so phase 22's order can copy them |
| `App\Enums\PurchaseRequestStatus` | Pending, Approved, Rejected, Closed. Not `DocumentStatus`: a request is never posted |
| `PurchaseRequestDesk` | Raises, approves, rejects and closes, each in one transaction, the row locked and its status read again, the dashboard flushed with `DB::afterCommit()` |
| `PurchaseRequestPolicy` | Who may do which; no query, so the list can ask it per row |

The rules:

- **Decision 2 is still open, so this is the default taken on GO! (12
  November 2026):** whoever holds **`purchase_requests.approve`** answers a
  request — Admin, through `*`. Naming another approver is a tick on the
  Roles screen.
- **Answering one's own request is a setting**, *An approver may approve
  their own request* under **Settings → Purchasing**
  (`purchasing.self_approval`), **on by default** (chosen 12 November 2026),
  because the shop has one approver and a strict rule left the owner's own
  requests waiting for ever. On, the request says so and the audit trail
  reads *Approved their own purchase request*, with `own_request` in its
  properties. Off, nobody answers their own, Admin included: the policy
  hides the buttons and the desk refuses again under the lock. Turn it off
  once a second person may approve.
- **Staff get `purchase_requests.view` and `.create`.** They may withdraw
  (close) a request they raised; somebody who may approve may close any.
  Pending or approved can be closed; rejected and closed are final.
- **Rejecting and closing say why.** Approving may carry a note.
- **No edit and no delete route.** A request typed wrongly is closed and
  raised again. A product or unit named on a line cannot be deleted; a
  preferred supplier that is deleted is simply cleared from the request.
- **Lines follow the purchase form's rules**: active products, decimals only
  where the unit allows, other units through `UnitConversion`. The cost is an
  estimate and may be 0. The product list comes from
  `Product::buyingOptions()`, which the purchase form now reads too.
- The request page shows what is on hand now beside each line, in orange
  when it is at or below the reorder level, so whoever decides can see if it
  is really needed.
- **The Purchase requests card is real**: how many are pending, their
  estimate and how long the oldest has waited, as of now. It needs
  `purchase_requests.view` (not `purchases.view` any more), follows the Staff
  filter by who raised them, and opens the list filtered to Pending. A list
  under the cards shows the five waiting longest. The cards' query budget
  went from 17 to 18 for it.
- `purchase_requests.*` are new permissions, so a deploy runs
  `permissions:sync`. The audit trail records **Create**, **Approve**,
  **Reject** and **Close** on the request. `data:reset` wipes both tables.
- **An approved request becomes a purchase order** (phase 22, next section):
  **Create order** on the request opens the order form filled from it, and
  placing the order closes the request as *Ordered on [PO-…]*.

### Departments, and printing a request (phase 24.1)

The first of four phases for the client's canvass and purchase-order
approval request of 13 November 2026 (SCOPE.md, CR-1): a department raises
a request, Sir Fernan approves it, suppliers are asked for prices, the prices
are compared, a supplier is chosen per item, orders are made at those
prices, and each order is approved before it is sent.

**Departments** (Masters → Departments) is a master list shaped exactly like
Categories: name, description, active; the form in a dialog on the list;
`departments.view/create/update/delete/status` — Admin keeps it, Staff read
it. *Electrical* and *Construction* are put in by the migration, so a server
deployed without `db:seed` has them; `data:reset` empties the list like every
other master and the client enters their own. A department a request names
is **switched off, never deleted** (`DepartmentPolicy`, counted with
`withCount`, so the list asks nothing per row).

**On a request** the Department is optional — requests raised before this
phase have none — and only an active one can be picked
(`Department::options()`). The list filters by it and shows it under the
reason; the page and the dialog show it.

**Two papers from the request's page**, both through the shared letterhead
and footer (`Components/PurchaseRequestPrint.vue`):

| Button | What prints | When |
|---|---|---|
| **Print PR** | The request: department, who raised it, why, the items with the estimate, *Requested by* and *Approved by*. One not yet approved says *Waiting for approval* across the top; a rejected one never names its rejecter as the approver. | Always |
| **Print RFQ** | A Request for Quotation: the items and quantities with **empty Unit price and Amount columns**, *reply by* the needed-by date, blanks for delivery days, payment terms and validity, and *Quoted by* / *Date* lines. **It never shows our estimate** — a supplier who saw it would quote up to it. | Only once the request is approved |

Saving either as PDF is the print dialog's **Save as PDF**. The dialog on the
list now says **Open full page to print**.

### The canvass: supplier prices and the Abstract of Quotation (phase 24.2)

The second phase of the client's request CR-1, built on the client's answers:
the canvass is **optional** (Q2), a **dearer supplier may win with a typed
reason** (Q3), **one supplier per item** (Q4), and **delivery days, payment
terms and a note** may come with the price (Q5).

**On an approved request** the page carries a **Canvass — Abstract of
Quotation** panel (`Components/CanvassPanel.vue`):

1. **Add supplier quote** (`/purchase-requests/{id}/quotes/create`, a page of
   its own because it has line items): pick the supplier, type its price for
   each item **per the unit the item was asked in, VAT included**, leave an
   item empty if the supplier does not carry it; delivery days, terms and a
   note are optional. One quote per supplier per request; an active supplier
   only; at least one price; more than zero.
2. **The Abstract**: items down, suppliers across, each cell the unit price
   and the line amount; **the lowest on each item is marked** (every tie);
   each supplier's total and how many items it priced and was lowest on; the
   total if the lowest were taken on every item. **Print Abstract** prints it
   with ★ on the lowest, the award and the reasons, *Prepared by* and
   *Awarded by*.
3. **Choose suppliers** (the owner): the table turns into choices, **the
   lowest already chosen**; choosing a dearer price opens a box for the
   reason, and **Approve award** is refused without one. An item nobody
   quoted is **left out of the award** and the table says so.
4. **Reopen award**, with a reason, until an order is made from it — the
   choices are cleared and the quotes can change again.

**`App\Support\QuoteAbstract` is the only place prices are compared**:
`of()` builds the table the screen and the paper read, and `weighAward()`
checks an award — the form asks it for errors, **`App\Services\CanvassDesk`**
asks again with the request row locked and decides. The desk refuses a quote
or an award unless the request is approved, not awarded and not ordered.
**After the award the quotes are locked**: they are the record of why a
supplier won. An awarded request is **not ordered by hand** (Create order is
hidden, the order desk refuses it); its orders are made from the award in
phase 24.3. A request with quotes but no award still orders by hand, since
the canvass is optional.

`canvass.create` (record and remove quotes — Staff and Admin) and
`canvass.award` (choose, reopen — Admin). Every step writes one line on the
request's audit trail; a dearer choice is written with its reason. Tables:
`supplier_quotes`, `supplier_quote_lines`, and on the request lines
`awarded_quote_line_id` and `award_reason` — cleared, not refused, when a quote
line goes, so `data:reset` can empty the quotes before the requests. The list
shows *N quote(s)* or *Awarded* under each request's status.

### Orders from the award (phase 24.3)

The third phase of CR-1. On an **awarded** request the Abstract panel offers
**Create orders from award** (`purchase_orders.create` — Admin; Staff read
orders and do not place them). One press, after a confirmation that names each
supplier, its items and its total, places **one purchase order for each
supplier that won an item**:

- the lines are that supplier's items, in the **unit each was asked in**, at
  the **price it quoted** — so the orders add up to the award total exactly;
- each order line remembers the request line it fills
  (`purchase_order_lines.purchase_request_line_id`);
- *Deliver by* is today plus the delivery days the supplier quoted, when it
  said; the note reads *From the award on request [PRQ-…]* and repeats the
  payment terms quoted;
- the VAT rate is stamped from the supplier, as every order's is.

The request then **closes as *Ordered on [PO-…], [PO-…]***, adding *N item(s)
nobody quoted were not ordered* when some were left out of the award. Its page
and dialog list every order with its supplier and status; the award can no
longer be reopened. `PurchaseOrderDesk::placeFromAward()` locks the request
row and reads again that it is approved, awarded and not yet ordered, so two
presses cannot order the award twice. The phase 22 rule *one request, one
order* is lifted for this: `purchase_orders.purchase_request_id` is now a plain
index (migration `2026_11_14_000003`, which adds the plain index before
dropping the unique one, because MySQL needs an index under the foreign key at
every moment). A request **not** awarded is still ordered by hand, once.

The orders are ordinary purchase orders from here: printed, received against
(partial deliveries, closing short), billed and paid as phases 22–24 built.
Since phase 24.4 they are approved first, like every order.

### Purchase-order approval (phase 24.4)

The last phase of CR-1, built on the client's answers Q6 (only Sir Fernan
approves an order; his own are approved at once) and Q7 (an unapproved order
prints only as *DRAFT — NOT APPROVED*). It reverses the phase 22 rule *an order
has no approval of its own*.

**How an order starts.** `PurchaseOrderDesk::write()` decides it, for an order
placed by hand and for the orders made from an award alike:

| Who places it | It starts as |
|---|---|
| Somebody holding `purchase_orders.approve` (Admin), with **Settings → Purchasing → An approver may approve their own request or order** on (the default) | **Open** — approved at once; the trail says *Approved their own purchase order [PO-…] as it was placed* |
| The same person with that setting off | **For approval** — somebody else must answer it |
| Anybody else who may place orders (an in-charge given `purchase_orders.create`) | **For approval** |

**While it waits** it is not outstanding: **Receive delivery** is hidden, the
purchase form refuses it (*Order [PO-…] is waiting for approval, so nothing
can be received against it yet*) and `OrderReceiving::weigh()` refuses it
again. It prints with **DRAFT — NOT APPROVED** across the page, the same words
under the reference, a blank *Approved by* line and *Not approved. Do not send
this order to the supplier.* The page's button reads **Print draft**.

**The approver** sees **Approve** and **Reject** on the order's page and in its
dialog on the list. Approve makes it *Open*: it prints clean, signed *Approved
by* with the approver's name, and can be received against. Reject needs a
reason and is **final** (status *Rejected*, still a draft on paper); the goods
are ordered again on a new order. A rejected or cancelled order leaves the
request it came from closed. An order waiting may still be cancelled by
somebody with `purchase_orders.cancel` who is not the approver. Both answers
lock the order row and read again that it waits, so Approve and Reject pressed
at once cannot both get through; with the setting off, one's own order is
refused there as well as hidden in the policy.

**Where it shows.** The Purchase Orders list says how many orders wait, with a
*Show them* link, and its Status filter has *For approval* and *Rejected*. The
dashboard's secondary row has an **Orders to approve** card (how many, their
total, the oldest; behind `purchase_orders.view`; the Staff filter narrows it
to the orders that person placed), so the desk flushes the dashboard after
every place, approve, reject and cancel. The cards' query budget is 22.

**The paper** is `Components/PurchaseOrderPrint.vue`. It reads `order.draft`,
which the server works out from the status — never from `approved_at` — and the
order's own approver: the request's approver never stands in for it.
`tests/js/PurchaseOrderPrint.test.js` guards both.

**Old orders.** Migration `2026_11_14_000004` adds `approved_at`,
`approved_by`, `rejected_at`, `rejected_by` and `reject_reason`, and counts
**every order already on the books as approved**: `approved_at` from when it
was placed, `approved_by` from its request's approver — the name it already
printed — so an old order reprints exactly as before. An order placed directly
before this phase printed a blank line, and still does.

**Permissions.** `purchase_orders.approve` is new (Admin through `*`), so a
deploy runs `permissions:sync`; the setting's wording changed, so
`config:cache` too. **Staff still only read orders.** To let a department's
in-charge draft orders for approval, tick *Place a purchase order* for their
role on **Roles & Permissions** — safe now, because nothing they place can be
sent or received until it is approved.

### Purchase orders

Phase 22, the second of stage 5. What the shop asks a supplier to deliver,
at the cost agreed, by when. It moves no stock and owes nothing: the stock
still goes on the shelf when the delivery is posted under Purchases, and
phase 23 ties that delivery to the order.

| Piece | What it does |
|---|---|
| `purchase_orders` | Supplier, order date, expected delivery, the total, `vat_rate` stamped from the supplier like a purchase, who placed it, a cancel reason. `purchase_request_id` names the request it came from. A request ordered by hand becomes one order; since phase 24.3 an **awarded** request becomes one order per winning supplier, so the column is no longer unique — the request lock guards it. Its own number, `PO-2026-0001`, from **Purchase order prefix** under Document Numbers |
| `purchase_order_lines` | Shaped like purchase lines — `quantity` in the product's own unit beside what was typed — at the agreed cost per typed unit |
| `App\Enums\PurchaseOrderStatus` | Open and Cancelled. Phase 23 adds partly received and received; phase 24.4 adds for approval and rejected |
| `PurchaseOrderDesk` | Places and cancels, each in one transaction. From a request it locks the request row, checks again that it is approved and not yet ordered, takes the number, and closes the request in the same transaction |

The rules:

- **From a request or directly.** `/purchase-orders/create?request={id}`
  fills the form with the request's supplier, needed-by date, notes and lines
  at their estimates; change the costs to what the supplier agreed. Only an
  approved request that has not been ordered can be used. An order may also
  be placed with no request: the request step is optional.
- **No approval of its own** — until phase 24.4, which added one: see
  *Purchase-order approval* above.
- **`purchase_orders.view`, `.create`, `.cancel`** are new permissions, so a
  deploy runs `permissions:sync`. Admin has all three; **Staff only view**,
  because placing an order commits the shop to a supplier.
- **Never edited or deleted.** An open order is **cancelled** with a reason.
  The request it came from stays closed; the goods are then ordered again
  directly.
- **Dated** any day up to today, with no posting-order floor (no stock moves).
  The expected delivery is optional and not before the order date. An open
  order past it is marked **Late** on the list and the page.
- **The order's page is the printed order**: company, supplier with its
  address, TIN and contact, the dates, the lines, the VAT split for a VAT
  supplier, the notes, and *Prepared by* / *Approved by* — the request's
  approver, when there was a request. **Print** leaves the app's chrome off
  the paper, like the statement of account.
- **Outstanding orders** are the ones still waiting for goods — open or
  partly received: the list says how many and filters to them in one click.
  Receiving against them is the next section.
- A supplier, product or unit named on an order cannot be deleted.
  `data:reset` wipes both tables. The audit trail records **Create** and
  **Cancel** on the order, and **Close** on the request it came from.

### Receiving against an order

Phase 23, the third of stage 5. A delivery is still posted under Purchases,
and can now name the order it fills.

| Piece | What it does |
|---|---|
| `purchases.purchase_order_id`, `purchase_lines.purchase_order_line_id` | Which order a delivery fills, and which order line each of its lines fills. A line with no order line on such a delivery is goods the order did not ask for |
| `purchase_order_lines.received_quantity` | What posted deliveries brought in against the line, in the product's own unit. Written only by `PurchasePoster`, with the order locked |
| `App\Support\OrderReceiving` | The only place a delivery is weighed against its order (`weigh()`), an order's status worked out (`statusOf()`), and the received figures checked (`reconcile()`, run by `inventory:verify`) |

The rules:

- **Receive delivery** on an order (or its dialog) opens the purchase form
  with the supplier fixed and a line for everything still to come, at the
  agreed cost — in the unit it was ordered in when that is whole boxes,
  otherwise by the piece at the agreed piece cost. Type what arrived and what
  the invoice says; take off a line that did not come.
- **No more than is still to come** on a line tied to the order. Extra goods
  go on a line of their own, which the order shows as *Delivered but not on
  the order*.
- **A different cost is allowed and flagged**: the order's Receiving section
  and the delivery's page say *invoiced ₱46.50 / pc, agreed ₱44.00*.
- **Status follows the deliveries**: open → partly received → received. A
  partly received order whose rest is not coming is **closed short** with a
  reason; an order with nothing received is **cancelled** instead. Both need
  `purchase_orders.cancel`.
- **Voiding a delivery gives its goods back to the order** (received → partly
  received or open). An order closed short stays closed. A purchase return
  does not touch the order.
- A delivery may still be posted **without an order**, as before.
- **Locks:** a delivery against an order locks the order, then the document
  number, then the products; a void locks the purchase, then its order, then
  the products.
- The order's page has a **Receiving** section on screen only — ordered,
  received and still to come per line, cost changes, goods not on the order,
  and the deliveries — so the printed order the supplier gets is unchanged.

### Supplier payables

Phase 24, the last of stage 5. What the shop owes its suppliers, and paying
them — the supplier side of phases 18 and 19.

| Piece | What it does |
|---|---|
| `suppliers.payment_terms_days` | Days the supplier gives to pay. 0 is due on delivery. On the supplier form; a form that leaves it out keeps it |
| `purchases.on_account`, `balance_due`, `due_date` | **The delivery is the bill.** On account, the whole bill waits for a supplier payment, due on the date stamped at posting from the supplier's terms. Every delivery posted before phase 24 counts as paid |
| `purchase_returns.credited_amount`, `supplier_credit_amount` | A return comes off an unpaid bill first; the rest, on a bill already paid, stays as **credit with that supplier** |
| `supplier_payments`, `supplier_payment_allocations` | Money paid to a supplier (`amount`, with a method) and credit used (`credit_used`, no method) against named bills; the bills add up to both exactly. `SP-2026-0001`, from **Supplier payment prefix**. Posted on save, never edited, voided with a reason |
| `App\Support\SupplierAccount` | The only place an account is read: owed, credit, open bills and their totals and aging, the Payables card, `reconcile()` for `inventory:verify` |
| `App\Support\BillAllocation` | The only place a supplier payment is weighed against bills; the form asks, the poster asks again with the rows locked |

The rules:

- **On the supplier's account** is a switch on the purchase form. Off, the
  delivery is paid on delivery. On, the form says when the bill falls due.
- **A supplier payment** (`/supplier-payments/create`, or **Pay supplier** on
  a bill, the supplier's page or a Payables row) spreads the money and any
  credit used over the bills soonest due first. No bill takes more than it
  owes, and money nobody can place is refused. Credit alone needs no method.
- **A purchase with a supplier payment against it cannot be voided** — the
  payment is voided first. A void clears what the bill still owed.
- **Locks:** the supplier, then the bills in id order, then the document
  number; the credit is read with a locking read. A payment's void locks the
  payment, then its bills.
- **Payables** (`/payables`, under Insights): every bill still owed, by
  supplier, past due or not, and the month it falls due, with the totals and
  the aging (not yet due, 1–30, 31–60, 61–90, over 90) over the filtered
  rows. The supplier's page shows what is owed, past due, the credit with
  them and the open bills.
- **The dashboard**: a **Payables** card, and Net cash from trading counts a
  bill when the supplier is paid. A return that came off a bill or stayed as
  credit moves no cash; one from before phase 24 still reads as a cash refund.
- **`supplier_payments.view`, `.create`, `.void`** are new permissions, so a
  deploy runs `permissions:sync`. **Staff hold none** — paying suppliers is
  the owner's job. A payment method a supplier payment used cannot be
  deleted. `data:reset` wipes both new tables; `inventory:verify` checks
  every bill's balance and that no supplier's credit was used beyond what it
  had.

### Quotations

Phase 28, the first of stage 7 (taken ahead of stages 4 and 6, the client's
request 2). A price offered to a customer until a date.

| Piece | What it does |
|---|---|
| `quotations` | Customer, an optional *attention* name, the date, `valid_until`, the subtotal, discount and total, who prepared it, and — once accepted — the sale it became (`sale_id`, unique). `QT-2026-0001`, from **Quotation prefix** under Document Numbers |
| `quotation_lines` | Shaped like sale lines: `quantity` in the product's own unit beside what was typed, at `unit_price` per typed unit |
| `App\Enums\QuotationStatus` | Open, Accepted, Cancelled. **Expired is not stored**: an open quotation past its date reads as expired |
| `QuotationDesk` | Makes and cancels a quotation, and — inside the sale's own transaction — checks one under the lock and marks it accepted |

The rules:

- **Nothing moves, nothing is reserved.** A quotation can offer more than is
  on hand; the page says what is short now. Reserving stock is phase 29.
- **Valid until** defaults to today plus **Quotation valid for (days)**
  (15) under Document Numbers, and can be changed on each quotation.
- **Make a sale** on a live quotation opens the sale form filled in: the
  customer (fixed), the lines at the **quoted prices** and the discount,
  with what is short on the shelf said at the top. The sale posts as any
  sale does — stock checked again by the ledger, paid as usual — and the
  quotation becomes **Accepted**, naming the sale. One quotation, one sale.
- **An expired quotation cannot become a sale.** **Quote again** makes a new
  one from it at **today's prices**; a product switched off since is left
  out and a unit no longer offered falls back to the product's own unit,
  each said on the form.
- **Cancel** with a reason, while open. Never edited or deleted.
- The VAT split follows the Tax Settings switch, as a sale's does.
- **`quotations.view`, `.create`, `.cancel`** are new permissions, so a
  deploy runs `permissions:sync`. **Staff hold all three**; turning one into
  a sale needs `sales.create`.
- The quotation's page is the printed quotation, and says *a quotation, not
  an official receipt or an invoice*. A customer, product or unit named on a
  quotation cannot be deleted; `data:reset` wipes both tables.

### Mobilization (phase 28.1, CR-4)

The client's request of 27 November 2026: a **Mobilization** charge — what
bringing the goods, the people or the equipment to the customer's site costs
— on a quotation, carried to the sales order and the sale. A service, not
goods: no stock, no cost.

```
Subtotal (the goods)     2,500.00
Mobilization            +  500.00
Discount                −  100.00
Total                    2,900.00   = subtotal − discount + mobilization
```

- **One sum, everywhere**: `SaleTotals::of($lines, $discount, $mobilization)`
  on the server, `documentTotal()` in `lib/money.js` on the forms. The
  discount never takes more than the goods, so the charge is never
  discounted. Typed **VAT-inclusive** like every amount; the VAT is split
  from the whole total. `subtotal` stays the goods alone; `Sale::goodsTotal()`
  is the total less the charge.
- **Carried** from a quotation to *Make a sale*, *Make an order*, a sale from
  an order and *Quote again* (unlike the discount, which *Quote again* leaves
  behind). Paid with the rest of the sale, on account too.
- **Never refunded**: a sales return spreads its refund over the goods total.
  A sale all on account and fully returned still owes the charge.
- **Figures**: in net sales, gross profit (no cost, all of it profit), the
  monthly chart, sales by customer and Money received; its own line on
  Reports, *Mobilization charged*, and a column in the spreadsheet. **Left out
  of every goods figure** — sales by product and category, top sellers — and
  of the dashboard while it is narrowed to a category or a product. The
  dashboard builds its figures from lines, so the charge is spread across
  each sale's lines in the same query (no new query); unnarrowed it equals
  Reports.
- **The till has none**, and a sale paying a pending payment may not add one
  (refused by the request and by `SalePoster`). Anyone who may make the
  quotation, order or sale may enter it; no limit, no approval, never
  negative. Deploy: `migrate` and the new `public/build`.

### Sales orders and reserved stock

Phase 29, the second and last of stage 7. A customer's order that holds
stock for them.

| Piece | What it does |
|---|---|
| `sales_orders`, `sales_order_lines` | Customer, the date, `reserve_until`, the lines and totals (as on a sale), who took and who approved it, the quotation it came from and the sale it became (both unique). `SO-2026-0001`, from **Sales order prefix** |
| `App\Enums\SalesOrderStatus` | Pending, Approved (shown *Reserved*), Fulfilled, Cancelled. **Expired is not stored**: an approved order past its date holds nothing |
| `App\Support\Reservations` | The only place what is reserved is worked out — the lines of approved orders still within their date — and what is available: on hand less that |
| `SalesOrderDesk` | Takes, approves (reserving), cancels (releasing) and — inside the sale's transaction — fulfils an order |

The rules:

- **Pending holds nothing.** Whoever holds **`sales_orders.approve`**
  (Admin, through `*` — the default for decision 2) approves it, choosing
  the last day the stock is held (**Sales order holds stock for (days)**, 7,
  under Document Numbers). Somebody who may approve can tick *Approve and
  reserve the stock now* while taking it.
- **Approving checks the stock is free** — on hand less what other orders
  hold — and refuses the whole order when it is not. It locks the products
  first, then the order.
- **No other sale may take reserved stock**, on the sale form or at the
  till: both offer only what is available, the form puts the reason under
  the line, and the sale poster checks again after the ledger and rolls the
  sale back.
- **Make the sale** on an approved order opens the sale form filled in; that
  sale may take the order's own reservation, and the order becomes
  **Fulfilled**. **Release** (cancel) frees the stock at once. Past its date
  the order holds nothing, and can still become a sale while the stock is
  free.
- **From a quotation:** *Make an order* on a live quotation; the quotation
  becomes Accepted.
- **The Stock screen** shows **Reserved** and **Available** beside On hand.
  Losses and purchase returns do not look at reservations.
- **`sales_orders.view`, `.create`, `.approve`, `.cancel`** are new
  permissions, so a deploy runs `permissions:sync`. Staff view, take and
  cancel; approving stays with the approver. A customer, product or unit on
  an order cannot be deleted; `data:reset` wipes both tables.

### Returns

A return never stands alone: it belongs to the document it undoes, which is
why `create` takes a sale or a purchase and the "Return" button lives on that
document's own screen.

| | Sales return | Purchase return |
|---|---|---|
| Stock | Comes back in | Goes back out |
| Which batch | The ones that line ate, **newest first** | That delivery's **own** batch |
| Ledger method | `restoreConsumed()` | `issueFromLayers()` |
| Cap | What is left of the line | That, **and** what is left of the batch |

Three rules worth stating plainly:

- **The newest unit out is the first one back.** Anything else would leave
  earlier, cheaper batches half eaten while a later one sat full.
- **A purchase return is the one place FIFO does not decide.** The goods go
  back to the supplier who sent them, so they must leave that supplier's own
  batch. Anything already sold is gone, and no paperwork brings it back from
  a customer.
- **A refund is prorated.** A sale of 1,000 with 100 off collected 900, so a
  full return hands back 900. `line_total × (total_amount ÷ subtotal)`.

A return locks in this order, and so must anything added later: the source
document row, then the sequence row, then the document's lines and the
consumption rows they ate, then the products in ascending id order, then the
batches. Every poster starts with the document that gates what is allowed.

`sale_lines.inventory_movement_id` and `purchase_lines.inventory_layer_id`
are what make all of this possible. Without them a line knows its totals but
not which batches it touched, and a return would be a guess.

Three things that are easy to get wrong:

- **Dates have a floor and a ceiling.** Nothing may be dated before the last
  posted document, because costs are taken in posting order — and nothing may
  be dated in the **future**, because that would raise the floor for everybody
  and block posting until the date arrived.
- **A double-clicked Save must not make two documents.** The create screen is
  given a uuid, the form sends it back, and the column is unique. A repeat
  lands on the document it already made. The duplicate check in the ledger
  cannot catch this: two submissions are two different documents.
- **Totals are worked out on the server** and whatever the browser sent is
  ignored. `LineItems.vue` adds up in centavos for display only, because
  adding decimals drifts.

The supplier's own invoice number is kept as well, unique per supplier, so
the same delivery cannot be booked twice weeks apart.

A sale records what it earned. Each line keeps the price charged **and** the
cost the ledger worked out from the batches it ate, so the margin is a fact
rather than an estimate.

`sales.view_cost` guards those figures: the controller leaves them out of the
payload entirely for anyone without it — the list, the detail screen and the
product list the form is given — because a screen that merely hides a figure
has still been sent it.

Be clear about what that permission does **not** hide. Staff hold
`products.view` and record purchases, so they already see the Cost column on
the Product Master and type a cost on every delivery. Somebody who wants to
work out a rough margin can. What `sales.view_cost` protects is the cost and
profit **of a sale**: what a particular customer was charged against what
those goods actually cost, line by line.

A sale also checks each line against what is on hand **before** posting, so
the refusal appears on the line that caused it. The ledger checks again and
has the final word — between the two, somebody else may have sold the last
of it.

### Reading the ledger

Phase 11 added the two screens that show what phases 7 to 10 wrote. Both are
read-only, with no write routes at all — the ledger is append-only, so a row
is answered by another movement, never changed.

| Screen | What it shows |
|---|---|
| `/movements` | Every move, newest first, with the running balance |
| `/stock` | What is on the shelf and what it is worth |
| Product detail | That product's stock card and its open batches |

Three things worth knowing:

- **The movement list sorts by `id`, not by date.** `TableQuery` adds the id
  ascending as a tiebreaker, so sorting by date alone puts same-day rows
  oldest first and the running balance reads backwards — 88 above 90.
  Backdating is refused everywhere, so posting order *is* date order.
- **The reference links to its document without loading it.** `DocumentLink`
  builds the address from `source_type` and `source_id` alone. Loading the
  morph would cost one query per kind of document on the page, which is what
  the N+1 test catches when the mix of types changes.
- **Cost columns are held back.** On a sale row, `unit_cost` *is* that sale's
  cost of goods, so it sits behind `sales.view_cost` exactly as on the sale
  itself — left out of the payload, not merely hidden.

`App\Services\InventoryValuation` is the one place stock is valued: what is
left of each batch, times what that batch cost. Per product it is a
`selectSub`, because `withSum` cannot multiply two columns, so a page of
forty products still costs one query. The dashboard reads the same service,
so the two screens can never disagree.

Someone who can read batch costs can work out roughly what a sale cost. That
is already true of the Product Master's Cost column, and `stock.view` does
not change it.

### The figures

Phase 12 replaced the dashboard's audit-based cards — users, roles, actions
today — with what a business actually asks: what did we sell, what did we
buy, what is on the shelf, and what is running out. The activity feed stayed,
because that is the one question the audit trail answers better.

**`App\Services\InventoryMetrics` owns every definition**, and the dashboard
and `/reports` both read it (the Reports screen through `ReportBook`, phase 34). A figure shown on two screens has to mean one
thing:

| | |
|---|---|
| Net sales | Posted sales, less what came back |
| Net purchases | Posted deliveries, less what went back |
| Inventory | `InventoryValuation::total()`, never recomputed |

- **A return counts against the month it happened in**, not the month of the
  sale it undoes. That is when the money went back.
- **Opening stock and adjustments are not purchases.** Nothing was bought.
- **A voided document counts for nothing**, anywhere.
- **Every panel is gated and left out of the payload** — sold and top sellers
  need `sales.view`, the balance and low stock need `stock.view`. The cache
  behind them is shared, so the gate is in the controller.

The ledger flushes the cache with `DB::afterCommit()` from all five of its
entry points, so a posted document is visible at once rather than up to five
minutes later. Flushing inside the transaction would let a concurrent read
cache the state from before the document and leave it there.

**Two database differences bit once each, and are worth knowing:**

- A `date` cast writes `2026-10-18 06:10:40`. MySQL's DATE column truncates
  it; SQLite keeps it. `BETWEEN '2026-01-01' AND '2026-10-18'` therefore
  drops everything posted *today* on SQLite — green on one engine, red on
  the other. Compare against the end of the day instead.
- SQLite gives a DECIMAL column **integer affinity**, so `total ÷ subtotal`
  is `1800 ÷ 2000 = 0` there and `0.9` on MySQL. Multiplying before dividing
  gives the same answer on both.

### Reports: filters, the printed page and the spreadsheet

Phase 34 (stage 9) narrowed `/reports` and gave it two ways out. **Every block
reads one `ReportFilters` and one `ReportBook`**, and the screen, the printed
page and the spreadsheet all go through them, so the file always covers
exactly what the screen showed — the "never two copies" rule of `detail()`.

**The filters.** `App\Support\ReportFilters` reads the period, a **Cashier**
and a **Payment method** for both routes, and `InventoryMetrics` applies them
to every block — the summaries, the four breakdowns, the chart and Money
received — through `narrowSales()` and `narrowPurchases()`:

| Filter | What it narrows |
|---|---|
| Cashier | The documents that user posted (`posted_by`): sales, sales returns, deliveries, purchase returns, and the payments behind Money received. |
| Payment method | The sales **paid at least in part** that way (an `EXISTS` on `sale_payments`), and the returns of those sales. A sale paid two ways **counts whole** — one payment cannot be cut out of the goods it paid for. A return goes with the sale it undoes, whatever it was refunded in. Money received shows only that method's money. **Purchases record no payment method, so the purchase blocks are left out** while one is chosen, never shown unfiltered beside filtered sales (`coversPurchases()`). |

The **stock value** is "right now" and no filter narrows it, so it is left out
while either filter is set. **Who may pick a cashier** is decided on the
server: with `dashboard.view_all_staff` (Admin) anybody; without it
(Staff) Everyone — as Reports always showed — or themselves, and asking for a
colleague is refused. There is no "On account" choice: Collectibles already
lists what customers owe.

**Money received** is a new block: what came in by method over the period,
from `InventoryMetrics::collections()` — the till's payments and, for
somebody holding `customer_payments.view`, payments against invoices — the
same query the dashboard's Collections card reads.

**PDF is the printed page.** *Print / PDF* calls `window.print()`; the page
carries a print-only heading (company, period, cashier, method, who printed
it and when) and hides the filters, the buttons, the chart and "Show all".
Choosing **Save as PDF** in the print dialog makes the PDF. There is no PDF
library, on purpose: this system is deployed by uploading files.

**The spreadsheet** (`GET /reports/export`, behind `reports.view`) is a real
Excel workbook, written by `App\Support\Spreadsheet` on ZipArchive and
XMLWriter — no Composer package. Sheets: **Summary**; then, with `sales.view`,
**Sales** (one row per sale, a column per payment method, on account and
still owed), **Sales returns**, **Sales by product**, **Sales by customer**;
then, with `purchases.view` and no payment method chosen, **Purchases**,
**Purchase returns**, **Purchases by supplier**, **Purchases by product**.
Every register ends in a total that equals the screen's figure, and the
breakdown sheets carry **every** row, not the screen's fifty. **Cost and
gross profit are left out of the file** for anybody without
`sales.view_cost`, on the server, as on the screen.

What keeps Excel from refusing the file: every string is an inline string,
never a formula, so a customer named `=HYPERLINK(…)` stays text; characters
XML forbids are stripped; the style counts match their children; sheet names
are legal and unique; dates are real dates. Each sheet streams to a temporary
file under `storage/app/private/exports` 500 rows at a time, and the workbook
is deleted once it is sent. An export writes a **View** line to the audit
trail naming the period and the filters.

**The server needs PHP's `zip` extension.** Without it the Spreadsheet button
is hidden, the screen says why, and the route answers 503 rather than failing.

### Print layouts

Phase 35 gave every full-page document **one letterhead and one footer**,
and Settings → **Print Layouts** to drive them. The quotation, the purchase
order, the statement of account, Aging and Reports all print through
`Components/PrintLetterhead.vue`, `Components/PrintFooter.vue` and
`composables/usePrintLayout.js` — five copies of the company block became one.

| Setting | What it does |
|---|---|
| Paper size | The printer's own setting (default), A4, Letter, Long bond (8.5 × 13 in) or Legal (8.5 × 14 in). |
| Margins | The printer's own setting (default), narrow 8 mm, normal 12 mm, wide 18 mm. |
| Print the logo | On by default. Off for paper that already carries it. |
| Print the address, TIN and phone | On by default. The company name always prints. |
| Terms on a quotation / on a purchase order | Printed at the foot of that document. Empty by default. |
| Closing line on every page | One line at the very bottom of every printed page. Empty by default. |

**Every default prints the page exactly as before.** The paper and margins
are an `@page` rule put in the page only while a document page is open and
removed when it closes, so the **sale slip** — printed from the till and the
sale's page — never inherits A4; it keeps its own width and closing line
under Document Numbers. The lines saying a page is *not an official receipt
or an invoice* stay written in the pages and cannot be switched off.

### The developer's credit line

*Designed, Built and Shipped by* **DeltaTech** (23 November 2026) is one
component, `Components/DeveloperCredit.vue`. The logo always sits left of the
name, and only the name links to `https://deltatechph.com`, in a new tab. The
words are written in the component, not a setting, so nobody can change or
clear them from Settings. Each place picks the logo for its background:

| Place | Background | Logo |
|---|---|---|
| Sign-in and kiosk sign-in (`SignInLayout`), under the card | light cream | navy, 20 `--u` |
| Every app page's footer (`AppLayout`) | light grey | navy, 16 px |
| The error page for a signed-out visitor (`GuestLayout`) | dark navy | white, 16 px |

On the sign-in pages the credit stands where the mockup's caption *GO
Enterprises • Inventory Management System* was — the caption was taken off on
24 November 2026, at the user's request. The credit takes no more height than
the caption did, so the page keeps the mockup's height and never scrolls.
The same day the card's last line, *Need access? Contact your administrator.*,
and the divider above it were taken off too; the card now ends with *Kiosk
sign in*.
**On a light background the logo is always the solid navy symbol** (26
November 2026, at the user's request); the blue gradient one that the sign-in
pages carried before was removed. The logos are
`public/images/deltatech/deltatech-{navy,white}`, 64 px
tall, PNG and WebP — the 991 px originals would have cost every page about a
megabyte. A black logo was supplied for paper and is not used. **The line
never goes on a printed document** (quotation, order, request, RFQ,
statement, Reports, the sale slip) — those papers go to the client's own
customers and suppliers — **nor on the till**, a full-screen work screen.
`tests/js/DeveloperCredit.test.js` guards the words, the link, the logo's
place, the three layouts and the papers it must stay off.

### Backups

Phase 35 added **Insights → Backups** (`/backups`) and Settings → **Backup
and Export**. A backup is one `.zip` in `storage/app/private/backups` —
never the public disk — holding:

- `database.sql` — every table, written by `App\Support\DatabaseDump` in
  PHP (shared hosting has no `mysqldump`). On MySQL it reads every table
  inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT`, so a sale posted
  while the backup runs is wholly in or wholly out; a table that is not
  InnoDB is refused rather than dumped half-consistent. It reads with the
  session's time zone set to UTC (and puts it back), because the file tells
  the import that times are UTC — read in the server's own zone, every
  `created_at` and `posted_at` would come back eight hours off. The rows of
  `sessions`, `cache` and the queue tables are left out; their structure
  stays.
- `files/` — the product photos and the logo, read through the `public` disk,
  unless *Include product photos and the logo* is off.
- `private/losses/` — the photos kept as evidence on loss reports (phase 33),
  from the private disk, under the same switch.
- `RESTORE.txt` and `manifest.json`.

**It never carries `.env`.** Keep `.env` (the `APP_KEY` and the database
password) apart: the restored system needs the same key.

**Who.** `backups.view`, `backups.create`, `backups.download`,
`backups.delete` — Admin only, Staff none. A backup is the whole books and
every password hash. Every one made, downloaded or deleted is written to the
audit trail. The download route accepts only a name matching
`BackupMaker::NAME` (`backup-YYYY-MM-DD-HHMMSS.zip`), so `../.env` is a 404.

**How often, and how many.** *Make a backup by itself* is Off (default),
Every day at 2:00 AM, or Every Sunday at 2:00 AM; *Backups kept on the
server* keeps the newest 7 by default and deletes the oldest **only after a
new one succeeds**. A backup is written under a `.part` name and renamed
once whole, so a half-made one is never listed. The schedule runs
`php artisan backup:run`, and **only if the server has one cron job**:

```
* * * * * cd /path/to/inventory_app && php artisan schedule:run >> /dev/null 2>&1
```

On Hostinger: hPanel → Advanced → Cron Jobs. The button on the screen makes
one at once; on the 52-table development books it took under a second.

**Restoring is done by hand, never from the app** (RESTORE.txt says the same):
`php artisan down`; import `database.sql` into an **empty** database
(phpMyAdmin → Import, or `mysql -u USER -p DB < database.sql`); copy `files/`
into `storage/app/public`, and `private/losses/` into `storage/app/private`;
`storage:link` if the photos are broken;
`optimize:clear`; **`inventory:verify`**; `php artisan up`.

**Proved on 13 November 2026**: a backup of a copy of the development
database (52 tables, 31,694 rows, 111 photos) was imported into an empty
MariaDB database. Every table held the same rows (the session and cache
rows aside, and the one audit line the backup itself wrote), the checksums
of the products, the sales, the purchases, the users (password hashes
included) and every audit line's time matched to the second, and
`inventory:verify` passed all four checks on the restored books. Do it once more on the real server before go-live.

**Keep a copy off the server.** A backup kept only on the server is lost
with the server; the screen says so.

### The owner's dashboard

The dashboard is a daily control screen. It opens on **Today**, and the
filter bar adds Yesterday and a **Staff** box beside the period, category and
product. Every figure, card and list answers to all of them.

**The eight cards, and where each one comes from:**

| Card | Formula | Opens |
|---|---|---|
| Today's sales | Posted sale lines after discount − refunds on sales returns | Sales, by period and staff |
| Collections | What was paid on posted sales, from the payments each sale recorded, plus what customers paid against invoices on the day it came in, split by method under the figure (Cash ₱850.00 · GCash ₱1,000.00). Narrowed to a category or product it falls back to the goods sold, because one payment cannot be split between them. What went on account is not in it until it is paid | Sales |
| Net cash from trading | Collections − cash refunds − deliveries paid on delivery − supplier payments + cash refunds on purchase returns. What went on a customer's account is left out until the customer pays it; what went on a supplier's account until the supplier is paid (phase 24) | Reports |
| Est. gross profit | Sales − FIFO cost of what was sold (less returned cost). Estimated: opening costs are typed in and VAT is in both | Reports |
| Low stock items | Active products with on hand ≤ reorder level (0 = not watched) | Stock, low |
| Overdue balances | What is still owed on sales past their due date, as of today, after returns and payments. Staff filter applies; category and product do not | Aging |
| Purchase requests | Requests pending approval, as of now, with their estimate and the oldest's wait. Staff filter applies (who raised them). Needs `purchase_requests.view` | Purchase requests, pending |
| Recorded losses | Posted **Decrease** adjustments: every reason, valued at the batches they ate | Adjustments, decreases |

A smaller row under them keeps Payables (what is owed to suppliers as of
today, past due beside it; `supplier_payments.view`; phase 24), Net
purchases, Inventory value, Out of stock, Returns rate and Sales made. Under the cards, short lists show the worst low
stock, the largest losses, the customers longest overdue and the purchase
requests waiting longest; each row opens its product, adjustment, customer
or request.

- **Cost is gated on the server by `sales.view_cost`.** Without it, Est. gross
  profit and Inventory value arrive as `state: restricted` with no value at
  all, losses are counted in units, the chart has no profit line, and the
  stock-value chart and the dead-stock peso figure are left out. The API's
  dashboard drops its inventory card the same way. The Stock screen still
  shows value to `stock.view`; that was left as it was.
- **`dashboard.view_all_staff` decides whose documents are added up.**
  Without it the controller sets the Staff filter to the user's own id,
  whatever the address says, and offers no other name. Admin has it through
  `*`. The ledger records no user, so the stock-movement chart is left out
  while a staff filter is set — which means always, for a held user.
- Shelf figures (low stock, out of stock, inventory value) are the whole
  shop's and do not follow the Staff filter. Their tooltips say so.
- The Sales, Purchases and Adjustments lists accept `posted_by`, and
  Adjustments also `reason`, so a card opens its list already narrowed.
- An adjustment's reason is a fixed list in `StoreAdjustmentRequest::REASONS`;
  **Written off** was added to it.

**On deploy, in this order:** `php artisan config:cache` (or `config:clear`),
**then** `php artisan permissions:sync` — sync reads the cached config, and an
old cache would never create the new permission. Until it exists `can()` is
false for everybody, Admin included, and the owner would quietly see only
the sales they rang up themselves. Then press Refresh on the dashboard once,
so a figure cached in the old shape is not served. Check it: signed in as
admin, the Staff box must read *All staff* and be editable.

### The three declared lists

Nearly every change starts in one of these files:

| File | What it declares |
|---|---|
| `config/permissions.php` | Every permission, grouped. Run `permissions:sync` after editing. |
| `config/settings.php` | Every setting: group, label, type, default, validation rules. |
| `resources/js/navigation.js` | The sidebar, with the permission each item needs. |

---

## 4. The CRUD standard

Every module follows the same six pieces. Users and Roles are the worked
examples — read `UserController` and `UserPolicy` alongside this.

### Step 1 — Permissions

Add a group to `config/permissions.php`:

```php
'members' => [
    'label' => 'Members',
    'description' => 'Who may open and change member records.',
    'permissions' => [
        'members.view' => 'View members',
        'members.create' => 'Create members',
        'members.update' => 'Edit members',
        'members.delete' => 'Delete members',
        'members.status' => 'Activate and deactivate members',
    ],
],
```

```bash
php artisan permissions:sync
```

The Admin role now holds them, and they appear on the role form by themselves.

### Step 2 — Migration and model

```php
class Member extends Model
{
    use Auditable;          // create, update and delete now log themselves

    protected $fillable = ['name', 'is_active', /* ... */];
}
```

Index any column a list screen will sort or filter on.
`tests/Feature/PerformanceTest` fails if you forget.

### Step 3 — Form Requests

One per write action, in `app/Http/Requests/Member/`. Rules go here, never in
the controller. Cross-field rules go in an `after()` closure — see
`UpdateUserRequest`, which stops the last administrator locking everybody out.

### Step 4 — Policy

`app/Policies/MemberPolicy.php`. Laravel finds it by name. Return a
`Response::deny('...')` instead of `false` when the reason helps the user.

Keep policies cheap: they run once per row on a list screen. If a check needs
a query, read it once and keep it — `UserPolicy::$activeAdminIds` shows how.

### Step 5 — Controller and routes

```php
public function index(Request $request): Response
{
    $this->authorize('viewAny', Member::class);

    $table = TableQuery::for(Member::query()->with('whatever'), $request)
        ->searchable(['name', 'code'])
        ->sortable(['name', 'is_active', 'created_at'])
        ->defaultSort('name');

    return Inertia::render('Members/Index', [
        'title' => 'Members',
        'members' => $table->paginate()->through(fn (Member $m) => [
            'id' => $m->id,
            'name' => $m->name,
            'can' => [
                'update' => $request->user()->can('update', $m),
                'delete' => $request->user()->can('delete', $m),
            ],
        ]),
        'filters' => $table->state(),
        'can' => ['create' => $request->user()->can('create', Member::class)],
    ]);
}
```

A column whose figure is worked out in the query rather than stored is
sorted by a keyed entry: the customer list's
`->sortable([..., 'balance' => 'balance_cents'])` lets the page send
`sort=balance` and orders by the `balance_cents` that `withBalance()` selects
(24 November 2026). The page still reaches only what is listed. Make sure
the figure is read through an index, as `sales_customer_balance` serves the
balance.

Routes get the permission as middleware — the outer gate — while the policy
checks each row inside:

```php
Route::resource('members', MemberController::class)
    ->middlewareFor('index', 'permission:members.view')
    ->middlewareFor(['create', 'store'], 'permission:members.create')
    ->middlewareFor(['edit', 'update'], 'permission:members.update')
    ->middlewareFor('destroy', 'permission:members.delete');
```

### Step 6 — Pages

`resources/js/Pages/Members/Index.vue`, built on `DataTable`:

```vue
<DataTable
    :data="members" :columns="columns" :filters="filters"
    url="/members" :only="['members', 'filters']"
>
    <template #cell-actions="{ row }">
        <TableAction variant="view" :href="`/members/${row.id}`" />
        <TableAction variant="edit" :disabled="!row.can.update"
                     :href="`/members/${row.id}/edit`" />
        <TableAction variant="delete" :disabled="!row.can.delete"
                     @click="remove(row)" />
    </template>
</DataTable>
```

Add the menu item to `resources/js/navigation.js` with its `permission`, and
it appears only for the people who may open it.

#### Where a form lives

> **A form that writes belongs where it is least in the way: a short master
> form in a dialog, a document with line items on a page. Reading never
> needs a page at all — every record opens in a dialog on the list.**

A master form is a handful of boxes. Sending somebody to another page and
back for that loses their place in the list and costs two page loads, so
Products, Categories, Units, Suppliers and Customers have no `create` or
`edit` route at all. A document form is a different thing — a header and a
table of lines that grows — and it keeps its page.

**Reading is separate, and was got wrong once.** This section used to say a
document was "far too big for a dialog", written before Purchases and Sales
existed. It was a guess, and the guess was wrong: losing your place in the
list to read one row is the same annoyance the master dialogs were built to
remove. Every list now opens its record in a dialog — purchases, sales,
adjustments, both returns, and a product from the Stock screen — and the
movement ledger opens the document behind a row the same way. So does a
sale under "Recent sales" on a customer's page; a ctrl-click there still
opens the full page in a new tab. Since 24 November 2026 **Customers,
Suppliers and Products** open in a dialog on their lists too
(`CustomerModal`, `SupplierModal`, `ProductModal`, reading
`/customers/{id}/preview`, `/suppliers/{id}/preview` and
`/products/{id}/preview`), with Edit in the dialog opening the edit form
over the list. Only Users and Roles still open a page.

**Every list remembers where you were** (24 November 2026,
`lib/listMemory.js`). The shared `DataTable` writes down, for its own list,
the address it shows — sort, filters, search, rows per page and page all
live in the query string — how far down it is scrolled, and the row last
touched, which it marks with a bar on the left. `PageHeader` turns its back
link into a **breadcrumb** (*Customers › RJ Builders & Construction*;
`crumbs` gives a longer trail, as on a statement or a return), and every
crumb, and every `PrimaryButton` such as Cancel, that leads to a bare list
address goes to the remembered one instead; the list then scrolls back and
marks the row. **The sidebar never reads it**: a list opened from the menu
starts fresh. It lives in `sessionStorage` — one tab, gone when the tab
closes — and with storage blocked the links simply open the list from the
start. A new list gets all of this by using `DataTable`; a new page by
using `PageHeader` with `back-href`. Nothing per page.

The pages all remain. `/sales/5` is still a real address to bookmark, send
or refresh, and every dialog carries an **Open full page** link to it. The
dialog is a summary, not a cramped copy of the page: it shows the record,
its lines, what it came to, and whatever can still be done to it.

The dialog is `Components/Modal.vue`, driven by `composables/useFormModal.js`,
and it renders the same `*FormFields.vue` component the page used to:

```vue
const modal = useFormModal({
    url: '/members',
    noun: 'member',
    label: (row) => row.code,
    fields: ['code', 'name'],
    blank: { code: '', name: '', is_active: true },
    fromRow: (row) => ({ code: row.code, name: row.name, is_active: row.is_active }),
})
```

Two rules follow from it:

- **The row feeds the form.** `fromRow` reads the row already on screen, so
  the dialog opens with no request at all. Whatever the form needs, `index`
  must put on the row — the list query already selects every column, so this
  costs nothing. `MasterFormModalTest` checks the fields the product, supplier
  and customer dialogs rely on; a new module should add its own case there.
- **`store` and `update` answer with `back()`**, so the list keeps its page,
  sort, filters and search. `destroy` still redirects to the list, because it
  is also called from a detail screen, and "back" there is a record that has
  just gone.

#### Reading a record in a dialog

`Components/RecordModal.vue` fetches one record and hands it to a scoped
slot, with a skeleton while it waits. `DocumentModal.vue` sits on it for the
five document kinds, `ProductModal.vue` for a product.

A master dialog fills itself from the row already on screen and costs no
request. A document cannot: its lines are not on the row, and putting forty
documents' worth of lines on a list page is the payload `PerformanceTest`
exists to prevent. So the dialog fetches from `/<module>/{id}/preview`.

**That endpoint must never build its own payload.** The controller holds one
`detail()` method; `show()` renders it through Inertia and `preview()`
returns it as JSON. Two copies would drift the first time a column was added
to one of them, and the cost gating would drift with them —
`DocumentPreviewTest` compares the two payloads field by field and fails if
they differ. It also checks that a dialog is not a way round
`sales.view_cost`, and that reading one is written to the audit trail, since
reading a document is reading it wherever it opened.

`Modal.vue` is a plain layered element at `z-index: 1000`, not a native
`<dialog>`. A native one sits in the browser's top layer, which would leave
every SweetAlert2 alert stuck behind it and impossible to click. For the same
reason it hands the keyboard over whenever `Swal.isVisible()`, or while a
`ComboBox` list is open, so Escape answers the question — or closes the list
— instead of shutting the form behind it.

#### Picking from a list

> **Every pick list is `Components/ComboBox.vue`. There are no raw
> `<select>` elements in the app.**

`SelectField` (forms) and `FilterSelect` (filter bars) are thin wrappers over
it, so most screens never name it directly. Typing narrows the list; it never
becomes the value, and leaving the box without picking puts the real label
back.

**Every list can be typed into, however short.** The search was once
switched on only past seven rows, on the idea that a short list is quicker
to read than to type. On real data that was wrong — products, categories,
units, suppliers and customers were all under the threshold, so the search
nobody could see was the search nobody had, and it would have switched
itself on silently as the data grew. Do not put the threshold back.

Four things it has to get right, each of which broke something real:

- **The list is teleported to the body** at `z-index: 5000`, positioned from
  the input's own rectangle and flipped upward near the bottom of the window.
  Rendered in place it would be cut in half by the card that clips the
  purchase lines, and again by the dialog that scrolls.
- **Escape and Enter are stopped.** Escape would otherwise close the dialog
  behind it, and Enter would post the purchase being typed.
- **Values are compared as text.** A form sends strings and the server sends
  numbers, so `String(a) === String(b)` decides which row is selected. That is
  what keeps the right label showing after the back button or Clear filters.
- **Only 50 rows are drawn**, with a note saying how many are left. A few
  thousand products across several lines would otherwise put tens of thousands
  of nodes on the page.

It makes a long list easy to *search*, not light to *load*: every option is
still sent to the browser. Past roughly a thousand products that becomes the
real cost, and the fix then is searching on the server.



### Conventions the modules share

- **Action colours** never change meaning: view is blue, edit is green,
  delete is red, warning is orange.
- **Deleting** always asks first, through `confirmDelete()` in `lib/swal.js`.
- **Field errors** show under the input. Alerts are for errors that belong to
  no single field.
- **Status** is a separate route and permission, so switching an account off
  is one click and is logged as Activate or Deactivate, not as an edit.
- **Pivot changes** (roles, permissions) are handed to the audit row with
  `$model->auditExtra` before saving, so one edit writes one trail row.
- **Slow figures** go in a service, cached, and reach the page through
  `Inertia::defer()` with a skeleton. See `DashboardMetrics`.

---

## 5. Settings

Add a field to `config/settings.php` and it appears on the settings page,
validated and saved. Nothing else to write.

```php
'members.code_prefix' => [
    'label' => 'Member code prefix',
    'type' => 'text',                 // text|textarea|email|tel|url
    'default' => 'MBR',               // number|toggle|select|color|image
    'rules' => ['required', 'string', 'max:8'],
    'hint' => 'Put in front of every new member code.',
],
```

Read it anywhere:

```php
settings('members.code_prefix');          // the value, or its default
settings()->set('members.code_prefix', 'M');
settings()->group('company');             // ['name' => ..., 'email' => ...]
```

Every value is read from one cached array, so a page reading twenty settings
costs one query at most. Saving clears the cache.

Two things to know:

- A form posts the **short** name (`values[code_prefix]`), not the full key.
  Laravel's validator reads a dot as a step into a nested array, so a full key
  would never match its rule. `SettingsSchema::fullKey()` puts the group back.
- The three empty groups (System Preferences, Notification Settings, API
  Settings) are registered and open. Give one a field and its page works.
- **`image` is the one type the Save button does not carry.** The company
  logo is one. Picking a file uploads it at once, through
  `POST settings/{group}/images/{field}`, and Remove goes through `DELETE`
  on the same address; both need `settings.update`. The form still holds
  the value from when the page opened, so saving it would put the old
  picture back over a fresh upload — `UpdateSettingsRequest` drops picture
  fields for that reason, and so a crafted request cannot point the logo at
  a path of its choosing either. `App\Support\SettingImage` is the only
  place such a file is written or deleted: on the `public` disk under
  `settings/`, shrunk to 512 pixels, JPG, PNG or WebP up to 2 MB, no SVG.
  The pixel work is `App\Support\ImageFile`, shared with product photos.
  A field with `'makes_icons' => true` (the logo) also gets the browser tab
  icon and the phone home-screen icon cut from it, in `settings/icons/`;
  `app.blade.php` uses them, or the shipped ones in `public/images` when no
  logo was uploaded. Like product photos, it needs the storage link and a
  backup of `storage/app/public`.

### The light and the dark screen (phase 35.1)

Each person picks **Light**, **Dark** or **Same as my device** with the
switch in the top bar (where a search box that searched nothing used to be;
icons only on a phone), or with the three icons in the till's header. The choice is kept on their account (`users.appearance`)
and follows them to any computer; somebody who never chose follows
**Theme Settings → Default appearance** (Light). It needs no permission —
it changes only the asker's own look — and writes no audit row.

| Where | Light screen | Dark screen |
|---|---|---|
| Every screen inside the app, the till included | as before | dark |
| Printed pages (quotation, order, statement, Reports, Aging) and the sale slip | white | **white** — the dark tokens sit inside `@media screen` |
| Sign-in, kiosk sign-in, the signed-out error page | their own design | **their own design** — a guest gets no theme |

**How to keep a new screen dark-ready.** Use the tokens and nothing else:
`bg-surface`, `bg-canvas`, `text-ink`, `text-ink-muted`, `border-line`, the
`brand`/`success`/`warning`/`danger`/`info` shades. Three habits break the
dark screen, and `tests/js/appearance.test.js` fails on each:

- a fixed white, black or Tailwind grey (`bg-white`, `text-black`,
  `bg-gray-100`) — use the token; white text on a coloured button is
  `text-ink-inverse`. `print:bg-white` is fine: paper is always white;
- `bg-ink` as a backdrop — ink turns light; use `bg-scrim`;
- `hover:bg-brand-700` and the like — `-700` is a light text shade on the
  dark screen; darken with `hover:brightness-90`.

A logo drawn for white paper takes `data-logo-chip`, which sits it on a
small white card on the dark screen. A chart drawn by ApexCharts goes
through `ChartPanel`, which recolours it with `darkChart()`.

Deploying it: `php artisan migrate` (the new column), `config:cache` (the
new setting) and the new `public/build`. No new permission.

---

## 6. Audit trail

`use Auditable;` on a model logs create, update and delete. For the rest:

```php
$member->auditView();                    // somebody opened it
$member->auditStatus($member->is_active); // switched on or off
$member->auditCustom(AuditAction::Update, 'Did something unusual.');
```

Keep fixtures and seeders out of the trail:

```php
app(AuditService::class)->withoutAuditing(fn () => /* ... */);
$this->unaudited(fn () => Member::factory()->create());   // in tests
```

Passwords and tokens are masked before anything is written. The page at
`/audit` is read-only and has exactly one route.

---

## 7. API v1

Stateless, token authenticated, versioned in the path.

```
POST   /api/v1/login              username, password, device_name
POST   /api/v1/logout             throws away the token used
POST   /api/v1/logout-all         throws away every token for the user
GET    /api/v1/me

GET    /api/v1/users              ?search= &sort= &direction= &per_page= &page=
GET    /api/v1/users/{id}
GET    /api/v1/roles
GET    /api/v1/roles/{id}

GET    /api/v1/products           the masters
GET    /api/v1/products/{id}
GET    /api/v1/categories
GET    /api/v1/units
GET    /api/v1/suppliers
GET    /api/v1/suppliers/{id}
GET    /api/v1/customers
GET    /api/v1/customers/{id}

GET    /api/v1/purchases          the documents
GET    /api/v1/purchases/{id}
GET    /api/v1/sales
GET    /api/v1/sales/{id}
GET    /api/v1/sales-returns
GET    /api/v1/sales-returns/{id}
GET    /api/v1/purchase-returns
GET    /api/v1/purchase-returns/{id}
GET    /api/v1/adjustments
GET    /api/v1/adjustments/{id}

GET    /api/v1/movements          the warehouse
GET    /api/v1/stock
GET    /api/v1/dashboard
```

Every list takes `?search=`, `?sort=`, `?direction=`, `?per_page=` and
`?page=`, and answers with the same `meta` block. `movements` also takes
`?product_id=`, `?type=`, `?from=` and `?to=`; `stock` takes
`?stock=held|low|out`. `products` also takes `?barcode=`, which matches the
barcode exactly (a scan), and its `?search=` reads the barcode, brand and
model too.

A list carries `lines_count`; only a single document carries its `lines`.
Forty documents of ten lines each would be four hundred rows nobody asked
for.

Sign in, then send `Authorization: Bearer <token>` on everything else.

```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H 'Accept: application/json' \
  -d 'username=admin&password=admin123&device_name=iPhone'
```

Every reply has the same shape:

```json
{ "success": true,  "message": "...", "data": {...}, "meta": {...} }
{ "success": false, "message": "...", "errors": { "field": ["..."] } }
```

`errors` is always a map of field to a list of messages, even for a failure
that has nothing to do with a field, so one branch in the client handles all
of them. Statuses: 401 no or bad token, 403 permission refused, 404 missing,
422 validation, 429 throttled.

Notes for whoever builds the mobile app:

- A login token carries the user's permissions as **abilities**, so the client
  can hide what the person cannot do without a second call. They are for the
  screen only: nothing checks `tokenCan`. Access is decided by the user's
  permissions as they stand at the moment of the request, so taking a
  permission away takes effect at once rather than when the token expires.
- Signing in again with the same `device_name` replaces that device's token.
- A token expires after `api.token_lifetime_days` on the Settings page, 30 by
  default. Sanctum's own `expiration` is left null in config, so the lifetime
  is written onto each token as it is issued.
- Routes carry the same `permission:` middleware as the web, so the API can
  never be a way around the roles. `ApiInventoryTest` fails if any route
  under `api/v1` lacks either `auth:sanctum` or a `permission:`.
- **Reading only, on purpose.** Saving a document moves stock the moment it
  posts, so posting one over the API deserves the idempotency key and the
  confirmation the web form already has. `ApiInventoryTest` fails if a
  writing route appears that is not login or logout, so adding one has to be
  a decision rather than an accident. Adding it means reusing the Form
  Request and the Policy the web controller already has.

### What cost the API sends

Cost is held back where cost means the **cost of goods sold**, and nowhere
else, which is exactly the rule the screens follow:

| Document | Cost figures | Behind `sales.view_cost` |
|---|---|---|
| Sale | `total_cost`, `gross_profit`, line `unit_cost` | yes |
| Sales return | `total_cost`, line `line_cost` | yes |
| Purchase | line `unit_cost` | no |
| Purchase return | `total_cost`, line `unit_cost` | no |
| Adjustment | `total_cost`, line `unit_cost` | no |

A delivery's unit cost is the price paid to a supplier, not a margin, and it
is readable on the web by anyone who may open the document. On a purchase
return it is the only value the document has, so hiding it would send a phone
a return worth nothing at all.

The same rule applies off the documents: a ledger row's `unit_cost` is held
back, because on a sale row it *is* the cost of goods. The audit trail is
held to it too — `SecurityTest` fails if a Sales trail row so much as names
a cost column, because the trail is readable with `audit.view`, which is a
different permission from `sales.view_cost`.

### The VAT fields

While the switch is on, a purchase, a sale and both kinds of return carry
`vat_rate`, `net_amount` and `vat_amount`, and each line carries `net_total`
and `vat_total`. With the switch off, none of them are sent, so a phone can
tell "no VAT" from "nobody told me". An adjustment never sends them. Every
one is a money string, like the rest.

### Adding an endpoint

1. A resource in `app/Http/Resources/` for the output shape.
2. A controller in `app/Http/Controllers/Api/V1/`, returning `ApiResponse`.
3. A route in `routes/api.php`, inside the `auth:sanctum` group, with its
   `permission:` middleware.

Keep lists cheap. `UserResource` leaves permissions out unless an endpoint
asks with `withPermissions()`, because working them out reads the role pivot —
on a page of 40 users that was 40 extra queries.

---

## 8. Security

What is in place, and where it lives:

| Area | How it is handled |
|---|---|
| Passwords | bcrypt, 12 rounds, via the model's `hashed` cast. Never serialized. |
| Sessions | Regenerated on login, invalidated on logout. Encrypted, http-only, `SameSite=Lax`. |
| CSRF | `ValidateCsrfToken` on every web route. The API is stateless and carries none. |
| XSS | Vue escapes by default. The few places that build HTML for an alert escape by hand. |
| SQL injection | Eloquent everywhere. `TableQuery` only sorts and searches on whitelisted columns; a crafted `?sort=` is ignored. |
| Authorization | Route middleware as the outer gate, a Policy per row inside. |
| Brute force | Login throttled per username and IP, on the web and the API. |
| Headers | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`; signed-in pages are `no-store`. |
| Tokens | Revoked on logout, one per device name, abilities from the user's permissions, and expiring after `api.token_lifetime_days`. |
| Seeding | `AdminUserSeeder` uses `firstOrCreate` and grants the role only on the first run, so a re-deploy cannot put the default password back or re-promote a demoted account. |

`tests/Feature/SecurityTest.php` keeps this honest. Two of its tests walk
every registered route and fail if one has no authentication gate, or writes
data without naming a permission — so a new route cannot be left open quietly.

### Before this goes live

1. `APP_ENV=production`, `APP_DEBUG=false`.
2. `SESSION_SECURE_COOKIE=true` and serve over HTTPS only.
3. Change the `admin` password; delete the account if it is not used.
4. `php artisan config:cache route:cache view:cache` and `npm run build`.
5. Point the web server at `public/`, not the project root.
6. Consider a Content-Security-Policy. There is none yet: the app prints an
   inline `<style>` for the theme and an inline `<script>` for the sidebar, so
   a strict policy needs a nonce on both. `SecurityHeaders` says so too.
7. Decide how long the audit trail is kept. Nothing prunes it yet.
8. **Revoke every token issued before the expiry was added.** They were
   written with `expires_at` null and never expire:

   ```bash
   php artisan tinker --execute="DB::table('personal_access_tokens')->whereNull('expires_at')->delete();"
   ```

9. Schedule the pruning of dead tokens, so the table does not grow forever:

   ```php
   // routes/console.php
   Schedule::command('sanctum:prune-expired --hours=24')->daily();
   ```

10. Check `api.token_lifetime_days` on the Settings page. 30 days is the
    default; a shorter life limits what a stolen phone is worth.
11. **Check PHP has the `bcmath`, `gd`, `zip` and `xmlwriter` extensions**
    (`php -m`, or the PHP settings in the host's panel) — for the PHP that
    serves the site, which can differ from the command line's.
    `composer install` refuses without them, since `composer.json` requires
    them; a server whose `vendor/` was copied by hand never asks. Without
    `bcmath` every Sales screen is a 500 (the first VPS deploy, 26 November
    2026). Without GD no photo is shrunk and no tab icon is cut from an
    uploaded logo, so the tab shows the shipped GO icon. Without `zip` the
    Reports spreadsheet and the backups are switched off; the screens say so.
    On Ubuntu: `sudo apt install php8.4-bcmath php8.4-gd php8.4-zip php8.4-xml`,
    then restart PHP-FPM.
12. **Add the scheduler's cron job** if backups should run by themselves:
    `* * * * * cd /path/to/inventory_app && php artisan schedule:run`. Then
    choose *Every day* under Settings → Backup and Export, make one backup
    by hand, download it, and **restore it once** into a spare database
    (README, "Backups") — that is the acceptance check's backup restoration.
13. **Keep `storage/app` on disk that survives a deploy, and run
    `php artisan storage:link`.** Git does not carry `storage/app/public`,
    so a deploy from GitHub arrives without the uploaded logo, the product
    photos, the loss-report photos and the backups; copy them across once.
    On a container platform (Coolify, Docker), mount `storage/app` as a
    persistent volume, or every redeploy deletes them again.

---

## 9. Performance

| Area | How it is handled |
|---|---|
| N+1 queries | List screens eager load. `PerformanceTest` loads each one with 5 rows and then 40, and fails if the query count grew. |
| Indexes | On every column a screen filters or sorts by. A test checks this. |
| Pagination | 25 rows by default, and only 10, 25, 50 or 100 are accepted. |
| Caching | Settings in one array; dashboard figures per block for five minutes, with a Refresh button. |
| Assets | Vendor code is split from app code, so a page change does not re-download the framework. ApexCharts loads only on pages with a chart. |
| First paint | Slow figures are deferred, so the shell and its skeletons appear at once. |

### The volume sweep

The query budgets above are measured on SQLite with 40 rows, which catches an
N+1 but says nothing about what MySQL does when a table is big. Phase 13
loaded a throwaway MySQL schema with 20,000 sales, 8,000 deliveries, 60,000
sale lines, 40,000 batches and 90,000 ledger rows across three years, and ran
`EXPLAIN` on the SQL the code actually issues — captured from the query log
while the real endpoints and services ran, not rewritten by hand.

What it found:

- **The inventory valuation read the whole table.** `InventoryValuation`
  added up every batch ever created and named no column to filter on, so
  nothing could be indexed: `type=ALL`, 39,824 rows, 3.5-9.8 ms, on every
  dashboard and every page of the Stock screen. It now asks only for batches
  with stock left — the same figure, since a used-up batch is worth nothing —
  and reads it from a covering index: 4,444 rows, 0.6-1.7 ms, without
  touching the table. That is the one index the migration
  `2026_10_18_100001_index_the_figures_the_reports_read` adds.
- **The yearly cards, the monthly chart and the top-seller list scan, and
  that is accepted.** Each filters on `status` and a date, and MySQL uses
  neither index, because nearly every document ends up `posted` and status
  therefore matches almost the whole table. Composite `(status, date)`
  indexes were tried on all four document tables and changed nothing: the
  optimiser still chose a full scan, correctly, since a quarter of a table
  fetched row by row costs more than one straight pass. They were taken out
  again rather than shipped as write cost for no gain. The figures are
  1-2.5 ms for the cards and 12-17 ms for the top sellers at this volume, and
  they are cached for five minutes. The migration records this so the same
  indexes are not re-added on the same reasoning.
- **The FIFO pick is sound.** It is a bounded range scan over the open
  batches of one product — eight rows at this volume — and the lock it takes
  on `products` is a primary-key hit.
- **No endpoint had an N+1**, and no column a screen sorts on was missing an
  index.

Two things were left alone deliberately, and are written down so they are not
re-discovered: a document list sorted by date still scans and sorts, because
`TableQuery` adds `id` as a tiebreaker and no index covers the pair; and
`paginate()` runs its own `count(*)`, 18 ms over 90,000 ledger rows. Neither
is worth an index until a screen is actually slow.

To run it again, the scripts are disposable: load a scratch schema, capture
the query log around the real code paths, `EXPLAIN` each statement, drop the
schema. Never point it at `db_maf_prototype`.

---

## 10. Tests

```bash
php artisan test      # the app: 545 feature tests
npm run test:js       # the front end: the ComboBox
```

Almost everything is tested from the server, which is cheaper and covers
more. Vitest exists for the one thing PHPUnit cannot reach: typing, arrow
keys and clicking away in `ComboBox`, which sits on the money forms. It is a
development dependency, so nothing about running or deploying the app
changes. `vitest.config.js` is kept apart from `vite.config.js`, which is
built around the Laravel plugin and wants a manifest a test run has no use
for.

```bash
php artisan test                         # everything
php artisan test --filter=SecurityTest   # one class
```

Feature tests, grouped by module, on SQLite in memory. Two things to know:

- Wrap fixture creation in `$this->unaudited(...)` so factory models do not
  land in the audit trail a test is counting.
- Never hard-code a permission count. Derive it from `PermissionRegistry`, or
  the next phase that adds a permission breaks your test.
# vpsinventory
