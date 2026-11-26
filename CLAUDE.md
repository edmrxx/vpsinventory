# MAF INVENTORY — Project Rules for Claude Code

The workflow rules (grammar correction, **REVIEW!**, **GO!**,
**FINISH NA BOSS!**, Simplified Technical English) live once in
`C:\Users\TTI-1230\.claude\CLAUDE.md` and apply to every project. They are not
repeated here.

This file holds only what is true of **this** project.

---

# Project Information

- System name: MAF INVENTORY
- Framework: Laravel 12
- Database name: db_maf_prototype
- Timezone/locale: Asia/Manila
- Default admin credentials: username `admin`, password `admin123`

---

# Current state

The baseline is **built and finished** — five phases, one git commit each:

1. Foundation, username session login, layout shell, SweetAlert2 helpers
2. Spatie permissions, the reusable DataTable, User and Role modules
3. Audit trail
4. Settings module and the dashboard
5. API v1 with Sanctum, security and performance review

The inventory system is being built on top of it, by phase:

6. **Done.** Masters: Products, Categories, Units, Suppliers, Customers
6.1 **Done.** The master forms moved from pages into a dialog on the list
7. **Done.** FIFO ledger: inventory layers, the movement ledger, the
   posting service, and the adjustment document opening stock arrives on
8. **Done.** Purchase of Goods, with void
8.1 **Done.** Every dropdown became a searchable combo box
9. **Done.** Sale of Inventory, with void and the margin
10. **Done.** Returns and Adjustments: sales returns, purchase returns,
    and the screens for the adjustment document built in phase 7
11. **Done.** Inventory Movement, the stock card, and Stock on Hand
12. **Done.** Dashboard and Reports
13. **Done.** API v1 for the new modules, security and performance review.
    661 PHP tests and 54 front-end tests pass; the index migration is
    applied to `db_maf_prototype` and `inventory:verify` is clean; and a
    login, a single-record read, a logout and a refused dead token were
    walked over real HTTP against MariaDB.

The client's scope (`SCOPE.md`) is being built on top of that, by phase:

14. **Done.** Decimal quantities (stage 1, first half). 757 PHP tests and
    63 front-end tests pass; the migration is applied locally and
    `inventory:verify` is clean.
15. **Done.** Unit conversions — a box of 12 adds 12 pieces (stage 1,
    second half). Stage 1 is finished. 777 PHP tests and 68 front-end tests
    pass; the migration is applied locally and `inventory:verify` is clean.
16. **Done.** Payments at the till (stage 2, first half): payment methods,
    cash received and change, split payment, the reference number, and
    Collections read from the payments. 821 PHP tests and 86 front-end
    tests pass; the migration is applied locally, `permissions:sync` has
    run, and `inventory:verify` is clean.
17. **Done.** Hold and recall, a customer at the till, and the printed
    slip (stage 2, second half). Stage 2 is finished. 842 PHP tests and 102
    front-end tests pass; the migration is applied locally and
    `inventory:verify` is clean. No new permission, so no
    `permissions:sync` on deploy.
18. **Done.** Credit limit, terms, a sale on account and the customer balance
    (stage 3, first half). 886 PHP tests and 122 front-end tests pass; the
    migration is applied locally, `permissions:sync` has run, and
    `inventory:verify` is clean. `customers.credit` is a new permission, so a
    deploy runs `permissions:sync`.
19. **Done.** Customer payments against invoices, the statement of account,
    aging, the overdue and over-limit flags, the Overdue card (stage 3,
    second half). Stage 3 is finished. 925 PHP tests and 133 front-end tests
    pass; the migration is applied locally, `permissions:sync` has run, and
    `inventory:verify` is clean — it now checks every sale's balance too.
    `customer_payments.*` are new permissions, so a deploy runs
    `permissions:sync`.
19.1 **Done.** Collectibles — every invoice still owed, filtered by month
    (due or sold), customer, status and staff, with totals over the filtered
    rows. Asked for by the client (12 November 2026, request 5); reads
    `CustomerCredit::openInvoices()`. 931 PHP tests and 133 front-end tests
    pass. No migration, no new permission.
21. **Done.** Purchase requests with approval, and the Purchase requests
    card (stage 5, first phase). The user chose stage 5 ahead of stage 4 on
    12 November 2026, after the client's requests of that day. 955 PHP tests
    and 133 front-end tests pass; the migration is applied locally,
    `permissions:sync` has run, and `inventory:verify` is clean.
    `purchase_requests.*` are new permissions, so a deploy runs
    `permissions:sync`.
22. **Done.** Purchase orders — from an approved request or directly, the
    printed order, outstanding orders, cancel with a reason (stage 5).
    971 PHP tests and 133 front-end tests pass; the migration is applied
    locally, `permissions:sync` has run, and `inventory:verify` is clean.
    `purchase_orders.*` are new permissions, so a deploy runs
    `permissions:sync`.
23. **Done.** Receiving against an order — partial deliveries, more than
    ordered refused, goods not on the order and cost changes shown, closed
    short (stage 5). 981 PHP tests and 133 front-end tests pass; the
    migration is applied locally and `inventory:verify` is clean — it now
    checks every order line too. No new permission: `purchase_orders.cancel`
    also closes short.
24. **Done.** Supplier payables — bills on account with due dates, supplier
    payments against bills, supplier credit from returns, the Payables list
    and card (stage 5, last phase). **Stage 5 is finished.** 998 PHP tests
    and 133 front-end tests pass; the migration is applied locally,
    `permissions:sync` has run, and `inventory:verify` is clean — it now
    checks every bill too. `supplier_payments.*` are new permissions, so a
    deploy runs `permissions:sync`.
