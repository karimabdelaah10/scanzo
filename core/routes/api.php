<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CatalogueImportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\VariantSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Everything a shop touches is here, the dashboard included, so that any of it
| can work from a local copy later — see docs/decisions.md, decision 14. Only
| the platform panel, which we use to manage subscribers, is server-driven and
| has no routes in this file.
|
| The front end is a same-origin PWA, so these authenticate with the session
| cookie — bootstrap/app.php gives the api group the cookie, session and CSRF
| middleware. The desktop app will be issued tokens alongside this.
|
| `active` ends the session of an account switched off after it signed in.
| `business` resolves the tenant, and without it BusinessScope does nothing,
| so every route below must stay inside this group.
| `writable` makes a suspended business read only: anything but GET and HEAD
| is refused, sign-out excepted. A route that changes data on a GET would walk
| straight past it, so writes are POST, PATCH or DELETE.
|
*/

// Signing in needs no tenant: the email is unique across the platform.
Route::post('login', [AuthController::class, 'login']);

Route::middleware(['auth', 'active', 'business', 'writable'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    // Everything the front end needs to start, in one call.
    Route::get('me', [AuthController::class, 'me']);

    // Selling
    Route::get('scan', ScanController::class);
    Route::get('variants/search', VariantSearchController::class);

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::post('invoices', [InvoiceController::class, 'store']);
    // Declared before `{invoice}` so the word is not read as an id.
    Route::get('invoices/summary', [InvoiceController::class, 'summary']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);

    Route::get('reports/sales', [ReportController::class, 'sales']);

    // The till's drawer. `current` before `{shift}` so the word is not read as an id.
    Route::get('shifts', [ShiftController::class, 'index']);
    Route::get('shifts/current', [ShiftController::class, 'current']);
    Route::post('shifts', [ShiftController::class, 'store']);
    Route::post('shifts/{shift}/close', [ShiftController::class, 'close']);

    // Returns and exchanges. `lookup` is declared before `{saleReturn}` so the
    // word is not read as an id.
    Route::get('returns/lookup', [SaleReturnController::class, 'lookup']);
    Route::get('returns', [SaleReturnController::class, 'index']);
    Route::post('returns', [SaleReturnController::class, 'store']);
    Route::get('returns/{saleReturn}', [SaleReturnController::class, 'show']);

    // Catalogue
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::patch('categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::patch('products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);

    Route::post('products/{product}/variants', [ProductVariantController::class, 'store']);
    Route::patch('variants/{variant}', [ProductVariantController::class, 'update']);
    Route::delete('variants/{variant}', [ProductVariantController::class, 'destroy']);

    // Bulk-load the catalogue from a file. All or nothing.
    Route::get('catalogue/import/template', [CatalogueImportController::class, 'template']);
    Route::post('catalogue/import', [CatalogueImportController::class, 'store']);

    // Stock. `movements` before nothing else, but kept above the write routes
    // for readability.
    Route::get('stock', [StockController::class, 'index']);
    Route::get('stock/movements', [StockController::class, 'movements']);
    Route::post('stock/receive', [StockController::class, 'receive']);
    Route::post('stock/adjust', [StockController::class, 'adjust']);
    Route::get('stock/transfers', [StockTransferController::class, 'index']);
    Route::post('stock/transfers', [StockTransferController::class, 'store']);
    Route::get('stock/transfers/{transfer}', [StockTransferController::class, 'show']);
    Route::post('stock/transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
    Route::post('stock/transfers/{transfer}/settle', [StockTransferController::class, 'settle']);
    Route::post('stock/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel']);

    // The shop itself: branches, the people, the tills and the settings.
    // Writes here are the owner's alone (the manage-business gate); a manager
    // and a seller can read what concerns their own branch.
    Route::get('branches', [BranchController::class, 'index']);
    Route::post('branches', [BranchController::class, 'store']);
    Route::patch('branches/{branch}', [BranchController::class, 'update']);
    Route::delete('branches/{branch}', [BranchController::class, 'destroy']);

    Route::get('users', [TeamController::class, 'index']);
    Route::post('users', [TeamController::class, 'store']);
    Route::patch('users/{user}', [TeamController::class, 'update']);
    Route::delete('users/{user}', [TeamController::class, 'destroy']);

    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices', [DeviceController::class, 'store']);
    Route::patch('devices/{device}', [DeviceController::class, 'update']);
    Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::patch('settings', [SettingsController::class, 'update']);
    Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
    Route::delete('settings/logo', [SettingsController::class, 'removeLogo']);
    Route::get('brand/preview', [SettingsController::class, 'preview']);
});
