<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Stateless, token authenticated, and versioned in the path so a later
| version can live beside this one.
|
| Every reply has the same shape, success or failure. See
| App\Support\ApiResponse and the exception handling in bootstrap/app.php.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    | Open routes. The login throttle is tight, and ApiLoginRequest holds a
    | second limit per username and IP address.
    */
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    /*
    | Everything below needs a token.
    */
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');

        /*
        | Users. The middleware is the outer gate; the policy checks the row
        | again inside the controller, exactly as the web module does.
        */
        Route::middleware('permission:users.view')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        });

        /*
        | Roles.
        */
        Route::middleware('permission:roles.view')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
        });

        /*
        | The master files.
        */
        Route::middleware('permission:products.view')->group(function () {
            Route::get('products', [CatalogController::class, 'products'])->name('products.index');
            Route::get('products/{product}', [CatalogController::class, 'product'])->name('products.show');
        });

        Route::middleware('permission:categories.view')
            ->get('categories', [CatalogController::class, 'categories'])->name('categories.index');

        Route::middleware('permission:units.view')
            ->get('units', [CatalogController::class, 'units'])->name('units.index');

        Route::middleware('permission:suppliers.view')->group(function () {
            Route::get('suppliers', [CatalogController::class, 'suppliers'])->name('suppliers.index');
            Route::get('suppliers/{supplier}', [CatalogController::class, 'supplier'])->name('suppliers.show');
        });

        Route::middleware('permission:customers.view')->group(function () {
            Route::get('customers', [CatalogController::class, 'customers'])->name('customers.index');
            Route::get('customers/{customer}', [CatalogController::class, 'customer'])->name('customers.show');
        });

        /*
        | The documents. Reading only: a document moves stock the moment it
        | is saved, so posting one through the API deserves the idempotency
        | key and the confirmation the web form already has. That is a
        | follow-up, not an afterthought.
        */
        Route::middleware('permission:purchases.view')->group(function () {
            Route::get('purchases', [DocumentController::class, 'purchases'])->name('purchases.index');
            Route::get('purchases/{purchase}', [DocumentController::class, 'purchase'])->name('purchases.show');
        });

        Route::middleware('permission:sales.view')->group(function () {
            Route::get('sales', [DocumentController::class, 'sales'])->name('sales.index');
            Route::get('sales/{sale}', [DocumentController::class, 'sale'])->name('sales.show');
        });

        Route::middleware('permission:sales_returns.view')->group(function () {
            Route::get('sales-returns', [DocumentController::class, 'salesReturns'])->name('sales-returns.index');
            Route::get('sales-returns/{salesReturn}', [DocumentController::class, 'salesReturn'])->name('sales-returns.show');
        });

        Route::middleware('permission:purchase_returns.view')->group(function () {
            Route::get('purchase-returns', [DocumentController::class, 'purchaseReturns'])->name('purchase-returns.index');
            Route::get('purchase-returns/{purchaseReturn}', [DocumentController::class, 'purchaseReturn'])->name('purchase-returns.show');
        });

        Route::middleware('permission:adjustments.view')->group(function () {
            Route::get('adjustments', [DocumentController::class, 'adjustments'])->name('adjustments.index');
            Route::get('adjustments/{adjustment}', [DocumentController::class, 'adjustment'])->name('adjustments.show');
        });

        /*
        | The warehouse and the figures. These read the same services the
        | web screens read, so a phone and a browser can never be told two
        | different things about the same stock.
        */
        Route::middleware('permission:movements.view')
            ->get('movements', [InventoryController::class, 'movements'])->name('movements.index');

        Route::middleware('permission:stock.view')
            ->get('stock', [InventoryController::class, 'stock'])->name('stock.index');

        Route::middleware('permission:reports.view')
            ->get('dashboard', [InventoryController::class, 'dashboard'])->name('dashboard');
    });
});

/*
| Anything else under /api is a dead end, answered in the same JSON shape
| rather than with an HTML page.
*/
Route::fallback(fn () => ApiResponse::error('This endpoint does not exist.', 404));
