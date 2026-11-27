<?php

use App\Http\Controllers\Account\AppearanceController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\LossReportController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CanvassController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\PayablesController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PendingPaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// GET only: Route::redirect would otherwise answer POST, PUT and DELETE
// on the root as well.
Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // The same sign-in, laid out for a tablet. It posts to the route above,
    // so there is no second way into the system — only a second screen.
    Route::get('kiosk', [AuthenticatedSessionController::class, 'kiosk'])->name('kiosk');
});

Route::middleware('auth')->group(function () {
    // The till on the shared tablet. Behind the same permission that
    // decides who may sign in there, and it sells through POST /sales
    // like every other screen.
    Route::get('pos', [PosController::class, 'index'])
        ->middleware('permission:kiosk.use')
        ->name('pos');

    // Hold and recall at the till. No screen of their own: the holds are
    // listed on the till. Nothing here moves stock — a recalled sale is
    // posted through POST /sales like any other.
    Route::middleware('permission:kiosk.use')->group(function () {
        Route::post('pos/holds', [HeldSaleController::class, 'store'])->name('pos.holds.store');
        Route::post('pos/holds/{held}/recall', [HeldSaleController::class, 'recall'])
            ->whereNumber('held')
            ->name('pos.holds.recall');
        Route::delete('pos/holds/{held}', [HeldSaleController::class, 'destroy'])
            ->whereNumber('held')
            ->name('pos.holds.destroy');
    });

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('dashboard/refresh', [DashboardController::class, 'refresh'])->name('dashboard.refresh');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // A person's own light or dark screen (phase 35.1). No permission: it
    // changes only how the app looks to the one asking.
    Route::put('appearance', [AppearanceController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('appearance.update');

    /*
    | User management. The middleware is the first gate; the policies check
    | each row again inside the controller.
    */
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])
        ->middleware('permission:users.status')
        ->name('users.status');

    Route::resource('users', UserController::class)
        ->middlewareFor('index', 'permission:users.view')
        ->middlewareFor('show', 'permission:users.view')
        ->middlewareFor(['create', 'store'], 'permission:users.create')
        ->middlewareFor(['edit', 'update'], 'permission:users.update')
        ->middlewareFor('destroy', 'permission:users.delete');

    /*
    | Documents. A posted document is never edited or deleted, so there is
    | no update and no destroy route anywhere below — not hidden behind a
    | permission, simply absent. A mistake is corrected with a void while the
    | stock is untouched, or with a return once it has moved.
    */
    /*
    | Purchase requests (phase 21). Not a document that posts: it is raised,
    | then approved or rejected by somebody else who may approve, or closed
    | when nobody needs it. No edit and no delete route, like the documents.
    | Closing is open to whoever raised it as well, so that route accepts
    | either permission; the policy decides which request each may close.
    */
    Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])
        ->middleware('permission:purchase_requests.approve')
        ->name('purchase-requests.approve');

    Route::post('purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])
        ->middleware('permission:purchase_requests.approve')
        ->name('purchase-requests.reject');

    Route::post('purchase-requests/{purchaseRequest}/close', [PurchaseRequestController::class, 'close'])
        ->middleware('permission:purchase_requests.create|purchase_requests.approve')
        ->name('purchase-requests.close');

    // The canvass (phase 24.2): suppliers' prices against an approved
    // request, and the award. The Abstract is part of the request's page.
    Route::get('purchase-requests/{purchaseRequest}/quotes/create', [CanvassController::class, 'create'])
        ->middleware('permission:canvass.create')
        ->name('purchase-requests.quotes.create');
    Route::post('purchase-requests/{purchaseRequest}/quotes', [CanvassController::class, 'store'])
        ->middleware('permission:canvass.create')
        ->name('purchase-requests.quotes.store');
    Route::delete('purchase-requests/{purchaseRequest}/quotes/{quote}', [CanvassController::class, 'destroy'])
        ->middleware('permission:canvass.create')
        ->name('purchase-requests.quotes.destroy');
    Route::post('purchase-requests/{purchaseRequest}/award', [CanvassController::class, 'award'])
        ->middleware('permission:canvass.award')
        ->name('purchase-requests.award');
    // Phase 24.3: one order per winning supplier, at the quoted prices.
    Route::post('purchase-requests/{purchaseRequest}/award/order', [CanvassController::class, 'order'])
        ->middleware('permission:purchase_orders.create')
        ->name('purchase-requests.award.order');
    Route::post('purchase-requests/{purchaseRequest}/award/reopen', [CanvassController::class, 'reopen'])
        ->middleware('permission:canvass.award')
        ->name('purchase-requests.award.reopen');

    Route::get('purchase-requests/{purchaseRequest}/preview', [PurchaseRequestController::class, 'preview'])
        ->middleware('permission:purchase_requests.view')
        ->name('purchase-requests.preview');

    Route::resource('purchase-requests', PurchaseRequestController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['purchase-requests' => 'purchaseRequest'])
        ->middlewareFor(['index', 'show'], 'permission:purchase_requests.view')
        ->middlewareFor(['create', 'store'], 'permission:purchase_requests.create');

    /*
    | Purchase orders (phase 22). Placed with a supplier, from an approved
    | request or directly; no stock moves. Never edited or deleted: an order
    | is cancelled with a reason. Approved or rejected before it is sent
    | (phase 24.4).
    */
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('permission:purchase_orders.approve')
        ->name('purchase-orders.approve');

    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])
        ->middleware('permission:purchase_orders.approve')
        ->name('purchase-orders.reject');

    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:purchase_orders.cancel')
        ->name('purchase-orders.cancel');

    // A partly received order whose rest is not coming (phase 23).
    Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
        ->middleware('permission:purchase_orders.cancel')
        ->name('purchase-orders.close');

    Route::get('purchase-orders/{purchaseOrder}/preview', [PurchaseOrderController::class, 'preview'])
        ->middleware('permission:purchase_orders.view')
        ->name('purchase-orders.preview');

    Route::resource('purchase-orders', PurchaseOrderController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['purchase-orders' => 'purchaseOrder'])
        ->middlewareFor(['index', 'show'], 'permission:purchase_orders.view')
        ->middlewareFor(['create', 'store'], 'permission:purchase_orders.create');

    /*
    | Money paid to suppliers against their bills, and what is still owed
    | (phase 24). Like a customer payment: no edit and no delete route, and
    | a void that says why. Payables only reads, behind the same view.
    */
    Route::post('supplier-payments/{supplierPayment}/void', [SupplierPaymentController::class, 'void'])
        ->middleware('permission:supplier_payments.void')
        ->name('supplier-payments.void');

    Route::get('supplier-payments/{supplierPayment}/preview', [SupplierPaymentController::class, 'preview'])
        ->middleware('permission:supplier_payments.view')
        ->name('supplier-payments.preview');

    Route::resource('supplier-payments', SupplierPaymentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['supplier-payments' => 'supplierPayment'])
        ->middlewareFor(['index', 'show'], 'permission:supplier_payments.view')
        ->middlewareFor(['create', 'store'], 'permission:supplier_payments.create');

    Route::get('payables', [PayablesController::class, 'index'])
        ->middleware('permission:supplier_payments.view')
        ->name('payables.index');
    // What was paid to suppliers in a period, by supplier and by method
    // (phase 24.5, CR-5): the Paid side of Payables.
    Route::get('payables/paid', [PayablesController::class, 'paid'])
        ->middleware('permission:supplier_payments.view')
        ->name('payables.paid');

    Route::post('purchases/{purchase}/void', [PurchaseController::class, 'void'])
        ->middleware('permission:purchases.void')
        ->name('purchases.void');

    // The dialog on the list screen reads the same payload the page does.
    Route::get('purchases/{purchase}/preview', [PurchaseController::class, 'preview'])
        ->middleware('permission:purchases.view')
        ->name('purchases.preview');

    Route::resource('purchases', PurchaseController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:purchases.view')
        ->middlewareFor(['create', 'store'], 'permission:purchases.create');

    /*
    | Quotations (phase 28). A price offered until a date; nothing moves or
    | is reserved. No edit and no delete route: cancelled with a reason, or
    | accepted when the sale form posts it (`/sales/create?quotation=`).
    */
    Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])
        ->middleware('permission:quotations.cancel')
        ->name('quotations.cancel');

    Route::get('quotations/{quotation}/preview', [QuotationController::class, 'preview'])
        ->middleware('permission:quotations.view')
        ->name('quotations.preview');

    Route::resource('quotations', QuotationController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:quotations.view')
        ->middlewareFor(['create', 'store'], 'permission:quotations.create');

    /*
    | Sales orders (phase 29). Taken pending; approved, they reserve their
    | stock until a date; cancelled, they release it; fulfilled on the sale
    | form (`/sales/create?order=`). No edit and no delete route.
    */
    Route::post('sales-orders/{salesOrder}/approve', [SalesOrderController::class, 'approve'])
        ->middleware('permission:sales_orders.approve')
        ->name('sales-orders.approve');

    Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])
        ->middleware('permission:sales_orders.cancel')
        ->name('sales-orders.cancel');

    Route::get('sales-orders/{salesOrder}/preview', [SalesOrderController::class, 'preview'])
        ->middleware('permission:sales_orders.view')
        ->name('sales-orders.preview');

    Route::resource('sales-orders', SalesOrderController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['sales-orders' => 'salesOrder'])
        ->middlewareFor(['index', 'show'], 'permission:sales_orders.view')
        ->middlewareFor(['create', 'store'], 'permission:sales_orders.create');

    /*
    | Pending payment (CR-2): the kiosk takes the order, the cashier takes the
    | money. Sent from the till, listed here and on the till's Pending tab,
    | paid as a sale through POST /sales, or cancelled. Anyone at the kiosk
    | may send one; the sender cancels their own, and anyone who may record a
    | sale cancels any (PendingPaymentPolicy).
    */
    Route::post('pending-payments', [PendingPaymentController::class, 'store'])
        ->middleware('permission:kiosk.use')
        ->name('pending-payments.store');

    Route::post('pending-payments/{pendingPayment}/cancel', [PendingPaymentController::class, 'cancel'])
        ->middleware('permission:kiosk.use|sales.create')
        ->name('pending-payments.cancel');

    Route::get('pending-payments/{pendingPayment}/preview', [PendingPaymentController::class, 'preview'])
        ->middleware('permission:pending_payments.view|kiosk.use|sales.create')
        ->name('pending-payments.preview');

    Route::get('pending-payments', [PendingPaymentController::class, 'index'])
        ->middleware('permission:pending_payments.view')
        ->name('pending-payments.index');

    Route::get('pending-payments/{pendingPayment}', [PendingPaymentController::class, 'show'])
        ->middleware('permission:pending_payments.view|kiosk.use|sales.create')
        ->name('pending-payments.show');

    Route::post('sales/{sale}/void', [SaleController::class, 'void'])
        ->middleware('permission:sales.void')
        ->name('sales.void');

    Route::get('sales/{sale}/preview', [SaleController::class, 'preview'])
        ->middleware('permission:sales.view')
        ->name('sales.preview');

    Route::resource('sales', SaleController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:sales.view')
        ->middlewareFor(['create', 'store'], 'permission:sales.create');

    /*
    | The warehouse, read-only. The ledger is append-only, so neither of
    | these has a write route — the same shape as the audit trail.
    */
    Route::get('movements', [MovementController::class, 'index'])
        ->middleware('permission:movements.view')
        ->name('movements.index');

    Route::get('stock', [StockController::class, 'index'])
        ->middleware('permission:stock.view')
        ->name('stock.index');

    // Read-only, and nothing is stored: a report is a question asked of
    // the documents, answered fresh each time.
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('reports.index');

    // The same report as a spreadsheet (phase 34). A GET that writes
    // nothing but an audit line: the file is made, sent and deleted.
    Route::get('reports/export', [ReportController::class, 'export'])
        ->middleware('permission:reports.view')
        ->name('reports.export');

    /*
    | Returns. A return is never voided, edited or deleted: undoing an undo
    | leaves a history nobody can follow. One entered wrongly is corrected
    | with another return or with an adjustment.
    */
    Route::get('sales-returns/{salesReturn}/preview', [SalesReturnController::class, 'preview'])
        ->middleware('permission:sales_returns.view')
        ->name('sales-returns.preview');

    Route::resource('sales-returns', SalesReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['sales-returns' => 'salesReturn'])
        ->middlewareFor(['index', 'show'], 'permission:sales_returns.view')
        ->middlewareFor(['create', 'store'], 'permission:sales_returns.create');

    /*
    | Money a customer brings in against what they owe (phase 19). Like a
    | sale: no edit and no delete route, and a void that says why. The
    | statement and the aging report only read, behind the same view
    | permission.
    */
    Route::post('customer-payments/{customerPayment}/void', [CustomerPaymentController::class, 'void'])
        ->middleware('permission:customer_payments.void')
        ->name('customer-payments.void');

    Route::get('customer-payments/{customerPayment}/preview', [CustomerPaymentController::class, 'preview'])
        ->middleware('permission:customer_payments.view')
        ->name('customer-payments.preview');

    Route::resource('customer-payments', CustomerPaymentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['customer-payments' => 'customerPayment'])
        ->middlewareFor(['index', 'show'], 'permission:customer_payments.view')
        ->middlewareFor(['create', 'store'], 'permission:customer_payments.create');

    Route::get('customers/{customer}/statement', [ReceivablesController::class, 'statement'])
        ->middleware('permission:customer_payments.view')
        ->name('customers.statement');

    Route::get('aging', [ReceivablesController::class, 'aging'])
        ->middleware('permission:customer_payments.view')
        ->name('aging.index');

    Route::get('collectibles', [ReceivablesController::class, 'collectibles'])
        ->middleware('permission:customer_payments.view')
        ->name('collectibles.index');

    Route::get('purchase-returns/{purchaseReturn}/preview', [PurchaseReturnController::class, 'preview'])
        ->middleware('permission:purchase_returns.view')
        ->name('purchase-returns.preview');

    Route::resource('purchase-returns', PurchaseReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['purchase-returns' => 'purchaseReturn'])
        ->middlewareFor(['index', 'show'], 'permission:purchase_returns.view')
        ->middlewareFor(['create', 'store'], 'permission:purchase_returns.create');

    /*
    | Stock corrections: a breakage, a recount, and the opening count a
    | business starts with.
    */
    Route::post('adjustments/{adjustment}/void', [AdjustmentController::class, 'void'])
        ->middleware('permission:adjustments.void')
        ->name('adjustments.void');

    Route::get('adjustments/{adjustment}/preview', [AdjustmentController::class, 'preview'])
        ->middleware('permission:adjustments.view')
        ->name('adjustments.preview');

    Route::resource('adjustments', AdjustmentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:adjustments.view')
        ->middlewareFor(['create', 'store'], 'permission:adjustments.create');

    /*
    | Stock moved between locations (phase 31.5). Shaped like adjustments:
    | no edit and no delete, a void while what it brought in is untouched.
    */
    Route::post('transfers/{transfer}/void', [StockTransferController::class, 'void'])
        ->middleware('permission:transfers.void')
        ->name('transfers.void');

    Route::get('transfers/{transfer}/preview', [StockTransferController::class, 'preview'])
        ->middleware('permission:transfers.view')
        ->name('transfers.preview');

    Route::resource('transfers', StockTransferController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:transfers.view')
        ->middlewareFor(['create', 'store'], 'permission:transfers.create');

    /*
    | The count sheet (phase 32). Not a posted document: figures are entered
    | while counting, then it is approved — posting the differences as
    | adjustments — sent back, or cancelled. No edit and no delete route.
    */
    Route::put('stock-counts/{stock_count}/counts', [StockCountController::class, 'record'])
        ->middleware('permission:stock_counts.create')
        ->name('stock-counts.record');

    Route::post('stock-counts/{stock_count}/submit', [StockCountController::class, 'submit'])
        ->middleware('permission:stock_counts.create')
        ->name('stock-counts.submit');

    Route::post('stock-counts/{stock_count}/approve', [StockCountController::class, 'approve'])
        ->middleware('permission:stock_counts.approve')
        ->name('stock-counts.approve');

    Route::post('stock-counts/{stock_count}/send-back', [StockCountController::class, 'sendBack'])
        ->middleware('permission:stock_counts.approve')
        ->name('stock-counts.send-back');

    // A counter may cancel the count they started; the policy decides.
    Route::post('stock-counts/{stock_count}/cancel', [StockCountController::class, 'cancel'])
        ->middleware('permission:stock_counts.create|stock_counts.approve')
        ->name('stock-counts.cancel');

    Route::get('stock-counts/{stock_count}/preview', [StockCountController::class, 'preview'])
        ->middleware('permission:stock_counts.view')
        ->name('stock-counts.preview');

    Route::resource('stock-counts', StockCountController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:stock_counts.view')
        ->middlewareFor(['create', 'store'], 'permission:stock_counts.create');

    /*
    | Loss reports (phase 33). Not a posted document: reported, then
    | approved — writing it off as a decrease adjustment — rejected, or
    | withdrawn. No edit and no delete route. The photos are served only
    | through the report, to somebody who may read it.
    */
    Route::post('loss-reports/{loss_report}/approve', [LossReportController::class, 'approve'])
        ->middleware('permission:losses.approve')
        ->name('loss-reports.approve');

    Route::post('loss-reports/{loss_report}/reject', [LossReportController::class, 'reject'])
        ->middleware('permission:losses.approve')
        ->name('loss-reports.reject');

    // The reporter may withdraw their own; the policy decides.
    Route::post('loss-reports/{loss_report}/cancel', [LossReportController::class, 'cancel'])
        ->middleware('permission:losses.create|losses.approve')
        ->name('loss-reports.cancel');

    Route::get('loss-reports/{loss_report}/preview', [LossReportController::class, 'preview'])
        ->middleware('permission:losses.view')
        ->name('loss-reports.preview');

    Route::get('loss-reports/{loss_report}/photos/{photo}', [LossReportController::class, 'photo'])
        ->middleware('permission:losses.view')
        ->name('loss-reports.photo');

    Route::resource('loss-reports', LossReportController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:losses.view')
        ->middlewareFor(['create', 'store'], 'permission:losses.create');

    /*
    | The master files. Every list screen is the same shape: the middleware
    | is the outer gate and the policy checks each row inside.
    |
    | None of them has a create or edit page. Both forms open in a dialog on
    | the list itself, so `store` and `update` are the only write routes and
    | the list keeps its page, sort and filters. See README section 4, step 6.
    |
    | Status is its own route in every one of them, so switching a record off
    | is one click and reads as Activate or Deactivate in the trail.
    */
    Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])
        ->middleware('permission:products.status')
        ->name('products.status');

    Route::get('products/{product}/preview', [ProductController::class, 'preview'])
        ->middleware('permission:products.view')
        ->name('products.preview');

    Route::resource('products', ProductController::class)
        ->except(['create', 'edit'])
        ->middlewareFor(['index', 'show'], 'permission:products.view')
        ->middlewareFor('store', 'permission:products.create')
        ->middlewareFor('update', 'permission:products.update')
        ->middlewareFor('destroy', 'permission:products.delete');

    Route::patch('categories/{category}/status', [CategoryController::class, 'updateStatus'])
        ->middleware('permission:categories.status')
        ->name('categories.status');

    // No detail screen: the table already shows everything a category holds.
    Route::resource('categories', CategoryController::class)
        ->except(['show', 'create', 'edit'])
        ->middlewareFor('index', 'permission:categories.view')
        ->middlewareFor('store', 'permission:categories.create')
        ->middlewareFor('update', 'permission:categories.update')
        ->middlewareFor('destroy', 'permission:categories.delete');

    // Departments (phase 24.1): who a purchase request is raised for. A
    // master list shaped like Categories, with no detail screen.
    Route::patch('departments/{department}/status', [DepartmentController::class, 'updateStatus'])
        ->middleware('permission:departments.status')
        ->name('departments.status');

    Route::resource('departments', DepartmentController::class)
        ->except(['show', 'create', 'edit'])
        ->middlewareFor('index', 'permission:departments.view')
        ->middlewareFor('store', 'permission:departments.create')
        ->middlewareFor('update', 'permission:departments.update')
        ->middlewareFor('destroy', 'permission:departments.delete');

    // Locations (phase 31.1): the store and the warehouse. A master list
    // shaped like Departments, with no detail screen.
    Route::patch('locations/{location}/status', [LocationController::class, 'updateStatus'])
        ->middleware('permission:locations.status')
        ->name('locations.status');

    // Phase 31.2: the location every document posts at until it names one.
    Route::patch('locations/{location}/default', [LocationController::class, 'makeDefault'])
        ->middleware('permission:locations.update')
        ->name('locations.default');

    Route::resource('locations', LocationController::class)
        ->except(['show', 'create', 'edit'])
        ->middlewareFor('index', 'permission:locations.view')
        ->middlewareFor('store', 'permission:locations.create')
        ->middlewareFor('update', 'permission:locations.update')
        ->middlewareFor('destroy', 'permission:locations.delete');

    Route::patch('units/{unit}/status', [UnitController::class, 'updateStatus'])
        ->middleware('permission:units.status')
        ->name('units.status');

    Route::resource('units', UnitController::class)
        ->except(['show', 'create', 'edit'])
        ->middlewareFor('index', 'permission:units.view')
        ->middlewareFor('store', 'permission:units.create')
        ->middlewareFor('update', 'permission:units.update')
        ->middlewareFor('destroy', 'permission:units.delete');

    Route::patch('payment-methods/{payment_method}/status', [PaymentMethodController::class, 'updateStatus'])
        ->middleware('permission:payment_methods.status')
        ->name('payment-methods.status');

    // No detail screen: the table already shows everything a method holds.
    Route::resource('payment-methods', PaymentMethodController::class)
        ->parameters(['payment-methods' => 'payment_method'])
        ->except(['show', 'create', 'edit'])
        ->middlewareFor('index', 'permission:payment_methods.view')
        ->middlewareFor('store', 'permission:payment_methods.create')
        ->middlewareFor('update', 'permission:payment_methods.update')
        ->middlewareFor('destroy', 'permission:payment_methods.delete');

    Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'updateStatus'])
        ->middleware('permission:suppliers.status')
        ->name('suppliers.status');

    // An ordinary edit of one field, so it reuses `suppliers.update` rather
    // than inventing a permission nobody has been granted yet.
    Route::patch('suppliers/{supplier}/vat', [SupplierController::class, 'updateVat'])
        ->middleware('permission:suppliers.update')
        ->name('suppliers.vat');

    // The supplier in a dialog on the list (24 November 2026).
    Route::get('suppliers/{supplier}/preview', [SupplierController::class, 'preview'])
        ->middleware('permission:suppliers.view')
        ->name('suppliers.preview');

    Route::resource('suppliers', SupplierController::class)
        ->except(['create', 'edit'])
        ->middlewareFor(['index', 'show'], 'permission:suppliers.view')
        ->middlewareFor('store', 'permission:suppliers.create')
        ->middlewareFor('update', 'permission:suppliers.update')
        ->middlewareFor('destroy', 'permission:suppliers.delete');

    Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])
        ->middleware('permission:customers.status')
        ->name('customers.status');

    // The customer in a dialog on the list (24 November 2026).
    Route::get('customers/{customer}/preview', [CustomerController::class, 'preview'])
        ->middleware('permission:customers.view')
        ->name('customers.preview');

    Route::resource('customers', CustomerController::class)
        ->except(['create', 'edit'])
        ->middlewareFor(['index', 'show'], 'permission:customers.view')
        ->middlewareFor('store', 'permission:customers.create')
        ->middlewareFor('update', 'permission:customers.update')
        ->middlewareFor('destroy', 'permission:customers.delete');

    /*
    | Settings. Every group is served by the same two routes, built from
    | config/settings.php.
    */
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::get('settings/{group}', [SettingsController::class, 'show'])->name('settings.show');
    });

    Route::middleware('permission:settings.update')->group(function () {
        Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
        Route::delete('settings/{group}', [SettingsController::class, 'reset'])->name('settings.reset');

        // A picture field, such as the company logo. Saved on upload, not
        // with the Save button. POST, because PHP reads no file on a PUT.
        Route::post('settings/{group}/images/{field}', [SettingsController::class, 'uploadImage'])
            ->name('settings.images.store');
        Route::delete('settings/{group}/images/{field}', [SettingsController::class, 'removeImage'])
            ->name('settings.images.destroy');
    });

    /*
    | Backups (phase 35). A backup is the whole books and every password
    | hash, so each route names its own permission (Admin only), and a
    | backup's name must match BackupMaker::NAME — `../.env` never routes.
    | Restoring is done by hand, never from here.
    */
    Route::get('backups', [BackupController::class, 'index'])
        ->middleware('permission:backups.view')
        ->name('backups.index');
    Route::post('backups', [BackupController::class, 'store'])
        ->middleware(['permission:backups.create', 'throttle:6,1'])
        ->name('backups.store');
    Route::get('backups/{name}/download', [BackupController::class, 'download'])
        ->where('name', 'backup-[0-9-]+\.zip')
        ->middleware('permission:backups.download')
        ->name('backups.download');
    Route::delete('backups/{name}', [BackupController::class, 'destroy'])
        ->where('name', 'backup-[0-9-]+\.zip')
        ->middleware('permission:backups.delete')
        ->name('backups.destroy');

    /*
    | The audit trail. One read-only route, on purpose.
    */
    Route::get('audit', [AuditTrailController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit.index');

    /*
    | Role and permission management.
    */
    Route::resource('roles', RoleController::class)
        ->middlewareFor('index', 'permission:roles.view')
        ->middlewareFor('show', 'permission:roles.view')
        ->middlewareFor(['create', 'store'], 'permission:roles.create')
        ->middlewareFor(['edit', 'update'], 'permission:roles.update')
        ->middlewareFor('destroy', 'permission:roles.delete');
});
