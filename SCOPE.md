# GO Enterprises — Scope Checklist

The working checklist of the **Scope of Work** in the *Implementation and
Subscription Proposal* of October 21, 2026 (section 4), checked against the
system as it really runs.

Type **`UPDATE CHECKLIST!`** to have it checked against the code again and
reported. The rule for doing that is in `CLAUDE.md`, under "Scope checklist".

- **Last checked:** 2026-11-27
- **Test suite on that day:** 1,328 PHP tests and 239 front-end tests pass
- **Client copy:** a PDF was made on 2026-11-08 at
  `~/Desktop/go enterprise scope.pdf`; it is no longer there, and it is not
  rebuilt automatically

---

## Where we are

| | |
|---|---|
| **Current stage** | Stage 8 — locations, counts, losses: ✅ **done** (all 8 phases: 30, 31.1 to 31.5, 32 and 33), taken ahead of stages 4 and 6 on 20 November 2026. Stages 1, 2, 3, 5, 7, 8 and 9 and CR-1 to CR-4 ✅ done; stage 5 still has one box open — pending purchase quantities on the Stock screen (#12). Stages 4 and 6 are left |
| **Current phase** | Phase 28.1 ✅ done (**Mobilization**, CR-4: a charge for bringing the goods to the site on a quotation, carried to the order and the sale). Before it, phase 35.1 ✅ done (**a light or a dark screen**, CR-3). Before that, phase 33 ✅ done (**loss reports**: damaged, expired, rusted, stolen or missing goods reported with photos, held aside at their location, written off only when an approver approves — or rejected, freeing the goods) |
| **Waiting on** | **GO!** for the next stage — stage 4 (cashier shifts) or stage 6 (pricing rules and approvals). Decision 4 still has two questions open, each answered for now by a default: whether the till may ever sell from a warehouse (*a store only*, 31.4), and who may transfer and whether a transfer needs approval (*Staff may, no approval*, 31.5). Decision 2 still waits for stage 6 |
| **Next stage** | Stage 4 (phase 20) or stage 6 (phases 25 to 27), in the order the user picks; stage 6 waits on decisions 2, 3 and 5 |

### Totals

| Done | Partly done | To do | Acceptance checks passing |
|:---:|:---:|:---:|:---:|
| **15** | **7** | **2** | **7 of 9** |

Status marks: ✅ Done · 🟡 Partly · ⬜ To do. An item is ✅ only when every box
under it is ticked.

---

## 1. Checklist by scope item

A box is ticked only when the feature is in the code and its tests pass —
never because it is planned. The note in brackets says where it lives.

### Selling

#### 1. Dashboard — 🟡 Partly
- [x] Today's sales, collections, cash position, estimated gross profit (`app/Services/DashboardOverview.php`)
- [x] Low-stock items and recorded losses, with exception lists (`DashboardOverview::exceptions()`)
- [x] Filters by date and by staff; staff without `dashboard.view_all_staff` see only their own (`DashboardController`)
- [x] Every card and row opens the records behind it
- [x] Cost and profit restricted to approved roles, on the server (`sales.view_cost`; `tests/Feature/DashboardAccessTest.php`)
- [x] Overdue customer balances — what is past due as of today, with a list of the customers longest overdue; opens Aging (`CustomerCredit::overdue()`, `DashboardOverview`, `tests/Feature/Customers/ReceivablesTest.php`)
- [x] Pending purchase requests — how many wait for approval, their estimate and the oldest's wait, as of now; opens the list filtered to Pending (`PurchaseRequest::waiting()`, `DashboardOverview`, `tests/Feature/Purchases/PurchaseRequestTest.php`)
- [ ] Cashier cash differences (needs #6, stage 4)

#### 2. Point of Sale — 🟡 Partly
- [x] Sales screen with item search, quantities and stock on hand (`resources/js/Pages/Pos/Index.vue`)
- [x] Discount and automatic total
- [x] Stock checked when the sale posts (`app/Services/InventoryLedger.php`)
- [x] Hold and recall a sale — the till parks the basket under Held, any till recalls it against today's shelf and prices; nothing is reserved (`app/Models/HeldSale.php`, `app/Http/Controllers/HeldSaleController.php`, `resources/js/lib/holds.js`, `tests/Feature/Pos/HoldTest.php`)
- [x] Cash received and change — the till takes the payment in a dialog, with one-tap notes and the change shown at once (`resources/js/Components/PaymentEntry.vue`, `app/Support/PaymentSettlement.php`)
- [x] Print a transaction slip — from the till after a sale, the sale dialog and the sale page; 80 or 58 mm; never shows cost; says it is not an official receipt (`resources/js/Components/SaleSlip.vue`, `resources/js/lib/slip.js`, `tests/js/SaleSlip.test.js`)
- [x] Choose a customer at the till — Walk-in by default, back to Walk-in after each sale; paid in full, not credit (`app/Http/Controllers/PosController.php`, `tests/Feature/Pos/TillCustomerAndSlipTest.php`)
- [x] Sell in other units — a line switches between pcs, pack and box; stock is checked in pieces (`resources/js/Pages/Pos/Index.vue`, `app/Support/UnitConversion.php`)
- [ ] Approved price lists (stage 6)

#### 3. Payment Recording — 🟡 Partly
- [x] Cash, e-wallet, bank transfer and split payments — on the till and the New sale form; cash may exceed the total and gives change, nothing else may (`app/Models/SalePayment.php`, `app/Support/PaymentSettlement.php`, `tests/Feature/Sales/PaymentTest.php`)
- [x] External payment reference stored — required where the method asks for it (`sale_payments.reference`)
- [x] Credit sales follow the customer's limit — all or part of a sale goes on account, on the till and the New sale form; refused over the limit, for Walk-in and for a customer with no limit (`app/Support/CustomerCredit.php`, `app/Services/SalePoster.php`, `tests/Feature/Sales/CreditSaleTest.php`)
- [ ] Approval of a credit sale over its limit — refused for now (stage 6, decision 2)

Every sale posted before phase 16 was recorded as paid in cash for its total
when the migration ran.

#### 4. Quotations and Sales Orders — ✅ Done
- [x] Dated quotations with an expiry date — valid for 15 days by default (a setting), changeable on each; past it an open quotation reads Expired and is quoted again at today's prices (`app/Http/Controllers/QuotationController.php`, `app/Services/QuotationDesk.php`, `resources/js/Pages/Quotations/`, `tests/Feature/Sales/QuotationTest.php`)
- [x] Quotation becomes an order or a sale without typing again — *Make a sale* or *Make an order* fills the form with the customer, the lines at the quoted prices and the discount; the quotation is then Accepted (`SaleController::create()`, `SalesOrderController::create()`, `SalePoster`)
- [x] Stock checked again at checkout — a quotation reserves nothing; the sale form says what is short now, and the ledger refuses the sale if the shelf cannot fill it (`app/Services/InventoryLedger.php`, `tests/Feature/Sales/QuotationTest.php`)
- [x] Approved orders reserve stock until release or expiry — approving holds the lines until a date (7 days by default, a setting); no other sale may take them; cancelling releases them, and past the date they lapse; the order becomes a sale taking its own reservation (`app/Services/SalesOrderDesk.php`, `app/Support/Reservations.php`, `tests/Feature/Sales/SalesOrderTest.php`)

#### 5. Returns and Exchanges — 🟡 Partly
- [x] A return is linked to the original sale (`app/Services/SalesReturnPoster.php`)
- [x] Quantity checked against what was sold
- [x] Refund recorded, at the cost of the batches it consumed
- [ ] Approval authority checked (stage 6)
- [ ] Exchanges (stage 6)
- [ ] Customer credit instead of a refund (stage 6)
- [ ] Damaged returns kept out of sellable stock — today every return goes back on the shelf (stage 6, decision 5)

#### 6. Cashier Shifts and Cash Balancing — ⬜ To do
- [ ] Opening cash (stage 4)
- [ ] Authorized cash-in and cash-out entries (stage 4)
- [ ] Closing count against expected cash (stage 4)
- [ ] Reason required for a shortage or overage (stage 4)
- [ ] Shift report, with noncash payments kept separate (stage 4)

#### 7. Transaction Controls — 🟡 Partly
- [x] Unique document numbers (`app/Services/DocumentNumber.php`)
- [x] Each document records who posted it (`posted_by`)
- [x] A void needs a reason; nothing is ever deleted
- [x] Stock cannot go below zero (`InventoryLedger`)
- [x] The sale keeps the price actually used (`sale_lines.unit_price`)
- [ ] Supervisor approval of price overrides (stage 6)
- [ ] Approval of discounts beyond a limit (stage 6)
- [ ] Approval of refunds and voids — a permission controls these today, not an approval (stage 6)

### Inventory

#### 8. Products — ✅ Done
- [x] Item code, name, category, unit, cost, selling price, reorder level (`ProductController`)
- [x] One photo per product (`app/Support/ProductPhoto.php`)
- [x] An inactive product keeps its history
- [x] Brand — typed on the product form, offered from the brands already used; searched, filtered on the Product Master, shown under the name and on the till's tiles (`database/migrations/2026_11_20_000001_add_product_details.php`, `app/Http/Controllers/ProductController.php`, `tests/Feature/Products/ProductDetailsTest.php`)
- [x] Model or size as its own field — shown beside the brand, searched (same files)
- [x] Barcode — one per product, kept in capitals; a scan and Enter on the till's search box adds the product to the basket, on any product picker (sale, purchase, request, order, quotation, adjustment) picks it, and the API finds it with `?barcode=` (`resources/js/lib/scan.js`, `resources/js/Components/ComboBox.vue`, `app/Http/Controllers/Api/V1/CatalogController.php`, `tests/js/productDetails.test.js`)
- [x] Preferred supplier — a suggestion on the product: an active supplier when picked, kept if switched off later, cleared when the supplier is deleted; filtered on the Product Master; a new purchase request fills in the first product's usual supplier until somebody picks one by hand (`app/Models/Product.php`, `resources/js/lib/usualSupplier.js`, `resources/js/Pages/PurchaseRequests/Create.vue`)

#### 9. Categories — ✅ Done
- [x] Categories list with status (`CategoryController`)
- [x] Used in search, sales by category, stock value by category and slow movers

#### 10. Units and Conversions — ✅ Done
- [x] Units list — 26 hardware units seeded (`database/seeders/HardwareUnitSeeder.php`)
- [x] Conversions between buying and selling units — a box of 12 adds 12 pieces. Each product lists its other units with how many it holds and an optional own price; sales, purchases and the till pick the unit, the stock stays in the product's own unit, returns count in pieces (`app/Models/ProductUnit.php`, `app/Support/UnitConversion.php`, `tests/Feature/Inventory/UnitConversionTest.php`)
- [x] Decimal quantities for weight, length and volume — up to 3 decimals where the unit's "Allows decimals" switch is on (kg, m, ltr, cu.m, sq.m seeded on); 2.5 pcs refused on every form and in the ledger (`app/Support/Quantity.php`, `app/Services/InventoryLedger.php`, `tests/Feature/Inventory/DecimalQuantityTest.php`)

#### 11. Pricing Rules — ⬜ To do
- [ ] Retail, wholesale and contractor prices (stage 6)
- [ ] Prices by quantity (stage 6)
- [ ] Effective dates (stage 6)
- [ ] Rule priority when several match (stage 6)
- [ ] Override with a reason (stage 6)

Today each product has one selling price.

#### 12. Stock Levels and Reorder Alerts — 🟡 Partly
- [x] Quantity on hand (`StockController`, `resources/js/Pages/Stock/Index.vue`)
- [x] Low-stock alerts — on hand at or under the reorder level
- [x] Reserved and available quantities — Reserved and Available columns on the Stock screen; the sale form and the till offer what is available; since phase 31.4 an order holds its stock at a location and both figures are counted per location (`StockController`, `Product::sellingOptions()`, `PosController`, `App\Support\Reservations`, `tests/Feature/Locations/SellAtLocationTest.php`)
- [ ] Pending purchase quantities — what is still to come on open orders, beside on hand on the Stock screen. **Left out of stage 5**: the figure exists (`PurchaseOrderLine::outstandingMilli()`) but no screen shows it yet; a small follow-up, waits on GO!
- [x] Stock by location — every batch and movement names a location; each product's figure at each place sits in `location_stocks` beside the total; the Stock screen narrows to one location (*At …* beside *All locations*) and the product page and dialog show *By location*; `inventory:verify` checks each location against its batches and their sum against the total (`app/Services/InventoryLedger.php`, `app/Models/LocationStock.php`, `app/Http/Controllers/StockController.php`, `tests/Feature/Locations/StockByLocationTest.php`)

#### 13. Stock Movements and Transfers — ✅ Done
- [x] Movement history with date, quantity, document and running balance (`MovementController`)
- [x] Stock card for each product
- [x] Locations — store and warehouse: the list (phase 31.1), the stock kept at each (31.2), a delivery received at the location picked, with *Deliver to* on the purchase order (31.3), and every sale, till sale, sales order, adjustment and opening stock naming its place, with both returns going back to or leaving from theirs (31.4) (`app/Http/Controllers/LocationController.php`, `app/Services/InventoryLedger.php`, `app/Services/PurchasePoster.php`, `app/Services/SalePoster.php`, `app/Services/AdjustmentPoster.php`, `tests/Feature/Locations/ReceiveAtLocationTest.php`, `tests/Feature/Locations/SellAtLocationTest.php`)
- [x] Transfers with a source and a destination — a posted document (*From*, *To*, lines in the product's own unit), prefix TR; the goods leave the source oldest batch first and arrive as batches of the same cost, so the total and the stock value do not change; stock reserved at the source is not moved; voided while what arrived is untouched, back into the batches it left (`app/Services/InventoryLedger.php`, `app/Services/TransferPoster.php`, `app/Http/Controllers/StockTransferController.php`, `tests/Feature/Locations/TransferTest.php`)

#### 14. Spoilage and Loss — ✅ Done
- [x] Loss recorded as a Decrease adjustment with reason, quantity and cost (`app/Services/AdjustmentPoster.php`)
- [x] Reasons: damaged or spoiled, lost, written off, count, other (`StoreAdjustmentRequest::REASONS`)
- [x] Losses shown on the dashboard
- [x] More reasons — expired, rusted or corroded, theft; *lost* now reads *Lost or missing* (`StoreAdjustmentRequest::REASONS`, `LOSS_REASONS`, `tests/Feature/Locations/LossReportTest.php`)
- [x] Photo as evidence — up to 5 on a loss report, on the private disk, served only to those who may read the report, carried by the backup; required for damaged, expired, rusted and written-off goods while Settings → Losses says so (`app/Support/LossPhoto.php`, `app/Http/Controllers/LossReportController.php`, `tests/Feature/Locations/LossReportTest.php`)
- [x] Approval before a write-off reduces stock — a loss report moves nothing until somebody holding `losses.approve` approves it, which posts the decrease adjustment; rejected or withdrawn, nothing is written off. Applies to every loss reported; Admin, the approver, can still post a decrease adjustment directly (`app/Services/LossReportDesk.php`, `app/Policies/LossReportPolicy.php`, `tests/Feature/Locations/LossReportTest.php`)
- [x] Holding place outside sellable stock pending a decision — while a report waits, its goods are **held aside at their own location**: still on the books, but no sale, till, transfer or customer order may take them; shown under *Reserved or held* on the Stock screen. Set aside in place, not moved to a separate damaged-goods location (`App\Support\Reservations::heldForLosses()`, `tests/Feature/Locations/LossReportTest.php`)

#### 15. Stock Counts and Adjustments — ✅ Done
- [x] Adjustments with "Count" as a reason
- [x] Opening stock through Adjustments
- [x] Count sheet comparing counted and system quantities — a count at one location (all products or one category), the book figure at the cutoff beside what was counted; the sheet is blind for the counters while open (the Stock screen still shows on hand); printed for counting by hand (`app/Services/StockCountDesk.php`, `app/Http/Controllers/StockCountController.php`, `resources/js/Pages/StockCounts/Show.vue`, `resources/js/Components/CountSheetPrint.vue`, `tests/Feature/Locations/StockCountTest.php`)
- [x] Variances presented for approval — handed in, the approver sees what is over and short with its value, then approves (posting them as adjustments, reason *count*, at the count's location) or sends it back for a recount (`StockCountDesk::approve()`, `StockCountPolicy`, `tests/Feature/Locations/StockCountTest.php`)
- [x] Count cutoff for transactions during the count — the book figures are taken when the count starts; only counted less that figure is posted, so sales and receipts after the cutoff stand; each line shows its change since the cutoff; one open count per location (`StockCountDesk::start()`, `tests/Feature/Locations/StockCountTest.php`)

### Reports and customers

#### 16. Sales and Reports — ✅ Done
- [x] Sales, purchases, returns and estimated gross profit (`ReportController`, `app/Services/InventoryMetrics.php`)
- [x] Best sellers, slow movers, sales by category
- [x] Filter by cashier on Reports — every block narrowed to the documents one user posted; without `dashboard.view_all_staff` only to oneself (`app/Support/ReportFilters.php`, `InventoryMetrics::narrowSales()`, `tests/Feature/Inventory/ReportFiltersAndExportTest.php`)
- [x] Filter by payment method — the sales paid at least in part that way and their returns, a split sale counted whole; Money received by method; purchases left out (`app/Support/ReportFilters.php`, `app/Services/ReportBook.php`, `tests/Feature/Inventory/ReportFiltersAndExportTest.php`)
- [x] Export to PDF and spreadsheet — Print / PDF prints the report (Save as PDF); Spreadsheet downloads an Excel workbook: summary, every sale, return and delivery, every breakdown row; no cost without `sales.view_cost` (`app/Support/Spreadsheet.php`, `app/Services/ReportBook.php`, `resources/js/Pages/Reports/Index.vue`, `tests/Feature/Inventory/ReportFiltersAndExportTest.php`)

#### 17. Customers and Credits — ✅ Done
- [x] Customer records — contact details, TIN, walk-in customer (`CustomerController`)
- [x] Credit limit and payment terms — on the customer form, behind `customers.credit`; zero means no credit; since phase 18.1 the customer's terms are the *usual* ones and the days are agreed on each sale, more than usual only with `customers.credit` (`app/Http/Requests/Concerns/ValidatesCredit.php`, `CustomerCredit::termsRefusal()`, `resources/js/Components/PaymentEntry.vue`, `tests/Feature/Customers/CustomerCreditTest.php`, `tests/Feature/Sales/SaleTermsTest.php`)
- [x] Outstanding balances and due dates — Balance column and "Owing only" filter on the list; limit, owed, credit left and unpaid sales on the customer's page (`Customer::scopeWithBalance()`, `resources/js/Pages/Customers/Show.vue`)
- [x] Full or partial collections against specific invoices — Customer Payments: one payment spread over named invoices, soonest due first; voided with a reason; a sale with a payment against it cannot be voided (`app/Services/CustomerPaymentPoster.php`, `app/Support/PaymentAllocation.php`, `tests/Feature/Customers/CustomerPaymentTest.php`)
- [x] Statement of account — balance brought forward, sales on account, return credits and payments with a running balance, for any period; printable, says it is not an official receipt (`CustomerCredit::statement()`, `resources/js/Pages/Customers/Statement.vue`)
- [x] Aging report — not yet due, 1–30, 31–60, 61–90 and over 90 days, by customer, as of today (`CustomerCredit::aging()`, `resources/js/Pages/Aging/Index.vue`)
- [x] Collectibles list — every invoice still owed, by month (due or sold), customer, status and staff, with totals (phase 19.1, the client's request of 12 November) (`CustomerCredit::openInvoices()`, `resources/js/Pages/Collectibles/Index.vue`, `tests/Feature/Customers/CollectiblesTest.php`)
- [x] Overdue and over-limit flags — on the customer list (with filters), the customer's page, the statement and aging; the till says what is past due (`CustomerCredit::flags()`, `Customer::scopeWithOverdue()`)

### Buying

#### 18. Purchase Requests — ✅ Done
- [x] Request with quantities, preferred supplier, estimated cost and reason — lines in any unit the product is bought in (`app/Http/Controllers/PurchaseRequestController.php`, `app/Services/PurchaseRequestDesk.php`, `resources/js/Pages/PurchaseRequests/`)
- [x] Pending, approved, rejected and closed status — approved or rejected by somebody holding `purchase_requests.approve` who did not raise it; closed with a reason (`app/Enums/PurchaseRequestStatus.php`, `app/Policies/PurchaseRequestPolicy.php`, `tests/Feature/Purchases/PurchaseRequestTest.php`)
- [x] Approved request becomes a purchase order — **Create order** fills the order from it, and the request closes as *Ordered on [PO-…]*; one request, one order — or, from a canvass award, one order per winning supplier (phase 24.3) (`app/Services/PurchaseOrderDesk.php`, `tests/Feature/Purchases/PurchaseOrderTest.php`)

#### 19. Suppliers and Purchase Orders — ✅ Done
- [x] Supplier records with contact person (`SupplierController`)
- [x] VAT registration per supplier (`suppliers.is_vat_registered`)
- [x] Purchase history per supplier
- [x] Payment terms — days per supplier; a delivery on account falls due by them (`suppliers.payment_terms_days`, `SupplierAccount::dueDate()`)
- [x] Purchase orders with costs and expected delivery — from an approved request or directly, printed for the supplier (`app/Http/Controllers/PurchaseOrderController.php`, `resources/js/Pages/PurchaseOrders/`, `tests/Feature/Purchases/PurchaseOrderTest.php`)
- [x] Order status and outstanding orders — open, partly received, received, closed short and cancelled, worked out from the deliveries posted against it; outstanding (open or partly received) counted and filtered on the list, late when past the expected date (`app/Enums/PurchaseOrderStatus.php`, `app/Support/OrderReceiving.php`, `tests/Feature/Purchases/PurchaseReceivingTest.php`)

#### 20. Goods Receiving and Supplier Returns — ✅ Done
- [x] A posted delivery adds stock (`app/Services/PurchasePoster.php`)
- [x] A supplier return reduces stock (`app/Services/PurchaseReturnPoster.php`)
- [x] Delivery matched against its purchase order — **Receive delivery** on the order fills the purchase form with what is still to come; each line names the order line it fills, and more than is still to come is refused (`app/Support/OrderReceiving.php`, `app/Services/PurchasePoster.php`, `tests/Feature/Purchases/PurchaseReceivingTest.php`)
- [x] Partial deliveries leave the order open — partly received until the last line is in full; a void gives the goods back to the order; an order whose rest is not coming is closed short with a reason (`PurchaseOrderDesk::closeShort()`)
- [x] Delivery discrepancies and cost changes recorded — what is still to come per line, goods delivered that were not on the order, and an invoiced cost different from the one agreed, shown on the order and on the delivery; `inventory:verify` checks every order line against its deliveries
- [x] Supplier credit recorded on a return — a return comes off an unpaid bill first; on a paid bill it stays as credit with the supplier, used in a later supplier payment (`PurchaseReturnPoster`, `SupplierAccount::credit()`, `tests/Feature/Purchases/SupplierPayablesTest.php`)

#### 21. Supplier Payables — ✅ Done
- [x] Supplier bills and due dates — a delivery is paid on delivery or put on the supplier's account, due by the supplier's terms; Payables lists every bill still owed, with aging (`purchases.on_account/balance_due/due_date`, `app/Http/Controllers/PayablesController.php`, `resources/js/Pages/Payables/Index.vue`)
- [x] Payments recorded against bills — a supplier payment pays named bills with money, supplier credit or both, voided with a reason (`app/Services/SupplierPaymentPoster.php`, `app/Support/BillAllocation.php`, `resources/js/Pages/SupplierPayments/`)
- [x] Balances, with purchase returns taken off — on the supplier's page, Payables and the dashboard's Payables card; `inventory:verify` checks every bill (`app/Support/SupplierAccount.php`)

### Control and set-up

#### 22. Staff Access — ✅ Done
- [x] Individual accounts (`UserController`)
- [x] Roles and permissions (`RoleController`, `config/permissions.php`)
- [x] Cost and profit limited to approved roles
- [x] A deactivated account keeps its history

Approval powers (credit, refunds, overrides) arrive with items 5, 7 and 17.

#### 23. Audit Log — ✅ Done
- [x] Who did what and when (`app/Services/AuditService.php`, `AuditTrailController`)
- [x] Old and new values kept
- [x] Voids, returns, adjustments, price changes and user changes logged
- [x] Read-only for every user

A customer's credit limit and terms are logged on its Update row, old and new.
Approvals are logged once they exist.

#### 24. Settings — 🟡 Partly
- [x] Business details and logo (`config/settings.php`)
- [x] Document numbers
- [x] VAT and tax settings
- [x] Units, categories and user roles (their own screens)
- [x] Print layouts — Settings → Print Layouts: paper size, margins, logo and company details on or off, terms on a quotation and a purchase order, a closing line; one letterhead and footer for the quotation, purchase order, statement, Aging and Reports (`resources/js/Components/PrintLetterhead.vue`, `resources/js/composables/usePrintLayout.js`, `tests/js/printLayout.test.js`)
- [x] Payment methods — their own screen, cash protected (`PaymentMethodController`, `tests/Feature/PaymentMethods/PaymentMethodTest.php`)
- [x] Locations — Masters → Locations: name, Store or Warehouse, address, active; *Main Store* and *Warehouse* put in by the migration; Admin keeps the list, Staff read it (`app/Http/Controllers/LocationController.php`, `app/Models/Location.php`, `resources/js/Pages/Locations/Index.vue`, `tests/Feature/Locations/LocationTest.php`)
- [ ] Approval limits (stage 6)
- [x] Backup and export settings — Settings → Backup and Export (how often, how many kept, photos in or out) and the Backups screen: make one now, download, delete; database and photos in one .zip, Admin only, audited; restored and verified on MariaDB (`app/Services/BackupMaker.php`, `app/Support/DatabaseDump.php`, `tests/Feature/BackupTest.php`)

### Client requests beyond the proposal

Asked for after the proposal, so they are kept apart from the 24 items and
do not change the totals above.

#### CR-1. Canvass and purchase-order approval (13 November 2026) — ✅ Done (6 of 6)
The client's nine steps: a department raises a request; Sir Fernan approves
it; suppliers are asked for prices; the prices are compared in an Abstract
of Quotation; Sir Fernan chooses a supplier for each item; orders are made
at those prices; Sir Fernan approves each order before it is sent.
- [x] Departments, and the department a request is raised for — a master list the owner keeps; one named on a request is switched off, never deleted (phase 24.1) (`app/Http/Controllers/DepartmentController.php`, `tests/Feature/Purchases/DepartmentTest.php`)
- [x] Print a request, and a Request for Quotation to hand suppliers — the RFQ lists the items and quantities with empty price columns, never our estimate, and prints only once the request is approved (phase 24.1) (`resources/js/Components/PurchaseRequestPrint.vue`, `tests/js/PurchaseRequestPrint.test.js`)
- [x] Enter each supplier's prices against an approved request — per item, per the unit asked for, with delivery days, terms and a note; one quote per supplier; removable until the award (phase 24.2) (`app/Services/CanvassDesk.php`, `resources/js/Pages/PurchaseRequests/Quote.vue`, `tests/Feature/Purchases/CanvassTest.php`)
- [x] Abstract of Quotation — the prices side by side, the lowest marked, printable; Sir Fernan awards a supplier per item, a dearer one only with a reason, and may reopen it (phase 24.2) (`app/Support/QuoteAbstract.php`, `resources/js/Components/CanvassPanel.vue`, `resources/js/Components/AbstractPrint.vue`, `tests/js/canvass.test.js`)
- [x] One request becomes an order per awarded supplier, at the quoted prices — one press, the lines in the unit asked for, *Deliver by* from the quoted delivery days, the request closed as ordered on all of them (phase 24.3) (`PurchaseOrderDesk::placeFromAward()`, `tests/Feature/Purchases/CanvassTest.php`)
- [x] An order is approved before it is sent; until then it prints as a draft — it waits *For approval*, nothing is received against it, Sir Fernan approves or rejects it with a reason, his own are approved as he places them, and the paper says *DRAFT — NOT APPROVED* with a blank *Approved by* line; an Orders to approve card on the dashboard (phase 24.4) (`app/Services/PurchaseOrderDesk.php`, `resources/js/Components/PurchaseOrderPrint.vue`, `tests/Feature/Purchases/PurchaseOrderApprovalTest.php`, `tests/js/PurchaseOrderPrint.test.js`)

#### CR-2. Pending payment: the kiosk takes the order, the cashier takes the money (24 November 2026) — ✅ Done (5 of 5)
From the client's meeting of 24 November: the kiosk is a tablet on the shop
floor, so beside *Take payment* it needs *Pending payment*, which sends the
order to the cashier, where the money is really received. Built on the
user's GO! on all five recommendations (Q1–Q5) as phase 17.1.
- [x] *Pending payment* at the kiosk sends the counter to the cashier, numbered `PP-…`, with no money taken (`app/Services/PendingPaymentDesk.php`, `resources/js/Pages/Pos/Index.vue`, `tests/Feature/Sales/PendingPaymentTest.php`)
- [x] Its goods are held at the till's store until it is paid, cancelled or the day closes — nobody else may sell them; nothing runs at closing (Q1, Q3) (`Reservations::heldForPendingPayments()`, Settings → Document Numbers)
- [x] The cashier takes the money on the till's *Pending* tab, or with *Receive payment* on Sales → Pending Payments in the back office, no kiosk needed; it becomes a sale at exactly the lines and prices sent, cash, GCash, split or on account (Q2) (`SalePoster`, `PendingPaymentDesk::lockedForSale()`, `resources/js/Components/ReceivePendingPayment.vue`)
- [x] Cancelled with a reason, which frees the goods; the sender cancels their own, whoever takes payment cancels any (`PendingPaymentPolicy`)
- [x] A slip for the customer marked *NOT PAID — pay at the cashier* and *not an official receipt*; **everybody at the kiosk has *Pending payment*, with nothing ticked**, and a kiosk role without *Record a sale* sends without being able to take money (Q4, Q5) (`resources/js/Components/OrderSlip.vue`, `tests/js/OrderSlip.test.js`)

#### CR-3. A light or a dark screen (26 November 2026) — ✅ Done (4 of 4)
The client's request: *"Provide UI customization options where the user can
change the background (options: white and dark)."* Read as a whole dark
theme, not only a background colour — dark grey behind dark text would be
unreadable. Built on the user's GO! on all four recommendations as phase 35.1.
- [x] Each person chooses for themselves — **Light, Dark or Same as my device** — from the switch in the top bar, where the search box that searched nothing used to be (moved there from the menu under their name the same day, at the user's request), or from three icons in the till's header (the till has no such menu); the choice is kept on their account, so it follows them to any computer, and the screen changes at once without reloading the page's figures (`resources/js/Components/AppearanceSwitch.vue`, `app/Http/Controllers/Account/AppearanceController.php`, `tests/Feature/Settings/AppearanceTest.php`)
- [x] Settings → Theme Settings → **Default appearance** for anyone who has not chosen (Light to start, so nothing changed for anybody until they pick) (`config/settings.php`, `App\Support\ThemeStyles::appearanceFor()`)
- [x] Every screen inside the app turns dark — lists, dialogs, forms, the till, the charts, the alerts — and the brand colour carries through, lifted so a near-black brand stays visible; a test fails if a screen uses a colour that cannot turn dark (`resources/css/app.css`, `resources/js/lib/charts.js`, `tests/js/appearance.test.js`)
- [x] **Paper and the sign-in pages stay light**: a document printed from the dark screen prints white, and signing out returns to the light sign-in page at once (`resources/js/lib/appearance.js`)

#### CR-4. Mobilization on a quotation (27 November 2026) — ✅ Done (4 of 4)
The client's request: a **Mobilization** field above the discount on a
quotation — the discount takes off the total, mobilization adds to it, both
worked out as typed. Read as a charge for bringing the goods to the site (a
service: no stock, no cost) that the customer must also pay when the
quotation becomes a sale. Built on the user's GO! on all five
recommendations as phase 28.1.
- [x] Mobilization on the quotation form, above Discount, worked out as typed: total = subtotal − discount + mobilization, never discounted, VAT-inclusive, never negative; on the printed quotation and its dialog (`app/Support/SaleTotals.php`, `resources/js/lib/money.js`, `resources/js/Pages/Quotations/`, `tests/Feature/Sales/MobilizationTest.php`)
- [x] Carried to the sales order and the sale — *Make a sale*, *Make an order*, a sale from an order and *Quote again* start on it; the sale is paid in full with it, cash, split or on account; on the sale and order pages, their dialogs and the slip (`SaleController`, `SalesOrderController`, `QuotationController`, `resources/js/Components/SaleSlip.vue`)
- [x] Never refunded by a return — the refund is spread over the goods only, so a fully returned sale on account still owes the charge; `inventory:verify` stays clean (`SalesReturnPoster::proratedLineTotal()`, `Sale::goodsTotal()`)
- [x] Counted in net sales and gross profit, with its own *Mobilization charged* line on Reports and a column in the spreadsheet; left out of products, categories, top sellers and the dashboard while narrowed to a category or a product; the till has none and a pending payment may not add one (`app/Services/InventoryMetrics.php`, `app/Services/ReportBook.php`, `tests/Feature/Sales/PendingPaymentTest.php`)

---

## 2. Acceptance checks (proposal section 10)

| Check | Status | Stage |
|---|---|---|
| A sale using a unit conversion | ✅ | 1 |
| Correct cash and noncash totals | ✅ Collections and the till's day split by method, from payments that add up to each sale (`InventoryMetrics::collections()`) | 2 |
| A credit sale followed by a partial collection | ✅ a sale on account, then a customer payment against part of it; the balance, statement and aging follow (`tests/Feature/Customers/CustomerPaymentTest.php`) | 3 |
| Partial goods receiving | ✅ an order received in two deliveries, partly received in between (`tests/Feature/Purchases/PurchaseReceivingTest.php`) | 5 |
| An approved return or loss | 🟡 a loss is approved before it is written off (phase 33, `tests/Feature/Locations/LossReportTest.php`); a return still has no approval step (stage 6) | 6 and 8 |
| A stock count adjustment | ✅ a count sheet handed in, approved, and its differences posted as adjustments at the location; sales after the cutoff not counted twice (`tests/Feature/Locations/StockCountTest.php`) | 8 |
| Permission restrictions | ✅ | — |
| Report reconciliation (`php artisan inventory:verify`) | ✅ | — |
| A demonstrated backup restoration | 🟡 demonstrated on the development books, 13 November 2026: a backup restored into an empty MariaDB database, every table's rows and the product and sale checksums matched, `inventory:verify` passed. Do it once more on the server at go-live (`README.md`, "Backups") | 9, go-live |

---

## 3. Decisions needed from the client

Each one reverses a rule the system was built on. Do not start the stage it
blocks until it is answered, then write the answer here and in `CLAUDE.md`.

| # | Decision | Blocks | Answer |
|---|---|---|---|
| 1 | Which products need decimal quantities, and which buying and selling units need conversions? | Stage 1 | **Decimals, 2026-11-08** (GO! on the suggestion): a switch on each unit; kg, m, ltr, cu.m and sq.m on, everything else (ft too) whole; 3 decimal places. **Conversions, 2026-11-08** (GO! on the four recommendations): (a) a box has its own price, and left empty it is the piece price × the number it holds; (b) purchases convert too, at the cost of one piece; (c) returns count in pieces; (d) adjustments and opening stock count in the product's own unit only |
| 2 | Which documents need approval before they post, and who approves each? | Stages 5, 6, 7 | **Sales orders, default taken 2026-11-12 (phase 29):** whoever holds `sales_orders.approve` (Admin to start) approves, their own included; approving reserves the stock. **Purchase requests, default taken on GO!, 2026-11-12:** whoever holds `purchase_requests.approve` (Admin to start) approves or rejects. Answering one's own request is the setting *An approver may approve their own request* (Settings → Purchasing), on by default (option A, 2026-11-12), recorded in the audit trail as approving one's own. Another approver is a tick on the Roles screen. **Loss reports, default taken on GO!, 2026-11-23 (phase 33):** whoever holds `losses.approve` (Admin to start) writes a reported loss off or rejects it; their own included, said in the audit trail; the reporter may withdraw their own. **Stock counts, default taken on GO!, 2026-11-23 (phase 32):** whoever holds `stock_counts.approve` (Admin to start) approves a handed-in count — posting its differences — or sends it back; their own included, said in the audit trail. *Still waiting* for stages 6 and 7 (overrides, discounts, refunds, voids, credit over the limit) |
| 3 | Discount per item, or per sale as now? What limit needs approval? | Stage 6 | *Waiting* |
| 4 | How many stock locations, and who may move stock between them? | Stage 8 | *Waiting.* **19 November 2026:** the client says deliveries arrive at the store or the warehouse, and both hold stock. **20 November 2026:** the list starts with *Main Store* and *Warehouse* (phase 31.1, renamed or added to on the Locations screen). **Default taken on GO! for phase 31.2, not the client's answer:** the stock already on the books sits at **Main Store**, the default location every document posts at until 31.3 and 31.4 let it choose — this reverses the earlier suggestion of the Warehouse, which would have left the till nothing to sell until transfers exist. **Default taken on GO! for phase 31.3:** a purchase order has an optional *Deliver to*, starting on the default location; a delivery's *Receive at* starts on it, else on the default, and may be changed. **Default taken on GO! for phase 31.4:** the till sells only from a **Store** (the default, else the first store), the sale form from any location; an adjustment and opening stock name their location; a sales order holds its stock at its own location, and a sale is weighed only against what is held at its place. **Default taken on GO! for phase 31.5:** a transfer is one step — posted on save, no approval and no *in transit*; Staff may record one, voiding stays with Admin; it moves the goods at the cost they already carry and never moves stock reserved at the source; a delivery moved away by a transfer is returned to its supplier or voided only after the transfer is voided — and once the moved goods have been sold or moved on at the other place, no longer against that delivery (a known limit; a return that follows a moved batch can be built later). Still to answer: whether the till may ever sell from a warehouse; who may transfer, and whether a transfer needs approval |
| 5 | Should each returned item be marked sellable or damaged? | Stage 6 | *Waiting* |
| 6 | Collections and cash position stay estimates until payments are recorded. | — | **Payments recorded, 2026-11-09** (phase 16; GO! on the three recommendations: Cash, GCash, Maya and Bank transfer to start; a reference number required for GCash, Maya and Bank; sales-return refunds stay cash until stage 6). Collections is now real. **Since phase 24 (2026-11-12) Net cash from trading counts a delivery on account when the supplier is paid**, and a purchase return that came off a bill or stayed as supplier credit moves no cash; a delivery paid on delivery still counts on its date |

---

## 4. Work plan

Each stage is split into **phases**, numbered on from phase 13 (the last
phase of the original build). One phase is one piece of work with its own
tests. The client reads the stages; the phases are how we build them. The
count may change once decisions 2 to 5 are answered. Two locations were
asked for on 19 November 2026 (a delivery is received at the store or the
warehouse), so phase 31 grew into 31.1 to 31.5.

**Progress: 7 of 9 stages and 22 of 26 phases done** (the client-request phases — 17.1, 18.1, 19.1, 24.1 to 24.4, 28.1 and 35.1 — are built on top and not counted among the 26). Stage 5 was taken ahead of stage 4 (12 November 2026); stage 9's phase 34 ahead of stages 4, 6 and 8 (13 November 2026); stage 8's phase 30 ahead of stages 4 and 6 (20 November 2026).

| Stage | Phase | Work | Completes | Status |
|---|---|---|---|---|
| **1** Units, conversions and decimal quantities | 14 | Decimal quantities — 2.5 kg, 2.5 m | #10, units part of #2 | ✅ Done |
| | 15 | Unit conversions — 1 box = 100 pcs; buy and sell in any unit, stock kept in the product's own unit | | ✅ Done |
| **2** Payments at the till | 16 | Payment methods, cash received and change, split payment, payment reference | #2, #3 | ✅ Done |
| | 17 | Hold and recall a sale, choose a customer, print the slip | | ✅ Done |
| | 17.1 | Pending payment — the kiosk sends the order, the cashier takes the money (client's meeting, 24 November) | CR-2 | ✅ Done |
| **3** Customer credit and collections | 18 | Credit limit, terms, credit sale, customer balance | #17, Overdue card on #1 | ✅ Done |
| | 19 | Collections against invoices, statement of account, aging, Overdue card | | ✅ Done |
| | 19.1 | Collectibles list by month, customer and status (client's request, 12 November) | | ✅ Done |
| | 18.1 | Terms agreed on each sale, starting on the customer's usual terms; why a customer has no *On account* (the user's request, 24 November) | | ✅ Done |
| **4** Cashier shifts and cash balancing | 20 | Open and close a shift, cash in and out, shortage or overage, shift report | #6, cashier differences on #1 | ⬜ Waits on GO! |
| **5** Buying chain | 21 | Purchase requests with approval, Purchase requests card | #18, #19, #20, #21, Purchase requests card on #1 | ✅ Done |
| | 22 | Purchase orders | | ✅ Done |
| | 23 | Receiving against an order — partial deliveries and discrepancies | | ✅ Done |
| | 24 | Supplier payables — bills, payments, balances, supplier credit | | ✅ Done |
| | 24.1 | Departments; print a request and a Request for Quotation (client's request, 13 November) | CR-1 | ✅ Done |
| | 24.2 | Canvass: supplier prices, the Abstract of Quotation, the award per item | CR-1 | ✅ Done |
| | 24.3 | Orders from the award — one per supplier, at the quoted prices | CR-1 | ✅ Done |
| | 24.4 | Purchase-order approval; a draft prints marked as not approved | CR-1 | ✅ Done |
| **6** Pricing rules and approvals | 25 | Retail, wholesale and contractor prices, prices by quantity, effective dates | #11, #7, #5 | ⬜ Not started |
| | 26 | Supervisor approval of overrides, discounts, refunds, voids and credit sales over the limit; approval limits | | ⬜ Not started |
| | 27 | Returns — exchange, customer credit, sellable or damaged | | ⬜ Not started |
| **7** Quotations and sales orders | 28 | Quotations | #4, reserved part of #12 | ✅ Done |
| | 29 | Sales orders with reserved stock; reserved and available quantities | | ✅ Done |
| | 28.1 | Mobilization on a quotation, carried to the order and the sale (client's request, 27 November) | CR-4 | ✅ Done |
| **8** Locations, counts, losses | 30 | Product brand, model, barcode, preferred supplier | #8, #12, #13, #14, #15 | ✅ Done |
| | 31.1 | Locations: the list of stores and warehouses (Masters → Locations) | #24 (locations) | ✅ Done |
| | 31.2 | Stock per location: the ledger, the batches and on hand count each place; the stock on the books goes to one starting location | #12 (by location) | ✅ Done |
| | 31.3 | Receive at: a delivery posts into the location chosen; *Deliver to* on the purchase order | #13 (locations) | ✅ Done |
| | 31.4 | Sell, return and adjust at a location: the till, sales, both returns, adjustments and opening stock name their place | #12, #13 (locations) | ✅ Done |
| | 31.5 | Transfers: stock moved from one location to another at the same cost, with a source and a destination | #13 (transfers) | ✅ Done |
| | 32 | Count sheet with variances for approval | #15 | ✅ Done |
| | 33 | Losses — photo, approval, holding place, more reasons | #14 | ✅ Done |
| **9** Reports and settings | 34 | Report filters, export to PDF and spreadsheet | #16, #24 | ✅ Done |
| | 35 | Print layouts, backup and export settings | | ✅ Done |
| | 35.1 | A light or a dark screen, chosen by each person (client's request, 26 November) | CR-3 | ✅ Done |

Phase 30 depends on nothing else and could be built alongside stage 2, so
the till can scan barcodes sooner.

---

## 5. Change log

Newest first. One line for each update.

- **2026-11-27** — **Phase 28.1 built: Mobilization** (CR-4, the client's
  request of 27 November), on the user's GO! on all five recommendations. A
  charge for bringing the goods to the site, typed above the discount on a
  quotation, a sales order and a sale: total = subtotal − discount +
  mobilization, never discounted; carried from a quotation to the order and
  the sale (and by *Quote again*); never refunded by a return; in net sales
  and gross profit, with its own Reports line and spreadsheet column, and
  left out of every goods figure. The till has none. A new column on three
  tables, so a deploy runs `migrate` and needs the new `public/build`; no new
  permission or setting. CR-4: 4 of 4 boxes; proposal totals unchanged.
  1,328 PHP and 239 front-end tests pass; `inventory:verify` clean, and a
  sale with the charge tried on the MariaDB books inside a rolled-back
  transaction. The flaky test noted on 23 November is
  `ProductPhotoTest` (*a large photograph is shrunk*): it failed in two full
  runs of three and passes every time on its own, with or without this
  phase — not yet fixed.
- **2026-11-26** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  (23 November): phase 17.1 (CR-2), phase 18.1, lists that remember where you
  were, and phase 35.1 (CR-3, the light or dark screen, its switch moved into
  the top bar in place of the search box that searched nothing) were built;
  besides them, GO tab icons in place of a template's handshake, PHP's
  `bcmath`, `gd`, `zip` and `xmlwriter` required in `composer.json` (the VPS
  lacked `bcmath`, and every Sales screen answered 500), and the navy
  DeltaTech symbol on every light background — all committed, merged into
  `main` and pushed (`6fbe51c`). All 22 open boxes checked against the code —
  none is built yet (no shift, no price list, no approval limit, no return
  approval, exchange or damaged return, no pending purchase quantities on
  the Stock screen). No box changes; no client decision given. 1,316 PHP and
  235 front-end tests pass; `inventory:verify` clean on all five checks.
  Totals stay 15 / 7 / 2; 7 of 9 checks; 7 of 9 stages, 22 of 26 phases (the
  client-request phases 17.1, 18.1, 19.1, 24.1–24.4 and 35.1 are not counted
  among the 26). Next: stage 4 or 6, on GO!.
- **2026-11-26** — **Phase 35.1 built: a light or a dark screen** (CR-3, the
  client's request of 26 November), on the user's GO! on all four
  recommendations: each person picks Light, Dark or Same as my device from
  the top bar (where the empty search box was), kept on their account; Theme Settings holds the
  default for anyone who has not chosen (Light); paper and the sign-in pages
  stay light. A new column, `users.appearance`, and a new setting, so a
  deploy runs `migrate` and `config:cache` and needs the new `public/build`;
  no new permission. CR-3: 4 of 4 boxes; proposal totals unchanged.
  1,316 PHP and 235 front-end tests pass.
- **2026-11-24** — **Phase 17.1 built: Pending payment** (CR-2, the client's
  meeting of 24 November), on the user's GO! on all five recommendations. At
  the kiosk, *Pending payment* beside *Take payment* sends the order to the
  cashier as `PP-…`; its goods are held at the store until it is paid,
  cancelled or the day closes; the cashier takes the money on the till's
  *Pending* tab (or from Sales → Pending Payments) at exactly the lines and
  prices sent; a slip marked NOT PAID can be printed. The same day, after
  the client's *Sales Encoder* role showed no button, it was tied to the
  kiosk on the user's GO!: anyone who signs in at the kiosk may send one,
  anyone who may record a sale takes its money or cancels any; only the
  back-office list needs the new `pending_payments.view`, so a deploy runs
  `permissions:sync`. Then *Receive payment* was added to the back-office
  list and page, so the money is taken at a desktop too (Admin has no
  kiosk). CR-2: 5 of 5 boxes; proposal totals unchanged.
  1,306 PHP and 220 front-end tests pass.

- **2026-11-24** — On the user's GO! (all three recommendations): **every list
  remembers where you were** — the sort, filters, search, page and scroll
  come back, and the row last opened is marked, after any breadcrumb,
  "Back to" or Cancel (`lib/listMemory.js`, `DataTable`, `PageHeader`,
  `PrimaryButton`); every full page shows a **breadcrumb**; and **Customers,
  Suppliers and Products open in a dialog** on their lists (new
  `/customers/{id}/preview` and `/suppliers/{id}/preview`). No box, mark or
  stage changes — it is how every screen behaves, not a scope item. 1,273 PHP
  and 212 front-end tests pass.

- **2026-11-24** — Phase 18.1 built on the user's GO! (all three
  recommendations): the payment terms are agreed on each sale. *On account*
  now has **Terms (days)** beside the amount, starting on the customer's
  usual terms, with the due date said under it; fewer days anybody may give,
  more needs `customers.credit`; kept as `sales.terms_days` and shown on the
  sale, its dialog, the slip, the message after posting and the statement;
  76 sales already on account given their days by the migration, no due
  date moved. A customer with no credit limit is now told why there is no
  *On account* (the Aling Nena Hardware case). The box under #17 says so; no
  mark, total or stage changes. The same day, at the user's request, the
  customer list's **Balance** column became sortable (`TableQuery` now sorts
  by a named figure the query selects). 1,270 PHP and 196 front-end tests
  pass.

- **2026-11-23** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  phase 33 was committed, merged into `main` and pushed (`ea73899`). All 22
  open boxes checked against the code — none is built yet (no shift, no
  price list, no return approval or exchange, no pending purchase quantities
  on the Stock screen). No box changes. 1,251 PHP and 173 front-end tests
  pass (one run in three had a single failure that did not come back — a
  flaky test, not yet found); `inventory:verify` clean on all five checks.
  Totals stay 15 / 7 / 2; 7 of 9 checks; 7 of 9 stages, 22 of 26 phases.
  Next: stage 4 or 6, on GO!.
- **2026-11-23** — Phase 33 built on GO!: **loss reports** (Inventory → Loss
  Reports, prefix LR). A loss is reported with its reason — three new ones:
  expired, rusted or corroded, theft; *lost* now reads *Lost or missing* —
  and up to 5 photos, kept on the private disk and carried by the backup; a
  photo is required for damaged, expired, rusted and written-off goods
  (Settings → Losses). While it waits the goods are **held aside at their
  location** — on the books, but no sale, transfer or customer order may
  take them; an approver writes it off (a decrease adjustment with the same
  reason) or rejects it, freeing them. Staff report; writing off stays with
  Admin. #14 ✅ (all four boxes ticked); *An approved return or loss* stays
  🟡 (returns, stage 6). **Stage 8 is finished.** Totals 15 / 7 / 2; 7 of 9
  checks; 7 of 9 stages, 22 of 26 phases. 1,251 PHP and 173 front-end tests
  pass; migration applied locally, `permissions:sync` run,
  `inventory:verify` clean. Next: stage 4 or 6, on GO!.
- **2026-11-23** — Phase 32 built on GO!: **the count sheet** (Inventory →
  Stock Counts, prefix CNT). Starting a count at a location takes the book
  figures as of that moment (the cutoff); one open count per location; the
  counters see no book figure on the sheet while counting and may print
  the sheet to count by hand; handed in, the approver sees what is over and
  short with its value and approves — posting counted less the cutoff
  figure as two adjustments (reason *count*) at the location, so sales after
  the cutoff are not counted twice — or sends it back for a recount; cancel
  while open. Staff count and hand in; approving stays with Admin. #15 ✅
  (all three boxes ticked); acceptance check *A stock count adjustment* ✅.
  Totals 14 / 8 / 2; 7 of 9 checks; 6 of 9 stages, 21 of 26 phases. 1,236
  PHP and 173 front-end tests pass; migration applied locally,
  `permissions:sync` run, `inventory:verify` clean, and a count tried on the
  MariaDB books inside a rolled-back transaction. Next: phase 33, on GO!.
- **2026-11-23** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  phase 31.5 was committed, merged into `main` and pushed (`8d7c924`). All 29
  open boxes checked against the code — none is built yet (no count sheet,
  no loss approval or photo, no shift, no price list, no pending purchase
  quantities on the Stock screen). No box changes. 1,217 PHP and 171
  front-end tests pass; `inventory:verify` clean on all five checks. Totals
  stay 13 / 9 / 2; 6 of 9 checks; 6 of 9 stages, 20 of 26 phases. Next:
  phase 32, on GO!.
- **2026-11-22** — Phase 31.5 built on GO!: **transfers** (Inventory →
  Transfers, prefix TR). The goods leave *From* oldest batch first and
  arrive at *To* as batches of the same cost, so the total and the stock
  value do not change; one step, no approval, no *in transit*; Staff may
  record one, voiding stays with Admin; stock reserved at the source is not
  moved; a void puts the goods back into the batches they left, only while
  what arrived is untouched; a delivery moved away is returned or voided
  only after its transfer is voided — both refusals name the transfer, and
  offer its void only while what it brought in is untouched; once those goods
  are sold or moved on there, they can no longer be returned against that
  delivery (a known limit).
  #13 ✅ (transfers ticked). Decision 4: the 31.5 defaults written in. Totals
  13 / 9 / 2; 6 of 9 checks; 6 of 9 stages, 20 of 26 phases. 1,217 PHP and
  171 front-end tests pass; migration applied locally, `permissions:sync`
  run, `inventory:verify` clean, and a transfer and its void tried on the
  MariaDB books inside a rolled-back transaction. Next: phase 32, on GO!.
- **2026-11-22** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  phase 31.4 was built and recorded, then committed, merged into `main` and
  pushed (`3407ddf`). All 30 open boxes checked against the code — none is
  built yet (no transfer exists). No box changes. 1,199 PHP and 171
  front-end tests pass; `inventory:verify` clean on all five checks. Totals
  stay 12 / 10 / 2; 6 of 9 checks; 6 of 9 stages, 19 of 26 phases. Next:
  phase 31.5, on GO!.
- **2026-11-22** — Phase 31.4 built on GO! (21 November): a sale names **Sell
  from** and is weighed at that place; the till sells only from an active
  store, with a picker for two or more; a sales order **holds its stock at a
  location**, and reservations, *Reserved* and *Available* are counted per
  location; an adjustment and opening stock name their **Location**; a sales
  return shows *Back to* and a purchase return *Taken from*; the sales and
  adjustments lists show and filter the place, and the API sends it. A
  location named only by an order is no longer deletable (a purchase order's
  *Deliver to* had the same gap since 31.3), and a delivery form sent twice
  with no location key now lands on its first delivery instead of clashing.
  Migration applied locally: 3,139
  sales, 30 adjustments and 1 sales order set to Main Store, none at odds
  with its movements; `inventory:verify` clean. Defaults recorded under
  decision 4. #12 and #13 notes updated; no box changes. 1,199 PHP and 171
  front-end tests pass. Totals stay 12 / 10 / 2; 6 of 9 checks; 6 of 9
  stages, 19 of 26 phases. Next: phase 31.5, on GO!.
- **2026-11-21** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  only the *Received at* display on a purchase order was built, then
  committed, merged into `main` and pushed (`5c38b81`). All 30 open boxes
  checked against the code — none is built yet. No box changes. 1,178 PHP
  and 171 front-end tests pass; `inventory:verify` clean on all five checks.
  Totals stay 12 / 10 / 2; 6 of 9 checks; 6 of 9 stages, 18 of 26 phases.
  Next: phase 31.4, on GO!.
- **2026-11-21** — Small display fix on phase 31.3, on GO!: a purchase
  order's dialog shows **Received at** (the places of its deliveries that
  still stand) and its page names the place on each delivery, so an order
  placed before 31.3 — *Deliver to: Not said* — still shows where its goods
  went. Display only: no order is edited, the paper is unchanged, no box
  changes. 1,178 PHP tests and 171 front-end tests pass.
- **2026-11-21** — Checked again (`UPDATE CHECKLIST!`). Since the last check
  phase 31.3 was built and recorded, the page content was made full width on
  large screens (no box), and everything was committed, merged into `main`
  and pushed (`3e5442a`). All 30 open boxes checked against the code — none
  is built yet. No box changes. 1,177 PHP and 171 front-end tests pass;
  `inventory:verify` clean on all five checks. Totals stay 12 / 10 / 2; 6 of
  9 checks; 6 of 9 stages, 18 of 26 phases. Next: phase 31.4, on GO!.

- **2026-11-20** — **Phase 31.3 built: Receive at, and Deliver to** (stage 8,
  on the user's GO!, with defaults while decision 4 stays open). A delivery
  names the location the goods go on the shelf at (`purchases.location_id`,
  required on the form, any active location; a form with no such key at all
  posts at the default), and `PurchasePoster` reads it under a shared lock
  and passes it to the ledger. A purchase order may name *Deliver to*
  (`deliver_to_location_id`, optional, the form starting on the default;
  orders from an award deliver to the default); it prints on the order with
  the address, and *Receive delivery* starts there unless that place was
  switched off. The purchase list filters and shows *Received at*; the page,
  dialog and API say it. Every delivery already on the books was set to where
  its batches sit (430 locally, all Main Store). #13's *Locations* box ticked
  (#13 stays 🟡: transfers, 31.5). Totals unchanged 12 / 10 / 2; 6 of 9
  checks; 6 of 9 stages, 18 of 26 phases. 1,177 PHP and 171 front-end tests
  pass; `inventory:verify` clean. A deploy runs `migrate`; no new permission.

- **2026-11-20** — Checked again (`UPDATE CHECKLIST!`). Nothing new built
  since phase 31.2 beyond two refinements inside it: making another location
  the default now checks again, with the rows locked, that the old default
  holds no stock, and the Stock screen hides *Reserved* while one location is
  shown (reservations are still product-wide until 31.4). All 34 open boxes
  checked against the code — none is built yet. No box changes. 1,165 PHP and
  169 front-end tests pass; `inventory:verify` clean on all five checks.
  Totals stay 12 / 10 / 2; 6 of 9 checks; 6 of 9 stages, 17 of 26 phases.
  Next: phase 31.3, on GO!.

- **2026-11-20** — **Phase 31.2 built: stock per location** (stage 8, on the
  user's GO!, with defaults while decision 4 stays open). Every batch and
  movement names a location; `location_stocks` keeps each product's figure at
  each, beside `products.on_hand`; only `post()` takes a location (the
  default), a void, a return and a purchase return follow the batches they
  touch; a sale eats only its own location's batches and says where the rest
  sits. **Main Store is the default**, and everything on the books was put
  there (12,879.5 units locally, `inventory:verify` clean before and after).
  The default never switches off, never deletes, and does not change while it
  holds stock; a location holding stock stays on; one with history is never
  deleted. `data:reset` puts back one default location. The Stock screen
  narrows to a location; the product page shows *By location*. #12's *Stock
  by location* box ticked (#12 stays 🟡: pending purchase quantities). Totals
  unchanged 12 / 10 / 2; 6 of 9 checks; 6 of 9 stages, 17 of 26 phases.
  1,165 PHP and 169 front-end tests pass. A deploy runs `migrate`; no new
  permission.

- **2026-11-20** — **Phase 31.1 built: Locations** (stage 8, on the user's
  GO!). Phase 31 is split into **31.1 to 31.5** on the client's concern of 19
  November (a delivery is received at the store or the warehouse). Masters →
  Locations: a name, Store or Warehouse, an address, active or not; *Main
  Store* and *Warehouse* arrive with the migration; `locations.*`, Admin keeps
  the list, Staff read it. Nothing counts stock per location yet (31.2, which
  waits on decision 4). #24's *Locations* box ticked; #24 stays 🟡 (approval
  limits, stage 6). Totals unchanged 12 / 10 / 2; 6 of 9 checks; 6 of 9
  stages, 16 of 26 phases. 1,143 PHP and 169 front-end tests pass. A deploy
  runs `migrate` and `permissions:sync`.

- **2026-11-20** — **Phase 30 built: product brand, model or size, barcode
  and preferred supplier** (stage 8, taken ahead of stages 4 and 6 on the
  user's GO!). All four optional on the product form; the barcode one per
  product, in capitals; the preferred supplier a suggestion that a deleted
  supplier only clears. A scan finds the product on the till (Enter adds it
  to the basket), on every product picker, on the Product Master and through
  the API (`?barcode=`); a new purchase request fills in the first product's
  usual supplier. Item #8 ✅ (4 boxes ticked). Totals 12 / 10 / 2; 6 of 9 checks;
  6 of 9 stages, 15 of 22 phases. 1,129 PHP and 169 front-end tests pass. No new permission; a deploy runs `migrate`.

- **2026-11-14** — **Phase 24.4 built: purchase-order approval** (CR-1), on
  Q6 and Q7. A new order waits *For approval* unless Sir Fernan places it
  himself (approved at once, while *An approver may approve their own request
  or order* is on); he approves it, or rejects it with a reason. Nothing is
  received against an order waiting, and it prints only as *DRAFT — NOT
  APPROVED* with a blank *Approved by* line. Every order already on the books
  counts as approved and reprints as before. New: an **Orders to approve**
  card and a waiting count on the Purchase Orders list. The phase 22 rule *an
  order has no approval of its own* is reversed. **CR-1 finished: 6 of 6
  boxes.** Proposal totals unchanged: 11 / 11 / 2. 1,109 PHP and 154
  front-end tests pass. Migration applied locally; `purchase_orders.approve`
  is a new permission.

- **2026-11-14** — **Phase 24.3 built: orders from the award** (CR-1). On an
  awarded request, **Create orders from award** places one purchase order per
  winning supplier — its items in the unit asked for, at its quoted prices,
  *Deliver by* from its quoted delivery days — and closes the request as
  ordered on all of them; an item nobody quoted is named as not ordered. *One
  request, one order* now holds only for a request ordered by hand. CR-1: 5 of
  6 boxes. Proposal totals unchanged: 11 / 11 / 2. 1,099 PHP and 151
  front-end tests pass. Migration applied locally; no new permission.
- **2026-11-14** — **Phase 24.2 built: the canvass** (CR-1), on the user's GO!
  on all seven recommendations (Q1–Q7). On an approved request: **Add supplier
  quote** (prices per item, delivery days, terms, a note; one per supplier;
  removable until the award), the **Abstract of Quotation** on screen and on
  paper (lowest marked), **Choose suppliers** (the lowest pre-chosen, a dearer
  one only with a reason, an unquoted item left out) and **Reopen award** with
  a reason. An awarded request is no longer ordered by hand — its orders come
  in 24.3. CR-1: 4 of 6 boxes. Proposal totals unchanged: 11 / 11 / 2.
  1,093 PHP and 151 front-end tests pass. Migration applied locally;
  `canvass.*` are new permissions.
- **2026-11-14** — **Phase 24.1 built: Departments and printing a purchase
  request**, the first of four phases for the client's canvass and PO
  approval request of 13 November (CR-1, a new block beside the 24 items).
  A Departments master list (Electrical and Construction to start; switched
  off, never deleted, once a request names it); an optional Department on
  every request, filtered on the list; **Print PR** on every request and
  **Print RFQ** on an approved one — the items and quantities with empty
  price columns, never our estimate. CR-1: 2 of 6 boxes. Proposal totals
  unchanged: 11 / 11 / 2, 6 of 9 checks; 6 of 9 stages, 14 of 22 phases.
  1,077 PHP and 146 front-end tests pass. Migration applied locally;
  `departments.*` are new permissions.
- **2026-11-13** — **Phase 35 built: print layouts and backups** — **stage 9
  done**. Settings → Print Layouts (paper, margins, logo and company details,
  terms on a quotation and a purchase order, a closing line) drives one
  letterhead and footer on the quotation, purchase order, statement, Aging and
  Reports; every default prints as before, and the slip is untouched. Settings
  → Backup and Export and a Backups screen: one .zip with the whole database
  (one consistent snapshot) and the photos, never `.env`; made now or daily or
  weekly by the scheduler, the newest 7 kept, Admin only, audited; restored by
  hand. A backup of the development books was restored into an empty MariaDB
  database and passed `inventory:verify`. #24's print layouts and backup boxes
  ticked (#24 stays 🟡: locations and approval limits belong to stages 8 and
  6). Acceptance check 9 🟡 (demonstrated locally, repeat at go-live). Totals
  stay 11 / 11 / 2; 6 of 9 checks; 6 of 9 stages, 14 of 22 phases. 1,063 PHP
  and 141 front-end tests pass. No migration; `backups.*` are new
  permissions.
- **2026-11-13** — Checked again after phase 34 (`UPDATE CHECKLIST!`).
  Nothing built since: phase 34 was committed, merged into `main` and pushed
  (`eec7c79`). No box changes; stage 9's two open boxes (print layouts,
  backup and export settings, #24) belong to phase 35. 1,047 PHP tests and
  133 front-end tests pass. Totals stay 11 / 11 / 2, 6 of 9 checks; 5 of 9
  stages, 13 of 22 phases.
- **2026-11-13** — **Phase 34 built: report filters and exports** (stage 9,
  taken ahead of stages 4, 6 and 8). Reports narrow by Cashier (`posted_by`;
  Staff only to themselves) and Payment method (the sales paid at least in
  part that way and their returns, a split sale counted whole; purchases left
  out). A new Money received block by method. Print / PDF prints the report;
  Spreadsheet downloads an Excel workbook (summary, every sale, return and
  delivery, every breakdown row) written without a package; no cost without
  `sales.view_cost`. #16 ✅ (all three boxes). Totals 11 / 11 / 2; 6 of 9
  checks; 5 of 9 stages, 13 of 22 phases. 1,047 PHP and 133 front-end tests
  pass. No migration, no new permission.
- **2026-11-13** — Checked again (`UPDATE CHECKLIST!`). Nothing built since the last check: phases 28 and 29 were committed, merged into `main` and pushed (`6800e1a`). No box changes. 1,031 PHP tests and 133 front-end tests pass. Totals stay 10 / 12 / 2, 6 of 9 checks; 5 of 9 stages, 12 of 22 phases. Next: stage 4, phase 20, on GO!.
- **2026-11-12** — Checked again after phase 29 (`UPDATE CHECKLIST!`). No box
  changes: every open box was checked against the code and none is met yet;
  the only work since is the till's category tabs, now one row that scrolls
  sideways with a visible bar (`resources/js/Pages/Pos/Index.vue`), which is
  not a scope item. 1,031 PHP tests and 133 front-end tests pass. Totals stay
  10 / 12 / 2, 6 of 9 checks; 5 of 9 stages, 12 of 22 phases. Next: stage 4,
  phase 20, on GO!.
- **2026-11-12** — **Phase 29 built: sales orders with reserved stock** —
  **stage 7 done**. An order is taken pending (holding nothing); approved by
  whoever holds `sales_orders.approve` (Admin; default for decision 2), it
  reserves its lines until a date (7 days by default, a setting), and no
  other sale — form or till — may take that stock; cancelling releases it,
  and past its date it lapses. It becomes a sale on the sale form, taking its
  own reservation; a live quotation can become an order. Reserved and
  Available columns on the Stock screen; the sale form and the till offer
  what is available. #4 ✅ (all four boxes), #12 *Reserved and available
  quantities* ticked. Totals 10 / 12 / 2; 5 of 9 stages, 12 of 22 phases.
  1,031 PHP and 133 front-end tests pass; migration applied locally;
  `permissions:sync` run (new `sales_orders.*`; Staff view, create, cancel).

- **2026-11-12** — **Phase 28 built: quotations**, stage 7 taken ahead of
  stages 4 and 6 on the user's GO! (client's request 2). A quotation offers a
  price until a date (15 days by default, a setting), prints for the
  customer, becomes a sale on the sale form at the quoted prices without
  typing again (stock checked again when the sale posts), is quoted again at
  today's prices once expired, and is cancelled with a reason. Nothing is
  reserved: that is phase 29. Three #4 boxes ticked (#4 ⬜ → 🟡). Totals
  9 / 13 / 2; 11 of 22 phases. 1,015 PHP and 133 front-end tests pass;
  migration applied locally; `permissions:sync` run (new `quotations.*`,
  Staff hold all three).

- **2026-11-12** — **Checked against the code** (`UPDATE CHECKLIST!`, at
  `79caffa`). Nothing built since phase 24. 998 PHP and 133 front-end tests
  pass; `inventory:verify` clean on all four checks. Found one box tagged
  stage 5 still open: #12 *Pending purchase quantities* — no screen shows
  what is still to come on open orders. Left unticked and noted in "Where we
  are" as a follow-up. Totals unchanged (9 / 12 / 3; 6 of 9 checks).

- **2026-11-12** — **Phase 24 built: supplier payables**, on GO! with the five
  defaults: the delivery itself is the bill; every delivery already posted
  counts as paid; a new one is paid on delivery unless put on account;
  Staff hold no supplier payment permission; supplier credit is used only
  against that supplier's later bills. A purchase return comes off an unpaid
  bill first and on a paid bill stays as supplier credit. Payables list with
  aging, the supplier's account on its page, a Payables card, and Net cash
  from trading counts a bill when it is paid. `inventory:verify` checks every
  bill. #19, #20 and #21 ✅ — **stage 5 done**. Totals 9 / 12 / 3; 4 of 9
  stages, 10 of 22 phases. 998 PHP and 133 front-end tests pass; migration
  applied locally (its index names were shortened after MySQL refused the
  generated one); `permissions:sync` run (new `supplier_payments.*`).

- **2026-11-12** — **Phase 23 built: receiving against an order**, on GO! with
  these defaults: a delivery may still post without an order; a line tied to
  the order takes no more than is still to come, and extra goods go on a line
  of their own, shown as not on the order; a cost different from the one
  agreed is allowed and flagged; a void gives the goods back to the order; a
  partly received order is closed short with a reason (cancel stays for one
  with nothing received). Orders now read open, partly received, received,
  closed short or cancelled. `inventory:verify` checks every order line. The
  #19 order-status box and three #20 boxes ticked (#20 stays 🟡 for supplier
  credit, phase 24); acceptance check *Partial goods receiving* ✅ (6 of 9).
  Totals 6 / 14 / 4; 9 of 22 phases. 981 PHP and 133 front-end tests pass;
  migration applied locally; no new permission.

- **2026-11-12** — **Phase 22 built: purchase orders**, on GO! with the five
  defaults: from an approved request or directly; no approval of its own;
  Staff view only; supplier payment terms wait for phase 24; never edited,
  cancelled with a reason. Placing an order from a request closes the
  request as ordered. The order's page prints as the order sent to the
  supplier. #18 🟡 → ✅; one #19 box ticked (#19 stays 🟡 for payment
  terms, and order status waits for phase 23's received statuses). Totals 6 / 14 / 4; 8 of 22 phases. 971 PHP and 133 front-end tests
  pass; migration applied locally; `permissions:sync` run (new
  `purchase_orders.*`).

- **2026-11-12** — **Self-approval made a setting** (option A, on GO!): with
  one approver, the strict rule left Admin's own requests unanswerable.
  Settings → Purchasing → *An approver may approve their own request*, on
  by default; the audit trail says when a request was approved by the person
  who raised it. Off restores the strict rule. No migration, no permission.

- **2026-11-12** — **Phase 21 built: purchase requests with approval**,
  stage 5 taken ahead of stage 4 on the user's GO! (client's request 1).
  Staff raise a request — why, lines in any unit, an estimated cost, an
  optional supplier and date — and somebody holding
  `purchase_requests.approve` who did not raise it approves or rejects it; a
  request nobody needs is closed with a reason. Decision 2 answered by
  default for purchase requests only. #18 ⬜ → 🟡 (two of three boxes; the
  order is phase 22), the Purchase requests box on #1 ticked. Totals 5 / 15
  / 4; 7 of 22 phases. 954 PHP and 133 front-end tests pass; migration
  applied locally; `permissions:sync` run (new `purchase_requests.*`).

- **2026-11-12** — **Phase 19.1 built: Collectibles**, on the client's request
  of 12 November (request 5: show collectibles, filter by month, customer).
  Every invoice still owed, filtered by month due or sold, customer, status
  and staff, with totals that equal Aging and the Overdue card. A box added
  and ticked on #17, which stays ✅. Totals unchanged. The client's other four
  requests map onto the plan: purchase request → order (phases 21–22),
  quotations (28), supplier payables (24), credit sales (18, done). 931 PHP
  and 133 front-end tests pass; no migration, no new permission.

- **2026-11-12** — **Phase 19 built: customer payments against invoices,
  statement of account, aging, overdue and over-limit flags, the Overdue
  card. Stage 3 done.** Built on GO! with these defaults: one method per
  payment; the invoices must add up to the payment exactly, so nothing is
  kept as customer credit (stage 6); the form spreads it soonest due first;
  a sale with a payment against it cannot be voided until the payment is;
  overdue is a warning at the till, not a refusal; Staff may record a
  payment, voiding stays with Admin; never called a receipt. Collections and
  Net cash from trading now count what customers paid, on the day it came
  in. `inventory:verify` also checks every sale's balance. #17 now ✅; the
  Overdue box on #1 ticked; acceptance check "A credit sale followed by a
  partial collection" now ✅. Totals 5 done, 14 partly, 5 to do; 5 of 9
  acceptance checks; 3 of 9 stages and 6 of 22 phases. 925 PHP and 133
  front-end tests pass; migration applied locally, `permissions:sync` run
  (`customer_payments.*` are new), `inventory:verify` clean.
- **2026-11-11** — Outside the scope, no box changed: presentation sample
  data for 2 January to that day (`PresentationDataSeeder`), and the login
  and kiosk sign-in pages redesigned as one split screen with the new round
  logo.

- **2026-11-10** — **Phase 18 built: credit limit, terms, a sale on account,
  customer balance. Stage 3 half done.** All or part of a sale goes on a
  customer's account, on the till and the New sale form, with the due date
  stamped from their terms. Built on GO! with these defaults: zero limit
  means no credit; over the limit is refused with no override (decision 2
  still waiting, so #3's approval is now its own open box, stage 6); no change
  on a sale on account; a return on an unpaid sale comes off the balance
  first; `customers.credit` sets the limit, and anybody who may sell may
  charge within it. Collections leaves out what is unpaid and Net cash from
  trading was corrected to match. Boxes ticked on #3 and #17; both stay 🟡.
  Acceptance check "A credit sale followed by a partial collection" now 🟡.
  886 PHP and 122 front-end tests pass; migration applied locally,
  `permissions:sync` run, `inventory:verify` clean.
- **2026-11-10** — `UPDATE CHECKLIST!`. No code changed since the last check
  (HEAD still `0a28d01`). Every open box checked and still absent: no credit
  limit, terms or balance, shift, purchase request or order, payable, price
  list, approval, quotation, reservation, barcode, brand, location, transfer
  or count sheet in the code, and Reports filters by date only, with no
  export. Totals recounted and unchanged: 4 done, 15 partly, 5 to do; 4 of 9
  acceptance checks; 2 of 9 stages and 4 of 22 phases. 842 PHP and 102
  front-end tests pass; `inventory:verify` clean. Phase 18 waits on GO!.
- **2026-11-10** — `UPDATE CHECKLIST!` after phase 17 was merged and pushed
  (`0a28d01`). One change since: a sale under Recent sales on a customer's
  page now opens in the sale dialog, not a new page; it ticks no box. No
  stage 3 code yet (no credit limit, terms or balance). Totals, acceptance
  checks and stages unchanged: stages 1 and 2 done, phase 18 next. 842 PHP
  and 102 front-end tests pass; `inventory:verify` clean.
- **2026-11-09** — **Phase 17 built: hold, customer and slip. Stage 2
  done.** The till holds a sale under a new Held tab (shared by every till,
  at most 50; nothing reserved, no stock moved) and recalls it against
  today's shelf and prices, saying what changed; a customer can be picked
  at the till (Walk-in by default, still paid in full); and a slip prints
  from the till, the sale dialog and the sale page, 80 or 58 mm, without
  cost, marked "not an official receipt". The three remaining stage 2
  boxes on #2 ticked; #2 stays 🟡 for price lists (stage 6). Totals and
  acceptance checks unchanged. Stage 3 next. 842 PHP and 102 front-end
  tests pass; migration applied locally, `inventory:verify` clean; no new
  permission.
- **2026-11-09** — `UPDATE CHECKLIST!` after phase 16 was merged and pushed
  (`00145fd`). No code changed since. Phase 17 items spot-checked and still
  absent (no hold, recall or slip route; the till sells to Walk-in only).
  821 PHP and 86 front-end tests pass; `inventory:verify` is clean. Totals
  unchanged: stage 2 half done, phase 17 waits on GO!.
- **2026-11-09** — **Phase 16 built: payments at the till.** A Payment
  Methods screen (Cash, GCash, Maya, Bank transfer; one protected cash
  method); every sale records how it was paid, on the till's new payment
  dialog and the New sale form's Payment card. Cash handed over gives
  change, split payments work, GCash, Maya and Bank need their reference
  number. Collections on the dashboard and the till's day now come from the
  payments, split by method. Existing sales got one Cash row each. Boxes
  ticked on #2, #3 (now 🟡) and #24; acceptance check "Correct cash and
  noncash totals" passes. Stage 2 in progress, phase 17 next. 821 PHP and
  86 front-end tests pass; migration applied locally, `permissions:sync`
  run, `inventory:verify` clean.

- **2026-11-09** — The work plan now lists the 22 phases (14 to 35) under
  the 9 stages, as proposed on 2026-11-08; "Where we are" names the current
  phase. Phases 14 and 15 marked done; phase 16 is next. The note about the
  client PDF corrected — the file is no longer on the Desktop.
- **2026-11-09** — `UPDATE CHECKLIST!`. No code changed since phase 15;
  open items spot-checked and still absent (no barcode, payment method,
  credit limit, purchase order, location, quotation or shift in the code).
  777 PHP and 68 front-end tests pass; `inventory:verify` is clean. Totals
  unchanged: stage 1 done, stage 2 waits on GO!.
- **2026-11-08** — **Phase 15 built: unit conversions. Stage 1 done.** A
  product lists other units (box = 100 pcs) with an optional own price;
  sales, purchases and the till pick the unit per line, and the shelf moves
  in the product's own unit. A box of 12 bought at 100 puts pieces in at
  8.33, and a return of the whole box gives back exactly 100.00. Boxes
  ticked on #10 (now ✅) and #2; acceptance check "A sale using a unit
  conversion" passes. 777 PHP and 68 front-end tests pass; migration applied
  locally and `inventory:verify` is clean.

- **2026-11-08** — **Phase 14 built: decimal quantities.** Units carry an
  "Allows decimals" switch; every quantity column is DECIMAL(15,3) and is
  worked in whole thousandths (`App\Support\Quantity`), so on hand still
  equals its batches exactly. The sale, purchase, adjustment and both return
  forms, the till, stock, movements, reports, dashboard and API take and show
  decimals; whole numbers still read 10. Box ticked on #10 (still 🟡 for
  conversions). Stage 1 in progress. 757 PHP and 63 front-end tests pass;
  migration applied locally and `inventory:verify` is clean.

- **2026-11-08** — `UPDATE CHECKLIST!`. No code changed since the last
  check; open items still absent. 735 PHP and 54 front-end tests pass.
  Totals unchanged. A split of the 9 stages into phases 14–35 was proposed
  and is waiting for GO!.
- **2026-11-08** — First `UPDATE CHECKLIST!`. No code changed since the
  checklist was written; open items spot-checked and still absent (no
  barcode, payment method, credit limit, purchase order, location or
  quotation in the code). 735 PHP and 54 front-end tests pass. Totals
  unchanged.
- **2026-11-08** — Checklist created from the proposal of October 21, 2026.
  3 done, 15 partly, 6 to do; 2 of 9 acceptance checks pass. Already in
  place before it: the owner's dashboard revision, the hardware starter
  data (units, categories, 108 products, photos, sample stock) and 10
  sample suppliers.