28. **Done.** Quotations — a price offered until a date, printed for the
    customer, turned into a sale on the sale form without typing again,
    quoted again at today's prices, cancelled with a reason (stage 7, first
    phase). The user took stage 7 ahead of stages 4 and 6 on 12 November
    2026 (the client's request 2). 1,015 PHP tests and 133 front-end tests
    pass; the migration is applied locally, `permissions:sync` has run, and
    `inventory:verify` is clean. `quotations.*` are new permissions, so a
    deploy runs `permissions:sync`.
29. **Done.** Sales orders with reserved stock; reserved and available
    quantities (stage 7, last phase). **Stage 7 is finished.** 1,031 PHP
    tests and 133 front-end tests pass; the migration is applied locally,
    `permissions:sync` has run, and `inventory:verify` is clean.
    `sales_orders.*` are new permissions, so a deploy runs
    `permissions:sync`.
34. **Done.** Report filters — Cashier and Payment method — Money received,
    the printed page (Print / PDF) and the Excel spreadsheet (stage 9, first
    phase). The user took stage 9 ahead of stages 4, 6 and 8 on 13 November
    2026. 1,047 PHP tests and 133 front-end tests pass. No migration and no
    new permission, so a deploy needs neither `migrate` nor
    `permissions:sync` — but it does need `config:cache` (a permission's
    wording changed), `route:cache` where routes are cached (or
    `/reports/export` is a 404), the new `public/build`, and PHP's `zip`
    extension on the server.
35. **Done.** Print layouts — one letterhead and footer for every printed
    page, paper size, margins, terms — and backups — made now or on a
    schedule, downloaded, kept to a number, restored by hand (stage 9, last
    phase). **Stage 9 is finished.** 1,063 PHP tests and 141 front-end tests
    pass, and a backup of the development books was restored into an empty
    MariaDB database and passed `inventory:verify`. No migration.
    `backups.*` are new permissions, so a deploy runs `permissions:sync`; it
    also needs `config:cache`, the new `public/build`, a writable
    `storage/app/private`, and — for backups on a timetable — the
    `schedule:run` cron job.
24.1 **Done.** Departments, and printing a purchase request and its Request
    for Quotation — the first phase of the client's canvass and PO approval
    request of 13 November 2026 (CR-1 in `SCOPE.md`). 1,077 PHP tests and
    146 front-end tests pass; the migration is applied locally and
    `permissions:sync` has run. `departments.*` are new permissions, so a
    deploy runs `migrate` and `permissions:sync`.
24.2 **Done.** The canvass — suppliers' prices against an approved
    request, the Abstract of Quotation (screen and paper), the award per item
    with a reason for a dearer choice, reopening it. Built on the user's GO!
    on all seven recommendations (Q1–Q7, 14 November 2026). 1,093 PHP tests
    and 151 front-end tests pass; the migration is applied locally and
    `permissions:sync` has run. `canvass.*` are new permissions.
24.3 **Done.** Orders from the award — one purchase order per winning
    supplier, at the quoted prices, the request closed as ordered on all of
    them. 1,099 PHP tests and 151 front-end tests pass; the migration is
    applied locally. No new permission (`purchase_orders.create` places them).
24.4 **Done.** Purchase-order approval — an order waits *For approval* until
    the approver approves it or rejects it with a reason; nothing is received
    against it before, and it prints only as *DRAFT — NOT APPROVED*; the
    approver's own is approved as it is placed (Q6, Q7). **CR-1 is finished.**
    1,109 PHP tests and 154 front-end tests pass; the migration is applied
    locally, `permissions:sync` has run, and `inventory:verify` is clean.
    `purchase_orders.approve` is a new permission, so a deploy runs
    `migrate`, `permissions:sync` and `config:cache` (a setting's wording
    changed).
30. **Done.** Product brand, model or size, barcode and preferred supplier
    (stage 8, first phase), taken ahead of stages 4 and 6 on the user's GO!
    of 20 November 2026. 1,129 PHP tests and 169 front-end tests pass; the
    migration is applied locally. No new permission, so a deploy runs
    `migrate` only.
31.1 **Done.** Locations — the list of stores and warehouses (Masters →
    Locations), the first of phases 31.1 to 31.5, split on the client's
    concern of 19 November 2026 (a delivery is received at the store or the
    warehouse). Built on the user's GO! of 20 November 2026. 1,143 PHP tests
    and 169 front-end tests pass; the migration is applied locally and
    `permissions:sync` has run. `locations.*` are new
    permissions, so a deploy runs `migrate` and `permissions:sync`.
31.2 **Done.** Stock per location — every batch and movement names one,
    `location_stocks` beside the total, a sale eats only its own location's
    batches; Main Store is the default and holds everything on the books.
    Built on the user's GO! of 20 November 2026 with defaults while decision 4
    stays open. 1,165 PHP tests and 169 front-end tests pass; the migration is
    applied locally (12,879.5 units moved, `inventory:verify` clean before and
    after). No new permission, so a deploy
    runs `migrate` only.
31.3 **Done.** Receive at, and Deliver to — a delivery is received at the
    location picked; a purchase order may name where to deliver. Built on the
    user's GO! of 20 November 2026 with defaults while decision 4 stays open.
    1,177 PHP tests and 171 front-end tests pass; the migration is applied
    locally (430 deliveries set to Main Store) and `inventory:verify` is
    clean. No new permission, so a deploy runs `migrate` only.
31.4 **Done.** Sell, return and adjust at a location — a sale's *Sell from*,
    the till at a store, a sales order's *Hold at* with reservations per
    location, an adjustment's *Location* (opening stock too), and both returns
    showing their place. Built on the user's GO! of 21 November 2026 with
    defaults while decision 4 stays open. 1,199 PHP tests and 171 front-end
    tests pass; the migration is applied locally (3,139 sales, 30 adjustments
    and 1 sales order set to Main Store, none at odds with its movements) and
    `inventory:verify` is clean. No new permission, so a deploy runs
    `migrate` only.
31.5 **Done.** Transfers between locations — stock moved from *From* to *To*
    at the cost it already carries, one batch at the destination per batch it
    ate, reserved stock left where it is, voided while what arrived is
    untouched. **Phase 31 is finished.** Built on the user's GO! of 22
    November 2026 with defaults while decision 4 stays open. 1,217 PHP tests
    and 171 front-end tests pass; the migration is applied locally,
    `permissions:sync` has run, and `inventory:verify` is clean.
    `transfers.*` are new permissions, so a deploy runs `migrate`,
    `permissions:sync`, `config:cache` (a new setting, the transfer prefix),
    `route:cache` where routes are cached (or `/transfers` is a 404), and the
    new `public/build` (new pages).
32. **Done.** The count sheet — a count at one location from a cutoff, a
    blind count for the counters, the differences for approval, posted as
    adjustments (reason *count*) once approved, sent back or cancelled with a
    reason. Built on the user's GO! of 23 November 2026 with defaults.
    1,236 PHP tests and 173 front-end tests pass; the migration is applied
    locally, `permissions:sync` has run, and `inventory:verify` is clean.
    `stock_counts.*` are new permissions, so a deploy runs `migrate`,
    `permissions:sync`, `config:cache` (a new setting, the count prefix),
    `route:cache` where routes are cached (or `/stock-counts` is a 404), and
    the new `public/build` (new pages).
33. **Done.** Loss reports — damaged, expired, rusted, stolen or missing
    goods reported with photos, held aside at their location until an
    approver writes them off (a decrease adjustment with the same reason) or
    rejects the report; three new reasons. **Stage 8 is finished.** Built on
    the user's GO! of 23 November 2026 with defaults. 1,251 PHP tests and
    173 front-end tests pass; the migration is applied locally,
    `permissions:sync` has run, and `inventory:verify` is clean.
    `losses.*` are new permissions, so a deploy runs `migrate`,
    `permissions:sync`, `config:cache` (new settings: the loss prefix and
    Settings → Losses), `route:cache` where routes are cached (or
    `/loss-reports` is a 404), the new `public/build` (new pages), a
    writable `storage/app/private` (the evidence photos), and PHP's
    `upload_max_filesize` at least `4M` and `post_max_size` at least `25M`
    (five photos of up to 4 MB).
18.1 **Done.** Terms agreed per sale — *Terms (days)* beside the amount on
    account, starting on the customer's usual terms; fewer days anybody may
    give, more needs `customers.credit`; kept on the sale as `terms_days`; a
    customer with no credit is told why there is no *On account*. Built on
    the user's GO! of 24 November 2026. 1,269 PHP tests and 196 front-end
    tests pass; the migration is applied locally (76 sales on account given
    their days, no due date moved) and `inventory:verify` is clean. No new
    permission, but `customers.credit`'s wording changed, so a deploy runs
    `migrate` and `config:cache`, and needs the new `public/build`.
17.1 **Done.** Pending payment (CR-2, the client's meeting of 24 November
    2026) — the kiosk sends the order to the cashier instead of taking the
    money; its goods are held at the store until paid, cancelled or closing;
    the cashier takes the money on the till's Pending tab at exactly the
    lines and prices sent; a NOT PAID slip. Built on the user's GO! on Q1–Q5.
    1,306 PHP tests and 220 front-end tests pass. The migration is applied locally, `permissions:sync` has run, and
    `inventory:verify` is clean. Then, the same day, on the user's GO!: it
    **comes with the kiosk** — anyone with `kiosk.use` sends one, anyone with
    `sales.create` takes its money or cancels any — because the client's own
    *Sales Encoder* role never got a separate tick. `pending_payments.view`
    (the back-office list) is the one new permission, so a deploy runs
    `migrate`, `permissions:sync`, `config:cache` (two new settings and a
    reworded permission), `route:cache` where routes are cached (or
    `/pending-payments` is a 404), and the new `public/build`.
36. Next: stages 4 and 6 unless the user reorders them; the pending purchase
    quantities on the Stock screen (stage 5's last box) is a small
    follow-up. Each waits on GO!.

**`README.md` is the working document.** Before building anything, read its
section 4 (the CRUD standard) and follow the pattern already in place. Do not
invent a second way to do something this project already does.

The three declared lists that drive the app:

| File | What it declares |
|---|---|
| `config/permissions.php` | Every permission. Run `php artisan permissions:sync` after editing. |
| `config/settings.php` | Every setting: group, label, type, default, rules. |
| `resources/js/navigation.js` | The sidebar, with the permission each item needs. |

Every pick list is `Components/ComboBox.vue`, which can be typed into. There
are no raw `<select>` elements; `SelectField` and `FilterSelect` wrap it.
README section 4, step 6 has the detail.

A short master form opens in a dialog on its list screen; a document form
with line items keeps a page of its own. **Reading is different: every list
opens its record in a dialog** — purchases, sales, adjustments, both returns,
and a product from the Stock screen — and the movement ledger opens the
document behind a row the same way, as does a customer page's Recent sales.
The pages all remain, every dialog links to its own with "Open full page",
and the dialog is a summary rather than a cramped copy. README section 4,
step 6 has the rule and the reason it changed. Since 24 November 2026
**Customers, Suppliers and Products open in a dialog on their lists too**;
Users and Roles still use pages, and stay that way unless asked.

**Every list remembers where you were** (24 November 2026, on the user's
GO!). `lib/listMemory.js` is the only place it is kept: `DataTable` writes
down its own list's address (sort, filters, search, page), scroll and last
row (marked on return); `PageHeader` shows a breadcrumb from `back-href`
(or `crumbs`), and it and `PrimaryButton` send a bare list address to the
remembered one. The sidebar always opens a list fresh. `sessionStorage`,
every access wrapped; nothing per page — never hand-build a back link that
bypasses `PageHeader`.

A dialog reads `/<module>/{id}/preview`. That endpoint must never build its
own payload: the controller holds one `detail()` method, `show()` renders it
through Inertia and `preview()` returns it as JSON. Two copies would drift,
and the cost gating would drift with them. `DocumentPreviewTest` compares
the two field by field and fails if they differ.

The sidebar carries a **Masters** group with the five modules of phase 6.
Every item in the sidebar is now a real screen, Reports included. (Phase 11
said this already and was wrong: Insights → Reports was still disabled.)

Two services own the figures, and nothing else may work them out again:

- `App\Services\InventoryValuation` — what the stock is worth, from what is
  left of each batch times what that batch cost.
- `App\Services\InventoryMetrics` — net sales, net purchases, the monthly
  chart, top sellers and low stock. The dashboard and the reports both read
  it, so the two screens cannot disagree.

Net sales is posted sales less returns; net purchases is posted deliveries
less returns; a return counts against the month it happened in, not the
month of the document it undoes; and **opening stock and adjustments are not
purchases**. A voided document counts for nothing anywhere.

The ledger flushes those caches with `DB::afterCommit()`, from all five of
its entry points, so a posted document shows at once. Flushing inside the
transaction would let a concurrent read cache the state from before it.

Two things that differ between MySQL and SQLite, and bit once each:

- A `date` cast writes a full datetime. MySQL's DATE column truncates it,
  SQLite keeps it, so a plain `BETWEEN '2026-01-01' AND '2026-10-18'` drops
  everything posted today on SQLite. Compare against the end of the day.
- SQLite gives a DECIMAL column integer affinity, so `1800 / 2000` is 0
  there and 0.9 on MySQL. Multiply before dividing.

Movements and Stock are read-only and have no write routes, the same shape
as the audit trail. The movement list sorts by **id**, not by date:
`TableQuery` adds the id ascending as its tiebreaker, so a date sort would
put same-day rows oldest first and the running balance would read backwards.

A return is never voided, edited or deleted: undoing an undo leaves a
history nobody can follow. One entered wrongly is corrected with another
return or with an adjustment. A sale or purchase that has had goods back
can no longer be voided either, or the stock would be counted twice.

**Opening stock for going live** is entered through Adjustments, with the
kind set to *Opening stock*.

What a sale cost, and so what it earned, is held back behind
`sales.view_cost`. The server leaves those figures out of the payload for
anyone without it, rather than the screen hiding them once they arrive.

That rule means the **cost of goods sold** — a sale, and a sales return,
which comes back at what the goods cost. A delivery's unit cost is the price
paid to a supplier, not a margin, and it is not gated; on a purchase return
it is the only value the document has. README section 7 has the table.

The API v1 added in phase 13 reads and does not write. Posting a document
moves stock at once and deserves the idempotency key and the confirmation the
web form has, so that was left as a decision for later rather than slipped
in. `ApiInventoryTest` fails if a writing route appears under `api/v1` that
is not login or logout, and fails if any route there lacks `auth:sanctum` or
a `permission:`. A token expires after `api.token_lifetime_days`.

`AdminUserSeeder` must stay `firstOrCreate`, and must grant the Admin role
only when it has just created the account. It runs again on every deploy that
calls `db:seed`; an update would put the default password back and re-promote
an account somebody had deliberately demoted.

A document module is shaped like `PurchaseController` and `PurchasePoster`:
no edit and no delete route anywhere, a void while the stock is untouched,
and a return once it has moved. README section 4 has the detail.

Decisions already taken for the phases ahead, so they are not reopened:

- **A quantity may carry up to three decimals — only where its unit
  allows it** (decided 8 November 2026, decision 1 in `SCOPE.md`).
  `units.allows_decimals` is the switch: kg, m, ltr, cu.m and sq.m are
  seeded on, everything else (ft too) whole, and 2.5 pcs is refused on
  every form and again in the ledger. Every quantity column is
  DECIMAL(15,3), and **all quantity arithmetic is done in whole
  thousandths through `App\Support\Quantity`** — never add, subtract or
  compare two quantities as they arrive, and never `(int)` one: 2.5 would
  become 2 without a word. The ledger writes each batch and product with
  its exact new figure rather than `increment()`, because SQLite adds a
  DECIMAL as a float. `QuantityCast` reads a whole quantity as an int, so
  10 stays 10 on every screen and payload; a raw query or `SUM()` goes
  through `Quantity::normalize()`. A line is priced with
  `Quantity::lineTotal()` (half up, to the centavo), and `lineTotal()` in
  `money.js` is the same sum. A unit's switch cannot go off, and a product
  cannot move onto a whole unit, while it holds a part.
- **A product may be bought and sold in other units** (decided 8 November
  2026, decision 1 in `SCOPE.md`). `product_units` lists each one: the unit,
  `factor` (how many of the product's own unit it holds — more than 1, and
  whole when that unit is whole) and an optional `selling_price`; left
  empty, a box sells at the piece price × the factor. **The stock never
  leaves the product's own unit.** A sale or purchase line keeps `quantity`
  in that unit — the figure the ledger, the returns and the reports read —
  and beside it what was typed: `unit_quantity` of `unit_id` at
  `unit_factor` each, with `unit_id` null for the product's own unit. The
  factor is copied onto the line, so changing a box later never changes an
  old document. `App\Support\UnitConversion` is the only place a box
  becomes pieces: the form request asks it to put the error against the
  line, the poster asks it again inside the transaction and decides. It
  multiplies through `Quantity::times()` (bcmath), and refuses a line that
  comes to half a piece or to more than three decimals. A sale line's
  `unit_price` is per typed unit. A purchase line's `unit_price` is per
  typed unit and its `unit_cost` is **per piece** — the line total over the
  pieces, half up to the centavo — because that is what the batch, the
  product's cost and a purchase return use. A box of 12 at 100 is therefore
  8.33 a piece and the batch is worth 99.96; a purchase return of a line
  typed in another unit gives back its share of the line total, and the
  last pieces back take what is left of it, so a whole box returns exactly
  100.00. **Returns count in pieces; adjustments and opening stock count in
  the product's own unit only** and ignore a `unit_id`. The product form
  sends its whole list every time and a missing list means none, so every
  product form must send `units`. A unit used as another unit, or named on
  a document line, cannot be deleted. A unit's "Allows decimals" cannot go
  off while one of its products is sold in another unit holding part of it
  (a roll of 75.5 m).
- **A product may carry a brand, a model or size, a barcode and a preferred
  supplier** (phase 30, built on GO! with these defaults). All four optional.
  The brand is free text with the brands already used offered as
  suggestions, not a master list. **The barcode is one per product** (unique,
  any number may have none) and stored in capitals through `MasterInput`,
  like a SKU. **The preferred supplier is a suggestion**: active when picked,
  kept when switched off later, cleared when the supplier is deleted
  (`nullOnDelete`), never a reason to refuse a delete. **A box has no barcode
  of its own yet** — a scan always adds one of the product's own unit.
  `Product::searchWords()` is the one place the brand, model and barcode are
  joined for a search; every product list a form reads sends it as
  `keywords`, and `ComboBox` searches it and picks the **exact** barcode on
  Enter. The till's search reads the same fields, and Enter adds the product
  whose barcode, or SKU, is exactly what was typed (`lib/scan.js`). A new
  purchase request fills in the first product's usual supplier until
  somebody picks one by hand (`lib/usualSupplier.js`); orders and deliveries
  do not change their supplier by themselves. The API sends the four and
  takes `?barcode=`.
- **A location is a place that holds stock** (phase 31.1, built on GO!
  with these defaults while decision 4 stays open). `locations` is a master
  list shaped exactly like Departments — dialog form, no detail screen,
  switched off rather than deleted once something names one; name unique,
  `type` the enum `LocationType` (**Store** or **Warehouse**), address and
  description optional. **Main Store** and **Warehouse** are put in by the
  migration, not a seeder; `data:reset` empties the list like every master.
  `locations.*`: **Admin keeps the list, Staff read it.**
  `Location::options($keep)` is the pick list documents will read.
- **Stock is counted at each location** (phase 31.2, built on GO! with these
  defaults while decision 4 stays open). Every batch and movement carries
  `location_id`; `location_stocks` holds each product's figure at each place;
  **`products.on_hand` stays the total**, and every screen keeps reading it.
  `InventoryLedger` is the only writer of all three. **Only `post()` takes a
  location** (`$at`, the default when null); `reverseReceipt`,
  `reverseIssue`, `restoreConsumed` and `issueFromLayers` follow the batches
  they touch — never the default. A sale eats only its own location's
  batches (`InventoryLayer::fifo($product, $location)`) and is refused past
  that location's figure, naming what sits elsewhere. The product rows stay
  the one lock; `location_stocks` is read with `lockForUpdate` after them and
  written exactly. `balance_after` stays the product total. **One location
  is the default** (`is_default`, never mass-assigned): the migration chose
  Main Store and put everything on the books there — a default taken, which
  **reverses the earlier suggestion of the Warehouse**, because the till must
  keep selling. The default never switches off or deletes and **does not
  change while it holds stock**; a location holding stock stays on; one on a
  batch or movement is never deleted (`LocationPolicy`, `Location::withUsage()`
  on the list). `data:reset` wipes `location_stocks` and puts back one
  default Main Store (`MasterDataSeeder::defaultLocation()`); with no default
  the ledger refuses with `InventoryException::noDefaultLocation()`.
  `inventory:verify` runs `reconcileLocations()`. Reservations are held at
  a location since 31.4 (see below). Stock moves between places only by a
  transfer (31.5, see below).
- **A delivery is received at a location; an order says where to deliver**
  (phase 31.3, built on GO! with these defaults). `purchases.location_id` is
  NOT NULL (every delivery from before was set to where its batches sit):
  the form's **Receive at** is required and any active location; **a form
  sent with no `location_id` key at all posts at the default** (a stale tab,
  as a sale with no `payments` key is paid in cash), an empty one is refused.
  `PurchasePoster::receivingLocation()` reads it with a **shared lock** after
  the order row and before the number, and hands it to `post()`; a void
  needs none (the ledger follows the batches). `matchesSaved()` compares it.
  `purchase_orders.deliver_to_location_id` is **optional**, the form starting
  on the default; orders from an award deliver to the default; it prints on
  the order (with the address) only when set, so an old order reprints as
  before (`PurchaseOrderPrint.vue`, guarded in `tests/js`). *Receive
  delivery* starts on the order's place unless it was switched off, else on
  the default; the receiver may change it. The order's dialog shows
  **Received at** (the places of its standing deliveries) and its page the
  place on each delivery — read off the deliveries, never written onto the
  order. No new permission.
- **Every document that moves stock names its place** (phase 31.4, built on
  GO! with these defaults while decision 4 stays open). `sales.location_id`
  (*Sell from*), `inventory_adjustments.location_id` (*Location*, opening
  stock too) and `sales_orders.location_id` (*Hold at*) are NOT NULL; the
  migration set each sale and adjustment to where its **first** movement
  happened and each order to the default. Returns take no column: a sales
  return goes back into the batches its sale ate (*Back to* = the sale's
  place), a purchase return leaves its delivery's batch (*Taken from*).
  **The 31.3 rules again**: no `location_id` key at all posts at the default,
  an empty or switched-off one is refused, the poster reads it with a
  **shared lock before the number** (`SalePoster::sellingLocation()`,
  `AdjustmentPoster::location()`, `SalesOrderDesk::holdingLocation()`), and
  `matchesSaved()` compares it. **The till sells only from an active
  Store** (`?location=`, else the default, else the first store; the default
  when there is no store) — a default taken, the client's answer still
  waits — with a picker only for two or more stores, locked while the
  basket holds anything, remembered in the browser as a convenience; a till
  sale returns to `/pos?location=`. The sale form may sell from **any**
  active location, the warehouse too, and starts on the order's place when
  it fulfils one. **Reservations are per location**:
  `Reservations::reserved(..., locationId:)` and `Reservations::atLocation()`
  (one grouped read; `locking:` takes a shared lock after the product rows)
  are the only reads; `reserve()` weighs what is free at the order's place
  and refuses a place switched off since; `StoreSaleRequest` and
  `SalePoster::refuseTakingReservedStock()` weigh the sale's own place.
  `Product::sellingOptions($withCost, $exceptOrder, $locationId)` gives that
  place's figures; without a location (quotations) the totals.
  `Location::startingAt()` is the one rule for where a form starts. A form's
  refusal names the place and what more is at other locations. **A location
  named by any order counts as history** — a pending sales order, a purchase
  order's *Deliver to* — so it is never deleted (the FK would refuse it).
  The Stock screen shows Reserved and Available at the location chosen and
  drops the category column then. No new permission.
- **A loss is reported, held aside, and written off only once approved**
  (phase 33, built on GO! with these defaults). `loss_reports` /
  `loss_report_lines` / `loss_report_photos`; **not a posted document** —
  its own enum `LossReportStatus` (pending shown *Awaiting approval*,
  approved shown *Written off*, rejected, cancelled shown *Withdrawn*);
  prefix `documents.loss_report_prefix`, **`LR`**.
  `App\Services\LossReportDesk` is the only place a report changes.
  **The hold is logical, not a place**: a pending report's lines are added
  into `Reservations::reserved()` — with no date, until answered — so every
  sale, till, transfer and sales order refuses them already;
  `Reservations::heldForLosses()` reads them alone and
  `Reservations::describe()` words a refusal ("7 reserved for customer
  orders and 3 held for a loss report"); the Stock screen's column is
  *Reserved or held*. A separate damaged-goods location was not built and
  would be a decision of its own. **Reporting** locks the products
  (ascending), then the location (shared), then reads what is free with a
  locking read, then the number; only what is free may be reported.
  **Reasons are one list**, `StoreAdjustmentRequest::REASONS`: phase 33
  added `expired`, `rust`, `theft` and relabelled `lost` *Lost or missing*
  (key unchanged); a report offers `LOSS_REASONS` (no count, no opening).
  **Photos** — up to 5, JPG/PNG/WebP, shrunk by `ImageFile`, SVG refused —
  sit on the **private** disk under `losses/` (`App\Support\LossPhoto` the
  only writer), served only by `/loss-reports/{id}/photos/{photo}` behind
  `losses.view`, the path read from the report's own photo row. **Required
  for damaged, expired, rust and write_off** (`PHOTOGRAPHABLE`) while
  `losses.photo_required` (Settings → Losses) is on, the default; never for
  theft or lost. **Every loss test fakes the `local` disk.** **Approving**
  posts one decrease through `AdjustmentPoster`, today, at the report's
  location, with its reason, copying the cost back; a write-off does not
  look at holds, so goods gone meanwhile make the ledger refuse and the
  report keeps waiting. **Reject** (reason) and **withdraw** (reason; the
  reporter their own, an approver any) free the goods. Approving one's own
  is allowed and the trail says so. **Held goods are still on the books**,
  so a count includes them: count the set-aside goods too — the count sheet
  and its printout say so, and the approver sees each line's held amount.
  `losses.view/create/approve`: **Staff view, report and withdraw their own;
  writing off stays with Admin**, who may still post a decrease adjustment
  directly. A product or location on a report is never deleted. `data:reset`
  empties the tables and deletes the photos by name; **a backup carries them
  under `private/losses/`**. No dashboard card and no API endpoint yet.
- **Stock is counted with a count sheet** (phase 32, built on GO! with these
  defaults). `stock_counts` / `stock_count_lines`; **not a posted document**
  — its own enum `StockCountStatus` (counting, submitted shown *For
  approval*, approved, cancelled), never `DocumentStatus`; prefix
  `documents.stock_count_prefix`, **`CNT`**. `App\Services\StockCountDesk`
  is the only place a count changes, every step with the count row locked.
  **Starting it is the cutoff**: every active product at one location (one
  category when picked) plus anything switched off still on hand there,
  `system_quantity` read after a shared lock on the product rows. **One open
  count per location** (`start()` locks the location row). Counted figures
  may be entered and changed **only while counting** — the one edit, because
  nothing is posted; **null is not counted, never zero**; the usual quantity
  rules. **Blind on the sheet only**: while open, a user without
  `stock_counts.approve` is sent no book figure, difference or over/short
  summary on the count — the server leaves them out; the printed sheet
  (`CountSheetPrint.vue`, guarded in `tests/js`) never carries one. Staff
  still read on hand on the Stock screen and the till; hiding that during a
  count was not built and would be a decision of its own.
  **Approving posts counted less the cutoff figure, never counted less what
  is there now**, so sales after the cutoff stand and the till never stops;
  the approver sees each line's change since the cutoff. It posts **through
  `AdjustmentPoster`**, dated today at the count's location, reason
  `count`: one *increase* for what was over at each product's cost, one
  *decrease* for what was short off the oldest batches; nothing different
  posts nothing; ids on `gain_adjustment_id` / `loss_adjustment_id`, each
  line's `variance` and `variance_value` copied back. A shortfall bigger than
  the place holds now is refused by the ledger and the count keeps waiting.
  Uncounted lines stay as they are. Approving one's own is allowed and the
  trail says *their own*, as with sales orders. **Send back** (reason)
  returns it to counting with the figures kept; **cancel** (reason) while
  open — the approver any, a counter their own. `stock_counts.view/create/approve`:
  **Staff view, count, hand in and cancel their own; approving stays with
  Admin**, as recording an adjustment does. A product or location named on a
  count is never deleted — so after the first full count, a product active
  then is only ever switched off; any count also stops `sample:clear`. A
  count's decrease shows under Losses (reason *count*). No dashboard card and
  no API endpoint yet.
- **Stock moves between locations by a transfer** (phase 31.5, built on
  GO! with these defaults while decision 4 stays open). `stock_transfers` /
  `stock_transfer_lines` — a posted document shaped like an adjustment:
  posted on save, no edit and no delete, prefix `documents.transfer_prefix`
  (**`TR`**), idempotency key, voided with a reason (`void_reason`). **One
  step, no approval, no *in transit***. Both places required, active and
  different; read by `TransferPoster` with a **shared lock, in id order,
  before the number** — and **no "no key posts at the default" rule**, the
  form being new. Lines in the product's own unit only, like an adjustment.
  **`InventoryLedger::transfer()` is the only way stock changes place** —
  never two `post()` calls: out of the source oldest batch first (*Transfer
  Out*, with consumptions), and **one new batch at the destination per batch
  eaten, at that batch's own cost** (*Transfer In*) — never one averaged
  batch, and never a batch's `location_id` changed. The new batch names its
  parent (`inventory_layers.transferred_from_layer_id`). The total and the
  stock value do not move. **Reserved stock at the source is not moved**:
  `StoreTransferRequest` refuses it and `TransferPoster` asks again after the
  ledger, as `SalePoster` does. **A void** (`reverseTransfer()`) only while
  what arrived is untouched, and never taking what orders hold at the
  destination: it zeroes the new batches (*Transfer Voided (-)*) and puts
  the goods back into **the batches they left** (*Transfer Voided (+)*);
  otherwise the goods go back with another transfer. **A purchase return or
  void takes from the delivery's own batch only**, so goods transferred away
  are returned or voided only after the transfer is voided — a transfer back
  opens a new batch and does not refill it. Both refusals name the transfer
  (`InventoryLedger::transfersHolding()`, `InventoryException::movedByTransfer()`)
  and offer its void **only while what it brought in is untouched**; once it
  has been sold or moved on there, they say those goods can no longer be
  returned or voided against this delivery — **a known limit**, not a bug: a
  return that follows a moved batch is not built (the parent link allows it
  later). `transfers.view/create/void`:
  **Staff view and create** (it changes no total), void stays with Admin.
  Transfers count in no figure — not bought, sold or lost. No API endpoint
  yet. A movement row opens the transfer (`DocumentLink`).
- A discount is per document, not per line.
- **A sale records how it was paid** (phase 16, decided 9 November 2026 on
  the three recommendations: Cash, GCash, Maya and Bank transfer to start;
  a reference number required for GCash, Maya and Bank; refunds on a sales
  return stay cash until stage 6). `sale_payments` holds one row per method:
  `amount` is what it put towards the sale, so the rows plus `on_account`
  add up to the total exactly, and on the cash row `tendered` is what was
  handed over — change is the gap. **Cash may be more than is owed; nothing else may**, because
  nobody gives change from GCash. One cash row per sale. There is exactly
  one cash method, and it is never switched off, turned into another kind
  or deleted; a method any sale used is never deleted either.
  `App\Support\PaymentSettlement` is the only place a payment is weighed —
  the request asks it for the error, `SalePoster` asks it again inside the
  transaction and decides — and `App\Support\SaleTotals` is the only place
  a sale's total is worked out, in centavos, for all three. `settle()` in
  `resources/js/lib/payments.js` draws the same sums on screen. **A sale
  sent with no `payments` key is paid exactly in cash** (the rule before
  phase 16, and what a stale browser tab sends); an empty list is refused
  unless the sale came to nothing. The four methods are inserted by the
  migration, not a seeder. `payment_methods.*` are new permissions, so a
  deploy runs `permissions:sync`. `data:reset` keeps the methods and wipes
  the payments. No credit kind, on purpose: see the next bullet.
- **A sale may go on a customer's account** (phase 18, built on GO! with
  these defaults). `customers.credit_limit` of zero means no credit, never
  no limit, so every existing customer started with none; Walk-in can never
  have any. `sales.on_account` is what was not paid at the counter and stays
  as posted; `sales.balance_due` is what is still owed; `sales.due_date` is
  stamped at posting, so changing terms never moves an old invoice. **Since
  phase 18.1 the days are agreed on each sale**: `sales.terms_days`, starting
  on the customer's `payment_terms_days` (now *usual* terms); fewer, down to
  0, is anybody's, **more needs `customers.credit`**
  (`CustomerCredit::termsRefusal()`, asked by the request and again by
  `SalePoster` with the customer locked); no `terms_days` sent, or empty,
  takes the usual; `matchesSaved()` compares it. **A customer's balance is always the sum of `balance_due`**
  — no figure on the customer. `sale_payments` stays money that really came
  in, so **there is no Credit payment method**, and Collections stays honest
  by itself. `App\Support\CustomerCredit` is the only place a limit is
  weighed: the request asks for the error, and `SalePoster` asks again with
  the customer's row locked, before the document number, and **reads what is
  owed with a locking read** — a plain SUM inside the transaction reads an
  older snapshot under REPEATABLE READ and would let two tills both fit. Credit is always
  asked for in pesos (`on_account`), never inferred from a shortfall, and a
  sale on account gives **no change**. **Over the limit is refused, with no
  override**: who may approve one is decision 2, still waiting. A return on
  an unpaid sale comes off `balance_due` first and only the rest is cash
  (`sales_returns.credited_amount`); that is not stage 6's customer credit. A
  void clears `balance_due`. **`customers.credit`** sets the limit and terms;
  without it an edit keeps what is there, and any form leaving the fields out
  keeps them too. Net cash from trading subtracts `on_account_net`, carried
  by the two queries `periodFigures()` already runs, because the dashboard
  has a query budget. **Whatever writes `balance_due` outside the ledger
  must flush the dashboard caches itself** — `CustomerPaymentPoster` does.
- **A customer pays against named invoices** (phase 19, built on GO! with
  these defaults). `customer_payments` is a document shaped like a sale —
  posted on save, never edited, voided with a reason, idempotency key, its
  own `CP` prefix — and `customer_payment_allocations` says which invoices
  it paid. **The invoices add up to the payment exactly**: no invoice takes
  more than it owes, and money nobody can place is refused, because keeping
  it as customer credit is stage 6. **One method per payment**, the
  reference rule taken from `PaymentSettlement::methodRefusal()`; no change
  is worked out. Dated not in the future and not before the newest invoice
  it pays. The form spreads the amount **soonest due first**.
  `App\Support\PaymentAllocation` is the only place a payment is weighed;
  `CustomerPaymentPoster` asks again with the customer, then the sales in id
  order, locked, then takes the number, and flushes the dashboard with
  `DB::afterCommit()` itself. **A sale with a payment against it cannot be
  voided** — `SalePoster::void()` checks with a locking read; the payment is
  voided first. **Every reading of an account lives in `CustomerCredit`**:
  overdue, aging, statement, flags and `reconcile()`, which
  `inventory:verify` runs. **Never call it a receipt**: an official or
  collection receipt is a BIR document; every payment screen and the printed
  statement say *not an official receipt*, in the component. Aging buckets:
  not yet due (due today included), 1–30, 31–60, 61–90, over 90, as of
  today, the edges worked out in PHP as dates. **Overdue is a warning, not a
  refusal**: the till says how much is past due and still sells within the
  limit — blocking an overdue customer has not been decided. Over limit
  means owing more than a limit lowered under the balance. Collections and
  Net cash from trading add what customers paid, on the day it came in, in
  the existing Collections query; left out when narrowed to a category or
  product, and left out of the till's day list (drawer count is stage 4).
  `customer_payments.view/create/void`: Staff get view and create, void
  stays with Admin. The statement, aging and Collectibles (phase 19.1) sit
  behind `.view`.
- **Somebody asks before goods are bought** (phase 21, built on GO! with
  these defaults while decision 2 stays open). A **purchase request is not
  a posted document**: it moves no stock and owes nothing, so the ledger
  never reads it, and its statuses are their own enum,
  `PurchaseRequestStatus` — pending, approved, rejected, closed — never
  `DocumentStatus`. **Whoever holds `purchase_requests.approve` answers it
  (Admin, through `*`).** Whether they may answer **their own** is the
  setting `purchasing.self_approval` (Settings → Purchasing), **on by
  default** — chosen 12 November 2026, option A, because one approver and a
  strict rule left the owner's requests stuck; the trail then reads
  *Approved their own purchase request*. Off, nobody answers their own,
  Admin included: `PurchaseRequestPolicy` hides the buttons and
  `PurchaseRequestDesk` refuses again with the row locked. Both read
  `PurchaseRequest::selfApprovalAllowed()`. Naming another
  approver is a Roles-screen tick, not code. Staff get `.view` and
  `.create` and may close (withdraw) their own; an approver may close any.
  Pending or approved can close; rejected and closed are final. Reject and
  close need a reason. No edit, no delete route. Lines are shaped like a
  purchase line (`quantity` in the product's own unit beside what was typed)
  at an estimated cost that may be 0, so phase 22's order copies them. The
  prefix is `documents.purchase_request_prefix`, **`PRQ`** — `PR` is the
  purchases' prefix, and two prefixes alike share one counter. A product or
  unit named on a line cannot be deleted; a deleted preferred supplier is
  only cleared. The desk flushes the dashboard with `DB::afterCommit()`.
  `Product::buyingOptions()` is the one product list every buying form
  reads.
- **An order is placed with a supplier** (phase 22, built on GO! with these
  defaults). A **purchase order is not a posted document either**: no stock,
  no money, its own enum `PurchaseOrderStatus` (open, cancelled; phase 23
  adds the received ones, phase 24.4 *for approval* and *rejected*). From an
  approved request (`/purchase-orders/create?request=`) or directly — the
  request step is optional. Phase 22 gave an order **no approval of its
  own**; phase 24.4 reversed that (see its bullet). `purchase_request_id` was
  unique — one request, one order — until phase 24.3; a request ordered by
  hand still becomes one order, an awarded one one per winning supplier. `PurchaseOrderDesk` locks the
  request row, re-reads that it is approved and not ordered, takes the
  number, and **closes the request as "Ordered on [PO-…]"** in the same
  transaction. Cancelling an order leaves its request closed.
  `purchase_orders.view/create/cancel` (and `.approve` since 24.4): **Staff view only**, because an
  order commits the shop to a supplier. Prefix
  `documents.purchase_order_prefix`, `PO`. `vat_rate` stamped from the
  supplier like a purchase. Never edited or deleted: cancelled with a
  reason. Dated up to today, no posting floor; expected date optional, an
  open order past it reads *Late*.
- **A delivery is received against its order** (phase 23, built on GO!
  with these defaults). `purchases.purchase_order_id` and
  `purchase_lines.purchase_order_line_id` tie a delivery to the order;
  `purchase_order_lines.received_quantity` is what posted deliveries brought
  in, in the product's own unit, written only by `PurchasePoster` with the
  order row locked (order → document number → products; a void locks the
  purchase → order → products). **`App\Support\OrderReceiving` is the only
  place a delivery is weighed against an order** — the form asks for errors,
  the poster asks again and decides — and `statusOf()` the only place an
  order's status is worked out: open, partly received, received; closed
  short and cancelled are decisions and stay. A delivery may still post
  without an order. A line tied to an order line is that product and takes
  **no more than is still to come**; extra goods go on an untied line, shown
  as *not on the order*. A **cost different from the one agreed is allowed
  and flagged** (per piece, against `agreedPieceCost()`). A **void gives the
  goods back** to the order; closed short stays closed. Cancel only with
  nothing received; a partly received order is **closed short** with a
  reason (`purchase_orders.cancel`). Outstanding = open or partly received.
  A purchase return does not touch the order. `inventory:verify` checks
  every order line against its posted deliveries
  (`OrderReceiving::reconcile()`). **Receive delivery** on an order opens
  `/purchases/create?order=` with what is still to come, in the order's
  unit only for whole boxes. The order's page is the printed order
  (`print:` classes, like the statement), signed *Prepared by* the placer
  and *Approved by* the request's approver. A supplier, product or unit on
  an order cannot be deleted. Supplier payment terms wait for phase 24.
- **The shop owes its suppliers, and pays them against named bills**
  (phase 24, built on GO! with these defaults). **The delivery is the
  bill**: `purchases.on_account` is what went on the supplier's account as
  posted, `balance_due` what is still owed, `due_date` stamped at posting
  from `suppliers.payment_terms_days` (0 = due on delivery). **Every delivery
  posted before phase 24 counts as paid**; a new one is paid on delivery
  unless the form's *On the supplier's account* is on (whole bill, no
  part). A supplier's balance is always the sum of `balance_due`.
  **`App\Support\SupplierAccount` is the only place an account is read**
  (owed, credit, due date, open bills, totals and aging, the Payables card,
  `reconcile()`), and **`App\Support\BillAllocation` the only place a
  supplier payment is weighed**. `supplier_payments` mirrors a customer
  payment — posted on save, voided with a reason, idempotency key, prefix
  `SP` — with `amount` (money out, needs a method) and `credit_used`
  (supplier credit, needs none); the bills add up to both exactly.
  `SupplierPaymentPoster` locks the supplier → the bills in id order → the
  number, reads the credit with a locking read, and flushes the dashboard
  after commit. **A purchase return comes off an unpaid bill first**
  (`purchase_returns.credited_amount`); the rest, on a bill already paid,
  **stays as supplier credit** (`supplier_credit_amount`) — no cash comes
  back from a supplier; a return from before phase 24 carries neither and
  still reads as refunded in cash. Credit is used only against that
  supplier's later bills. **A purchase with a supplier payment against it
  cannot be voided** (locking read); a void clears `balance_due`.
  `supplier_payments.view/create/void`: **Staff hold none**. Payables
  (`/payables`) and the supplier's page sit behind `.view`. A payment method
  a supplier payment used cannot be deleted. The migration names its
  allocation indexes by hand: MySQL refused the generated name (over 64
  characters), and SQLite in the tests did not.
- **A customer is offered a price until a date** (phase 28, built on GO!
  with these defaults). A **quotation is not a posted document**: no stock,
  no money, **nothing reserved** (reserving is the sales order, phase 29).
  Its own enum `QuotationStatus` — open, accepted, cancelled — and
  **"Expired" is not stored**: an open quotation past `valid_until` reads as
  expired (`Quotation::standing()`, `isLive()`), so nothing runs at
  midnight. Valid for `documents.quotation_validity_days` (15) by default,
  changeable on each. Prefix `documents.quotation_prefix`, `QT`. Lines are
  shaped like sale lines and the total comes from `SaleTotals`, discount per
  document; the VAT split follows the Tax Settings switch, as a sale's does.
  **It becomes a sale on the sale form** (`/sales/create?quotation=`): the
  customer (fixed), the lines at the **quoted prices** and the discount are
  filled in, and what is short on the shelf now is said; `SalePoster` locks
  the quotation **first**, asks `QuotationDesk::lockedForSale()` (live, same
  customer), posts, and marks it **accepted** with `sale_id` (unique: one
  quotation, one sale). Stock is checked again by the ledger as for any
  sale. **An expired quotation cannot become a sale**; *Quote again*
  (`/quotations/create?from=`) copies it at today's prices, a switched-off
  product left out and a unit no longer offered falling back to the
  product's own, each said aloud. Cancel only while open, with a reason. A
  voided sale leaves its quotation accepted. `quotations.view/create/cancel`:
  **Staff hold all three** — quoting moves nothing; selling it needs
  `sales.create`. The page is the printed quotation and says, in the
  component, *a quotation, not an official receipt or an invoice*.
  `Product::sellingOptions()` is the one product list the sale and
  quotation forms read. A customer, product or unit on a quotation cannot be
  deleted.
- **An order holds stock for a customer** (phase 29, built with these
  defaults while decision 2 stays open). A **sales order** moves no stock
  itself; its enum `SalesOrderStatus` is pending, approved (shown
  *Reserved*), fulfilled, cancelled, and **"Expired" is not stored**: an
  approved order past `reserve_until` holds nothing
  (`SalesOrder::standing()`, `isReserving()`). **What is reserved is never
  stored on the product**: `App\Support\Reservations` is the only place it
  is worked out — the sum of the lines of approved orders within their
  date — and `available()` is on hand less that, never below nothing.
  Pending holds nothing; **whoever holds `sales_orders.approve` approves**
  (Admin), their own included (the trail says so), choosing the date (7
  days by default, `documents.sales_order_reserve_days`); a taker who may
  approve can approve while taking it (*Approve and reserve now*), refused
  whole when the stock is not free. `SalesOrderDesk::reserve()` locks the
  **products first (ascending id), then the order row**, then reads what
  others hold with a shared lock. **No sale may take stock reserved for
  somebody else**: `StoreSaleRequest` says so under the line, and
  `SalePoster::refuseTakingReservedStock()` checks again after the ledger
  has moved the stock (while it holds the product rows) and rolls the sale
  back. The sale that fulfils an order (`/sales/create?order=`, approved
  or lapsed, same customer) may take its own reservation, and marks the
  order fulfilled with `sale_id` (unique). Cancelling releases at once. A
  live quotation becomes an order (`/sales-orders/create?quotation=`,
  `sales_orders.quotation_id` unique), and is then Accepted. The sale form,
  the quotation and order forms and **the till** offer what is *available*
  (`Product::sellingOptions()` puts it in `on_hand`, the real figure in
  `shelf`); the Stock screen shows Reserved and Available. Losses and
  purchase returns do not look at reservations: the shelf is what it is.
  `sales_orders.view/create/approve/cancel`: **Staff view, create and
  cancel**; approving — holding stock — stays with the approver. Prefix
  `SO`. A customer, product or unit on an order cannot be deleted.
- **Reports are narrowed by cashier and payment method, printed, and
  exported** (phase 34, built on GO! with these defaults).
  `App\Support\ReportFilters` is the only place the Reports filters are read
  — period, `staff_id`, `payment_method_id` — for `/reports` and
  `/reports/export` alike, and `App\Services\ReportBook` the only place that
  decides which blocks a user receives (`blocks()`, `sees()`); the screen and
  the spreadsheet both read it, so the file is what the screen showed. Every
  block takes the same filters inside `InventoryMetrics`
  (`narrowSales()`, `narrowPurchases()`): Cashier is `posted_by`; **a payment
  method narrows to the sales paid at least in part that way** (EXISTS on
  `sale_payments`) — **a split sale counts whole** — and to the returns of
  those sales; **purchases are left out** while a method is chosen
  (`coversPurchases()`), and the stock value while either filter is set.
  **Without `dashboard.view_all_staff` a user may narrow to themselves only**
  (Everyone stays as it was); a colleague is refused with a validation
  error. No "On account" choice. **PDF is the printed page** —
  `window.print()`, `print:` classes, Save as PDF — **no PDF library**. **The
  spreadsheet is a real .xlsx written by `App\Support\Spreadsheet`** on
  ZipArchive and XMLWriter, no Composer package: inline strings only (a
  formula typed as a name stays text), forbidden XML characters stripped,
  dates as serials worked out from Y-m-d, streamed 500 rows at a time to
  `storage/app/private/exports` and deleted after sending. **Cost and gross
  profit are left out of the file without `sales.view_cost`.** An export
  writes a View line to the audit trail. The server needs PHP's `zip`
  extension; without it the button hides and the route answers 503.
- **A request is raised for a department, and prints two ways** (phase
  24.1, built on GO! with the recommendations: departments a list the owner
  keeps, starting with Electrical and Construction). `departments` is a
  master list shaped exactly like Categories — dialog form, `withCount` for
  the delete rule, switched off once a request names it, never deleted;
  `departments.*`, Admin keeps it, Staff read it; put in by the migration and
  emptied by `data:reset` like every master. `purchase_requests.department_id`
  is **optional** (old requests have none) and must be active when picked
  (`Department::options()`); `matchesSaved()` compares it. Users do **not**
  belong to a department yet — that would change who sees what, and was
  not asked for. **Print PR** prints any request; **Print RFQ** only an
  approved one (`can.printRfq`), and **the RFQ never prints our estimated
  cost** — `PurchaseRequestPrint.vue` holds both, `tests/js` guards it. The
  canvass (24.2), orders from the award (24.3) and PO approval (24.4)
  followed; 24.3 lifted the *one request, one order* rule for an awarded
  request, and 24.4 reversed *an order has no approval of its own*.
- **Suppliers' prices are compared before buying** (phase 24.2, built on
  GO! with the recommendations: Q2 optional, Q3 a dearer supplier with a
  reason, Q4 one supplier per item, Q5 delivery days, terms and a note).
  `supplier_quotes` (one per supplier per request, unique) and
  `supplier_quote_lines` (a price **per the request line's typed unit**, VAT
  included; no row for an item not carried). **`App\Support\QuoteAbstract`
  is the only place prices are compared** — `of()` for the screen and the
  paper (lowest marked, ties too; cells line up with the supplier columns),
  `weighAward()` for the award; **`App\Services\CanvassDesk`** locks the
  request row and refuses unless approved, not awarded, not ordered. The award
  is stored on each request line (`awarded_quote_line_id`, nullOnDelete so
  `data:reset` can empty quotes first; `award_reason`) with `awarded_at` /
  `awarded_by` on the request; an item nobody quoted is left out. **After the
  award the quotes are locked**; `canvass.award` reopens it with a reason
  until an order exists. **An awarded request is not ordered by hand**
  (`PurchaseOrderDesk` refuses, Create order hidden) — 24.3 makes its orders.
  `canvass.create` Staff and Admin; `canvass.award` Admin. Removing a quote
  is the one DELETE under `purchase-requests`, and the shape test names it.
- **An awarded request becomes one order per winning supplier** (phase
  24.3). `PurchaseOrderDesk::placeFromAward()` locks the request, reads again
  that it is approved, awarded and not ordered, and writes one order per
  supplier — its items in the unit asked for, at the quoted
  `unit_price` (an order line's `unit_cost` is per typed unit, as a quote's
  price is), `purchase_order_lines.purchase_request_line_id` naming the
  request line, *Deliver by* = today + the quoted delivery days, the note
  naming the request and the quoted terms — then closes the request as
  *Ordered on [PO-…], [PO-…]* (plus the items nobody quoted). The request now
  has `purchaseOrders()` (HasMany; `isOrdered()`), not `purchaseOrder()`, and
  its detail sends `orders` as a list. `purchase_orders.purchase_request_id`
  is a plain index now; a request ordered **by hand** still becomes one order,
  guarded by the request lock. Placing them needs `purchase_orders.create`.
  The award cannot be reopened once ordered.
- **A purchase order is approved before it is sent** (phase 24.4, built on
  GO! with Q6 and Q7: only the approver approves, their own at once; an
  unapproved order prints only as a draft). `PurchaseOrderStatus` gained
  **`pending_approval`** (*For approval*) and **`rejected`**; approved is
  *Open*. **`PurchaseOrderDesk::write()` is the one place it is decided as an
  order is placed** — by hand and from an award alike: approved at once only
  when the placer holds **`purchase_orders.approve`** (Admin, through `*`)
  **and** `purchasing.self_approval` is on (`approvesOwn()`; the trail says
  *Approved their own purchase order*); otherwise it waits. `approve()` /
  `reject()` lock the order, re-read that it waits, and refuse one's own with
  the setting off — the policy hides the buttons too. A reject needs a reason
  and is final; **a rejected or cancelled order leaves its request closed** —
  the goods are ordered again directly. An order waiting may still be
  cancelled (`purchase_orders.cancel`); the approver is shown Reject instead.
  **Waiting is not outstanding**, so nothing is received against it — the
  purchase form and `OrderReceiving::weigh()` both say *waiting for approval*
  — and `statusOf()` keeps both new statuses as decisions. `approved_at`,
  `approved_by`, `rejected_at`, `rejected_by`, `reject_reason` on the order.
  **Every order from before 24.4 counts as approved**: the migration stamped
  `approved_at` from `created_at` and `approved_by` from its request's
  approver, the name it already printed, so an old order reprints exactly as
  before. **The paper reads `order.draft`, worked out from the status, never
  from `approved_at`**: a draft carries *DRAFT — NOT APPROVED* across the
  page and a blank *Approved by* line — the request's approver never stands
  in for the order's (`Components/PurchaseOrderPrint.vue`, guarded in
  `tests/js`). The **Orders to approve** card (secondary row,
  `purchase_orders.view`, Staff filter on `ordered_by`, one query) counts
  them as of now, so the desk flushes the dashboard after commit on place,
  approve, reject and cancel. **Staff still only read orders**; the in-charge
  who should draft them needs `purchase_orders.create` ticked on the Roles
  screen, which is now safe because nothing they place is sent unapproved.
- **Every full-page document prints through one letterhead and footer**
  (phase 35, built on GO! with these defaults). `PrintLetterhead.vue`,
  `PrintFooter.vue` and `composables/usePrintLayout.js` serve the
  quotation, the purchase order, the statement, Aging and Reports;
  Settings → **Print Layouts** (`printing.*`, shared to every page as the
  `printing` prop) sets the paper (printer's own, A4, Letter, Long bond 8.5 × 13 in,
  Legal 8.5 × 14 in), the margins, whether the logo and the address/TIN/phone print, terms
  for a quotation and a purchase order, and one closing line. **Every
  default prints exactly as before.** The `@page` rule is added only while a
  document page is mounted and removed on unmount, so **the sale slip never
  inherits it**; the slip keeps `documents.slip_*` (not moved, or stored
  values would be orphaned). The *not an official receipt / not an invoice*
  lines stay in the pages, never in a setting. A new printed page uses the
  two components and calls `usePrintLayout()` — never a sixth copy.
- **A backup is the whole books, made in PHP and restored by hand** (phase
  35, built on GO! with these defaults). `App\Support\DatabaseDump` writes
  `database.sql` — no `mysqldump` on shared hosting — reading every table on
  MySQL inside **one `START TRANSACTION WITH CONSISTENT SNAPSHOT`** (a
  non-InnoDB table is refused) **with the session's time zone set to UTC**
  and put back after — the file declares `time_zone='+00:00'`, and read in
  the server's zone every TIMESTAMP came back eight hours off, streaming a thousand rows at a time, leaving
  out the rows (not the tables) of `sessions`, `cache` and the queue tables;
  SQLite is supported for the tests. `App\Services\BackupMaker` is the only
  place a backup is made, listed or deleted: a `.zip` on the **private**
  disk (`storage/app/private/backups`) with `database.sql`, `files/` (the
  public disk's photos and logo, unless `backup.include_files` is off),
  `RESTORE.txt` and `manifest.json`; written as `.part` and renamed when
  whole; the oldest pruned to `backup.keep_copies` (7) **only after a new one
  succeeds**. **It never carries `.env`.** `backups.view/create/download/delete`
  — **Admin only**, every make, download and delete audited; a name must
  match `BackupMaker::NAME`, so nothing else is ever served. `backup:run`
  makes one from the command line; `routes/console.php` schedules it daily or
  weekly at 2 AM per `backup.frequency` (off by default) and it runs **only
  with the server's `schedule:run` cron job**. **Restoring is never done from
  the app.** **Every backup test fakes the `local` disk** — a prune against
  the real folder would delete real backups. "Export settings" in the scope
  was read as the settings that govern a backup (what it carries, how often,
  how many); data export is phase 34's spreadsheet.
- **The kiosk may send the order to the cashier: Pending payment** (CR-2,
  phase 17.1, built on GO! with Q1–Q5). `pending_payments` /
  `pending_payment_lines`, its own enum `PendingPaymentStatus` (pending shown
  *Awaiting payment*, paid, cancelled; **"Expired" is not stored**), prefix
  `documents.pending_payment_prefix` (**`PP`**), held for
  `documents.pending_payment_hold_days` (**0 = until closing that day**).
  **Not a hold**: a hold keeps nothing aside; this **holds its goods** at the
  till's store through `Reservations::heldForPendingPayments()`, added into
  `reserved()` like a loss report's. `App\Services\PendingPaymentDesk` is the
  only place one changes; sending locks products (ascending) → the store
  (shared) → locking reads of what is free → the number. **Paid exactly as
  sent**: `POST /sales` with `pending_payment_id` (prohibits a quotation or
  sales order); `StoreSaleRequest` and `SalePoster` — which **locks it
  first** — refuse unless it is waiting, same customer, same store, same
  lines, units, quantities, prices and discount
  (`PendingPaymentDesk::sameLines()`); the sale may take its own hold
  (`Reservations::reserved(..., exceptPending:)`, never mixed with
  `$exceptOrder`). **The till pays it from the order's own payload, never by
  loading it into the basket**, so the basket's stock cap never sees its own
  hold. **It comes with the kiosk, not a tick of its own** (the user's GO!,
  24 November 2026, after the client's *Sales Encoder* role showed no
  button): **anyone with `kiosk.use` may send one; anyone with
  `sales.create` takes its money and may cancel any; the sender may cancel
  their own** (`PendingPaymentPolicy`). Only the back-office list needs
  `pending_payments.view` (Staff hold it). A kiosk-only role is `kiosk.use`
  without `sales.create`: it sends and never takes money — the till then
  hides *Take payment*, *Hold* and other people's orders. Never put it back
  behind a permission of its own: a role made on the Roles screen would lose
  the button again. **The money may also be received in the back office**:
  *Receive payment* on Sales → Pending Payments (dialog and page,
  `ReceivePendingPayment.vue`) posts the same `POST /sales`, needs only
  `sales.create` — Admin has no kiosk — and lands on the new sale. The
  sidebar shows a **red count** of those waiting: the shared `badges` prop,
  read by `NavLink.vue` through the item's `badge` key — the one way to put
  a count on a menu item. **Every module with a step waiting on staff has
  one** (24 November 2026): `App\Support\SidebarBadges` is the only place the
  counts are worked out — Purchase Requests, Purchase Orders, Sales Orders,
  Pending Payments, Stock Counts and Loss Reports — each what this person
  can act on: an approver everything waiting, anybody else their own, none
  for a screen they cannot open. Quotations and the overdue screens are left
  out on purpose. README has the table. **The bell lists the same**: the
  shared `notifications` (`SidebarBadges::waiting()` with anything in it,
  worked out once per request with the badges), each linking to its list
  narrowed to exactly those rows; no red mark when nothing waits. The slip, `OrderSlip.vue`, says *NOT PAID*
  and *not an official receipt* in the component, never a cost
  (`tests/js/OrderSlip.test.js`). A product, unit, customer or location on
  one is never deleted. No dashboard card and no API endpoint yet.
- **The till can hold a sale, name a customer and print a slip** (phase 17,
  built on GO! with these defaults). A **hold is not a document**:
  `held_sales` keeps the basket (lines as JSON, quantity as text) with no
  reference, no stock moved and nothing reserved — reserving is stage 7.
  Do not turn it into a `held` status on `sales`. Holds are shared by every
  till, at most `HeldSale::MAX_OPEN` (50). Recall claims the row by deleting
  it, so two tills cannot both bring it back, and `lib/holds.js`
  `rebuildHold()` lays it against today's shelf: today's price, a gone
  product or unit left off, a quantity cut to what is left, an inactive
  customer back to Walk-in — each said aloud. The routes sit behind
  `kiosk.use` and `sales.create`; no new permission. The till's customer
  box reads `Customer::options()` (shared with the sales form) and goes
  back to Walk-in after every sale, hold or clear; **naming a customer is
  not credit** — it takes a tap on On account (phase 18). The **slip** is
  `Components/SaleSlip.vue`, drawn from the sale's own `detail()` payload
  and printed with `window.print()` through `SlipPrinter.vue` and
  `body.printing-slip` (no iframe: `X-Frame-Options` is DENY). **It never
  prints a cost or margin**, even for `sales.view_cost`, and **always says
  "This is not an official receipt."** — written in the component, not a
  setting, so it cannot be cleared. Width (80/58 mm) and the closing line
  are settings under Document Numbers, shared as the `slip` prop.
- Saving a purchase or a sale posts it at once. There is no draft stage, but
  the documents keep a status column for one later.
- A posted document is never edited or deleted. It is corrected with a void,
  a return or an adjustment.
- No backdating before the last posted document, because FIFO consumes layers
  in posting order.
- **VAT has two sides, and they follow different things.** Selling asks
  *do we charge VAT* — `tax.vat_registered` in Settings, a **view** over
  sales, sales returns and the till: every one follows the switch, old
  documents included, nothing is stored, and turning it off puts every
  screen back. Buying asks *did the supplier charge VAT* — the supplier's
  own `is_vat_registered`, stamped onto `purchases.vat_rate` when the
  delivery is posted, a **record** that does not move afterwards. A
  purchase therefore **ignores the Settings switch entirely**: a VAT
  supplier splits with the switch off, and a Non-VAT supplier does not
  split with it on. You cannot claim input VAT from a supplier who charges
  none. A purchase return follows the delivery it undoes, not the switch.
  `Vat::currentRate()` is the selling rate and returns 0 when the switch is
  off; `Vat::rate()` is the configured rate whatever the switch says, and
  only the buying side uses it. A `vat_rate` column on `sales` was built in
  October and removed, because there it would have recorded only that a
  comparison view happened to be on; the one on `purchases` records what a
  real invoice charged, which is why it stays. Every price typed in is
  VAT-inclusive, so the tax comes out of the total and never goes on top. `App\Support\Vat` is the only place that
  arithmetic happens, with `vatSplit()` in `resources/js/lib/money.js`
  drawing the same sum while somebody types — **both in whole centavos**,
  because dividing floats put them one centavo apart on an amount whose net
  lands on exactly half a centavo (₱42.14 gave 37.63 and 37.62). A sale
  splits what was charged, after the discount, so its lines will not add up
  to its footer when a discount was given — the footer follows the money.
  Each line is also rounded on its own, so even without a discount the lines
  can land a centavo off the footer; the footer is the figure that counts. A
  purchase return splits its cost, which is the only value it has.
  Adjustments never split: nothing was bought or sold. The stock valuation,
  the cost of goods sold, the margin, the Dashboard and the Reports all
  still carry VAT, and a document shown with VAT is not a figure to file a
  BIR return from. Making the books net of VAT means changing
  `InventoryLedger`, `InventoryValuation` and `InventoryMetrics`, and it has
  not been asked for.
- **A product may carry one photo, and it is a file, not a row.**
  `products.photo_path` points into the `public` disk; the picture sits in
  `storage/app/public/products` and reaches the browser through the storage
  link, so **`php artisan storage:link` is part of deploying** and
  `storage/app/public` must be backed up separately — a database export does
  not carry it. `App\Support\ProductPhoto` is the only place a photo is
  written, shrunk or deleted, and it shrinks with GD rather than a Composer
  package, because this system is deployed by uploading files by hand. JPG,
  PNG or WebP up to 2 MB; **SVG is refused**, because it is XML that can
  carry script and would be served from this system's own domain.
  `photoUrl()` gives a path from the site root rather than an absolute
  address: `Storage::url()` builds that from `APP_URL`, and a wrong `APP_URL`
  would break every picture while everything else kept working. The API sends
  the absolute address instead, since a phone cannot resolve a path. An edit
  carrying a file is posted with `_method: 'put'`, because PHP does not read
  an upload on a PUT; `useFormModal` switches only when a file is really
  there, so the other four master dialogs are untouched.
- **The developer's credit line is one component** (23 November 2026, on
  the user's GO!). *Designed, Built and Shipped by [logo] DeltaTech* is
  `Components/DeveloperCredit.vue`: the logo always left of the name, only
  the name linked (`https://deltatechph.com`, new tab), the words written in
  the component and never a setting. **On a light background the logo is
  always the solid navy symbol** (26 November 2026, at the user's request;
  the gradient one was removed). It sits on the sign-in pages (navy
  logo, where the caption *GO Enterprises • Inventory Management System* was
  — taken off on 24 November 2026 — no taller than it, so the page never
  scrolls; the card's *Need access? Contact your administrator.* and its
  divider went the same day, at the user's request), every app
  page's footer (navy) and the signed-out error page (white). **Never on a
  printed document or the till.** `tests/js/DeveloperCredit.test.js` guards
  it. README, "The developer's credit line".
- **The company logo is uploaded from Settings, and it is a file too.**
  `company.logo` is a settings field of type `image`: picking a file saves
  it at once through `POST settings/{group}/images/{field}` (Remove is
  `DELETE` on the same address), gated by `settings.update` — no new
  permission. The Save button never carries it, because the form holds the
  value from when the page opened and would put the old logo back.
  `App\Support\SettingImage` is the only place it is written or deleted,
  and it deletes only a file it wrote, matched strictly under `settings/`.
  The browser tab icons are cut from the same upload (with GD; without it
  none are cut). With no icons cut, the tab falls back to the shipped ones —
  `public/images/favicon.png`, `apple-touch-icon.png`, `brand-mark.png` (the
  default `company.logo`) and `public/favicon.ico` — which since 26 November
  2026 are cut from the round GO logo by `ImageFile::square()`; before, they
  were a handshake from the template, and the live tab showed it. The sign-in pages do
  **not** follow it: they keep the approved round GO logo,
  `public/images/go-enterprises-logo.png` (11 November 2026), on the login
  and the kiosk alike — both wear `SignInLayout`, redrawn on 18 November 2026
  to the client's mockup (`design/login-mockup.png`, 1586 × 992, matched to
  the pixel at that size). On 18 November 2026 it was made to fit the window
  at 100% zoom: every size is a number of one unit, `--u` (`goe-scale` in
  `app.css`), the mockup's pixel shrunk so 1586 × 1085 units fit the screen
  across and down (never above 1 px, never below 0.6 px); below 1024 px it
  is 1 px and the phone layout is as written. On a touch screen the fields
  and buttons keep 48 px (`pointer-coarse:`). A new measurement on these
  pages is written as `calc(N*var(--u))`, never a bare px. The hero's illustration is cut from that mockup
  (`public/images/login-hero.png` and `.webp`, 944 × 395) and is never drawn
  larger than its own size; a sharper one needs the client's original file.
  The mockup has no Kiosk sign in; the card keeps it on purpose (the shared
  tablet). "Forgot password?" points to `#`: there is no password reset, an
  administrator sets a new password from Users. The pixel work is `App\Support\ImageFile`, shared with
  `ProductPhoto`. **`tests/TestCase.php` fakes the `public` disk for every
  test** — a settings test once ran against the real disk and deleted the
  tab icons cut from a real upload. Keep it there.
- **A supplier carries its own VAT registration, and that one IS a record.**
  `suppliers.is_vat_registered` says whether that supplier charges VAT. Do
  not confuse it with `tax.vat_registered` in Settings, which is a view and
  stores nothing: this is a fact about the supplier and it stays written
  down. It is switched from the pill in the list, from the edit dialog and
  from the supplier's page, all three reading one field. The pill is the
  switch rather than a fifth action icon, because the table standard keeps
  blue, green, red and orange for View, Edit, Delete and Warning. The route
  is `PATCH suppliers/{supplier}/vat` gated by **`suppliers.update`** — it
  is an ordinary edit of a field, so it needs no permission of its own and
  no `permissions:sync` on deploy. Every supplier already on the books
  started as Non-VAT. The column is filtered, never sorted: a boolean index
  earns nothing and `PerformanceTest` fails on a sorted column without one.
  `PartyResource` serves customers too, so the field is sent for suppliers
  only. **It drives the VAT split on every purchase** — see the VAT bullet
  above — so changing it decides how the *next* delivery is stamped, never
  how an old one reads.
- **The dashboard is the owner's daily screen** (README, "The owner's
  dashboard"). It opens on Today. Est. gross profit, Inventory value and
  losses at cost need `sales.view_cost` and arrive as `restricted` with no
  number without it — never as ₱0.00. **`dashboard.view_all_staff`** decides
  whose documents it adds up: without it the controller forces the Staff
  filter to the user's own id. Purchase requests became real in phase 21 —
  pending requests as of now, behind `purchase_requests.view`, Staff filter
  by who raised them — and Overdue balances in phase 19 — as of today, Staff
  filter applies, linking to Aging. The cards' query budget is 22 (phase
  24 added the supplier payments in Net cash, two queries, and Payables;
  phase 24.4 the Orders to approve card, one).
  Collections reads the payments each sale recorded (phase 16), and since
  phase 19 what customers paid against invoices, and names
  each method under the figure; narrowed to a category or a product it falls
  back to the goods sold, because one payment cannot be split between them.
  Net cash from trading leaves out what went on a customer's account until
  it is paid, and since phase 24 what went on a supplier's account until the
  supplier is paid (`purchases_unpaid_net`, carried by the purchase queries
  `periodFigures()` already runs, plus `supplierPaid()`); a purchase return
  that came off a bill or stayed as supplier credit moves no cash. The
  **Payables** card (secondary row, `supplier_payments.view`) is what is owed
  to suppliers as of today, past due beside it. Losses are posted Decrease
  adjustments of every reason.
- A sales return comes back at the cost of the layers it consumed. A purchase
  return draws from the layer its own purchase created.
- Low stock means `on_hand <= reorder_level`, and a reorder level of zero
  means the product is not watched.
- Stock never moves through a form, and nothing writes `products.on_hand`
  or `location_stocks` except `App\Services\InventoryLedger`. It moves inside that one service, in
  the same transaction as the document, with the product rows locked in
  ascending id order. `php artisan inventory:verify` checks the rule it
  keeps: on hand always equals the sum of the batches behind it.
- Audit at document level only. Line, layer and movement rows must not carry
  `use Auditable;`, or one purchase would write dozens of trail rows.
- `InventoryValuation` counts only batches with stock left. A used-up batch
  is worth nothing, so the figure is the same, but the query can then be
  indexed: it was reading all 39,824 batch rows on every dashboard and every
  page of the Stock screen. `layers_valuation` covers the sum.
- **Do not add a `(status, date)` index to the document tables.** It was
  tried on all four and measured on a copy holding 20,000 sales. Nearly every
  document ends up `posted`, so status narrows almost nothing, and MySQL goes
  on scanning — correctly, because a quarter of a table fetched row by row
  costs more than one straight pass. A narrow report range is already served
  by the plain date index. The composites were removed rather than shipped as
  a write on every document saved for no gain. If those figures ever do need
  help it will be a covering index carrying `total_amount`, and only once a
  screen is actually slow.

---

# Scope checklist

`SCOPE.md` in the project root is the checklist of the client's proposal
(October 21, 2026, section 4 "Scope of Work"): 24 scope items with a box for
each piece, the 9 acceptance checks, the 6 client decisions, the 9-stage
work plan and a change log. **It is the record of what is done and what is
left. Keep it true.**

**`UPDATE CHECKLIST!`** — when the user types it:

1. Read `SCOPE.md`, then check the code for every box that is not ticked
   and for anything built since the last change-log entry. Run the full
   test suite.
2. Tick a box only when the feature is in the code **and** its tests pass —
   never because it was planned or half-built. Untick one that is no longer
   true. Add the file it lives in, in brackets.
3. Set each item's mark (✅ every box ticked, 🟡 some, ⬜ none), recount the
   totals, the acceptance checks and each stage's status, and update "Where
   we are": the current stage, what it waits on, and the next stage.
4. Write any client decision the user has given into section 3, and into
   the decisions list in this file if it changes a rule here.
5. Add one dated line to the change log, newest first. Set "Last checked"
   and the test counts.
6. Report to the user, in this order: the stage and phase in progress and
   how far along it is, with the work plan table from `SCOPE.md` section 4
   (stage, phase, work, status); what was finished since the last check; what is still open
   in the current stage; what blocks the next one; the new totals. Then the
   corrected English and "FINISH NA BOSS!".

`UPDATE CHECKLIST!` changes only `SCOPE.md` (and this file for a decision).
It does not build anything, and it does not rebuild the client PDF on the
Desktop unless asked.

Building a stage still needs **GO!**. When a stage finishes, update
`SCOPE.md` as part of that work, in the same way.

---

# Before go-live

The build is finished. What is left is not a phase. README section 8 has the
server-side checklist; these are the ones specific to this system.

1. **Back up the database and `storage/app/public`**, then run
   `php artisan down`. The next step cannot be undone.
2. **Empty the books** with `php artisan data:reset` (decided 7 November
   2026: the client starts from scratch). It empties every document (customer
   and supplier payments, purchase requests and orders, quotations and sales orders, transfers, count sheets, loss reports — with their photos — and pending payments too), the sales on hold, the ledger, all seven master lists (departments too, phase 24.1, and locations, phase 31.1, of which one default is put back) and the audit trail; it keeps users, roles,
   permissions, the payment methods and every setting; then it puts back
   one row, the walk-in
   customer, because the till cannot sell without it. The units are not put
   back: the client enters their own, units first, and turns on "Allows
   decimals" for each unit sold by measure (kg, m, ltr …) — a new unit
   starts whole. The departments are not put back either (Electrical and
   Construction go with the reset): enter the real ones before the first
   purchase request. The locations go too, but one default **Main Store** is
   put back (phase 31.2), because the ledger cannot post without one: rename
   it to the real store and add the warehouse before the opening stock. Since
   phase 31.4 each opening-stock adjustment names its **Location**: count the
   store and the warehouse separately; after that, goods change place only
   through a transfer (phase 31.5). This replaces the old
   plan of voiding `PR-2026-0001` and running `sample:clear` — after a full
   reset neither has anything left to do. README section 3, "Starting the
   real books", has the detail. **A new table must be added to `WIPED` or
   `KEPT` in `ResetBusinessData`**, or `ResetBusinessDataTest` fails.
3. **Enter the real opening stock** through Adjustments, with the kind set
   to *Opening stock*, once the products are in. Nothing else may put stock
   on the shelf. Then `php artisan up`.
4. **Make the storage link**, with `php artisan storage:link`, or by hand
   when the web root is not the app's own `public/`. Without it every
   product photo and an uploaded logo are broken images. Check it
   afterwards: a folder copied from another project brings that project's
   link with it. That happened on the development machine, and it was
   re-made with `php artisan storage:link --force` on 7 November 2026. It
   broke again after the folder was renamed (every till picture broken),
   so on 11 November 2026 it was made relative by hand —
   `ln -s ../storage/app/public public/storage` — which survives a rename
   or a move. `storage:link --relative` needs `symfony/filesystem`, which
   this project does not carry.
5. **Back up `storage/app/public` as well as the database.** Product
   photos and the uploaded logo are files. A database export does not
   carry them, so a restore would bring back broken pictures. The same goes
   for `storage/app/private/losses`, the photos on loss reports (phase 33);
   the app's own backup carries both.
6. **Run `php artisan inventory:verify`.** On hand must equal the sum of the
   batches behind it. If it does not, stop and find out why before anybody
   trades on the figures.
7. **Change the `admin` password**, or delete the account if a real person
   does not use it. `AdminUserSeeder` will not put the default back — it
   uses `firstOrCreate` and grants the Admin role only when it has just
   created the account — but the password is in this file and in the README.
8. **Revoke every token issued before phase 13.** They were written with no
   expiry and never die:
   `DB::table('personal_access_tokens')->whereNull('expires_at')->delete();`
9. **Schedule `sanctum:prune-expired --hours=24`** daily, so dead tokens do
   not pile up.
10. **Check `api.token_lifetime_days`** on the Settings page. 30 days is the
   default; a shorter life limits what a stolen phone is worth.
11. **Back up, and prove the backup restores.** Add the scheduler's cron job
   (`* * * * * cd /path/to/inventory_app && php artisan schedule:run`), set
   Settings → Backup and Export to *Every day*, make one backup from the
   Backups screen, download it, and import it once into a spare database:
   `inventory:verify` must pass on it. That is acceptance check 9. Keep a
   copy off the server.

---

# Main Objective

Build a modern, scalable, responsive, secure, and future-proof business management system.

The system must be: fast, smooth, elegant, premium, enterprise-quality, mobile-ready, and production-ready.

The UI/UX must feel like modern SaaS applications (Stripe Dashboard, Notion, Linear, Vercel, Clerk, HubSpot).

---

# Tech Stack

- Backend: Laravel 12 (latest stable), MySQL
- Frontend: Inertia.js, Vue 3, Tailwind CSS v4, Vite
- Libraries: SweetAlert2, ApexCharts, Lucide Icons (`@lucide/vue`), Spatie Laravel Permission
- API: Laravel Sanctum

---

# System Architecture

Follow Laravel best practices. Use:
- Models
- Controllers
- Form Requests
- Services (when necessary)
- Policies
- Middleware
- API Resources

The structure must be easy to maintain and easy to scale.

---

# Authentication

- Session-based authentication
- Username login support
- Secure password hashing
- Remember Me support
- Logout function
- Protected routes
- Ready for API authentication with Laravel Sanctum

---

# Role and Permission Management

Use Spatie Laravel Permission.

- Role-based access control
- Permission-based access control
- Menu visibility based on permissions
- Route protection based on permissions
- Default roles: Admin, Staff
- The system must allow unlimited future roles (for example: Manager, Supervisor, Cashier, Encoder, Inventory Clerk, Accountant)

`User` and `Role` both keep `protected $guard_name = 'web'`. Do not remove it:
the `auth:sanctum` middleware makes sanctum the default guard for a request,
and Spatie then cannot find the user model.

---

# Audit Trail

Log these actions: Login, Logout, Create, View, Update, Delete, Activate, Deactivate.

Audit trail fields: User, Role, Module, Action, Description, IP Address, Browser, Timestamp.

Every new module must write to the audit trail. Add `use Auditable;` to the
model and it logs create, update and delete by itself.

---

# Dashboard

- Summary cards
- Recent activity
- Charts (ApexCharts, with smooth animations)
- Quick statistics
- Responsive layout

Figures belong in a service, cached, and reach the page through
`Inertia::defer()` with a skeleton. See `App\Services\DashboardMetrics`.

---

# CRUD Standard

Every new module must have:
- Create, Retrieve, Update, Delete
- Search
- Pagination
- Sorting
- Filtering
- Status management

Validation:
- Required fields
- Unique constraints
- Clear error messages

Rules go in a Form Request. Authorization goes in a Policy. A route carries
`permission:x.y` as the outer gate. Keep a Policy free of queries per row — it
runs once for every row on a list screen.

---

# SweetAlert2 Global Standard

Use SweetAlert2 in the whole system for:
- Create success
- Update success
- Delete confirmation
- Delete success
- Validation errors
- Warning messages
- Logout confirmation
- Session expiration

Use toast notifications when they fit. Every alert goes through
`resources/js/lib/swal.js`.

---

# UI/UX Standard

The system must NEVER look like default Laravel. Use:
- Modern SaaS design
- Soft shadows
- Rounded corners
- Clean typography
- Proper white space
- Smooth animations
- Smooth hover effects
- Skeleton loaders
- Loading states
- Page transitions

Colour, type, radius, shadow and motion are tokens in `resources/css/app.css`
(Tailwind v4 `@theme`). There is no `tailwind.config.js`. A Tailwind class
built at runtime is never generated — use an inline style for a dynamic size.

---

# Sidebar Standard

- Responsive
- Collapsible
- Permission-based menu visibility
- Active menu highlight
- Smooth transitions
- Icon-based navigation

---

# Table Standard

All tables must support:
- Search
- Server-side processing
- Pagination
- Sorting
- Responsive layout

Rules:
- Default rows per page: 25
- Hover effect: the row gets slightly darker on hover
- Action buttons: icons only
- Action colors: View = Blue, Edit = Green, Delete = Red, Warning = Orange

Use `App\Support\TableQuery` on the controller and
`resources/js/Components/DataTable.vue` on the page. Do not write a new table.

---

# Settings Module

A generic settings module. Groups in place:
- Company Information
- System Preferences
- Theme Settings
- Tax Settings
- Document Numbers
- Notification Settings
- API Settings

Use settings-driven design. Do not hardcode values. One field added to
`config/settings.php` appears on the page, validated and saved, with no other
change. Read a value with `settings('group.key')`.

---

# Security

- CSRF protection
- XSS protection
- SQL injection protection
- Password hashing
- Authentication middleware
- Permission middleware
- Form validation
- Secure session handling

Follow Laravel security best practices.

`tests/Feature/SecurityTest.php` walks every registered route and fails if one
has no authentication gate, or writes data without naming a permission.

---

# Mobile Application Readiness

Prepare the backend for future mobile apps (Android and iOS):
- Laravel Sanctum
- REST API structure
- API Resources
- API authentication
- Version-ready API design, for example `/api/v1/`

Every API reply has one shape, success or failure. See
`App\Support\ApiResponse` and README section 7.

---

# Performance

Optimize:
- Queries
- Pagination
- Eager loading
- API responses
- Asset loading

The system must stay fast with large datasets.

`tests/Feature/PerformanceTest.php` loads each list screen with 5 rows and
then 40, and fails if the query count grew. It also fails when a column a
screen sorts on has no index.

---

# Code Quality

- Clean code
- Reusable components
- Reusable services
- Organized folder structure
- Maintainable architecture
- Laravel best practices

---

# Development Rules

Priority order:
1. Stability
2. Security
3. Scalability
4. Performance
5. User experience
6. Premium UI design

Before you create any module:
- Think like a senior software architect.
- Think about future scalability.
- Avoid hardcoding.
- Use settings-driven design when possible.
- Write clean and maintainable code.

Build everything as if thousands of users will use the system and it will grow for many years.
