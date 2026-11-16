<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BusinessController as AdminBusinessController;
use App\Http\Controllers\LabelController;
use App\Enums\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Decided on the server from the session cookie, so a signed-in owner never
// sees the till flash first. Signed out goes to the till (the lightest page);
// signing in there sends managers and owners on to the dashboard.
Route::get('/', function () {
    $user = Auth::guard('web')->user();

    return redirect($user && $user->role !== Role::Seller ? '/dashboard' : '/till');
});

/*
|--------------------------------------------------------------------------
| The till
|--------------------------------------------------------------------------
|
| One page; everything after it is the API. It is served without auth because
| the app itself shows the sign-in form — redirecting here would mean the
| browser caching a redirect for a page that is valid once signed in.
|
*/
Route::get('/till', fn () => view('till'));

/*
|--------------------------------------------------------------------------
| The business dashboard
|--------------------------------------------------------------------------
|
| Same arrangement as the till: one page, served without auth, and the app
| shows its own sign-in form. Screens are hash routes (#/products, #/stock), so
| this is the only server route the dashboard needs and a refresh always lands
| on a valid URL.
|
*/
Route::get('/dashboard', fn () => view('dashboard'));

/*
|--------------------------------------------------------------------------
| Label printing
|--------------------------------------------------------------------------
|
| A page rather than an API route, because what comes back is something the
| browser prints at an exact paper size — see LabelController. It shares the
| session with the API, so signing in through /api/login is enough.
|
*/
Route::middleware(['auth', 'active', 'business'])->group(function () {
    Route::get('labels/print', [LabelController::class, 'print'])->name('labels.print');
    Route::get('labels/pdf', [LabelController::class, 'pdf'])->name('labels.pdf');
    Route::get('labels/calibration', [LabelController::class, 'calibration'])->name('labels.calibration');
    Route::get('labels/calibration.pdf', [LabelController::class, 'calibrationPdf'])->name('labels.calibration.pdf');
});

/*
|--------------------------------------------------------------------------
| Platform panel
|--------------------------------------------------------------------------
|
| Ours, not the shops': server-rendered Blade, no API, no SPA — decision 14.
| The `admin` guard and its own session cookie (UseAdminSession) keep it apart
| from everything above: a shop login cannot reach it, and an admin login is
| not accepted by the shops' API. Deliberately outside the `business` group.
|
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('login', [AdminAuthController::class, 'store']);

    Route::middleware(['auth:admin', 'admin.active'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'destroy'])->name('logout');

        Route::redirect('/', '/admin/businesses');
        Route::get('businesses', [AdminBusinessController::class, 'index'])->name('businesses.index');
        // Declared before `{business}` so the word is not read as an id.
        Route::get('businesses/create', [AdminBusinessController::class, 'create'])->name('businesses.create');
        Route::post('businesses', [AdminBusinessController::class, 'store'])->name('businesses.store');
        Route::get('brand/preview', [AdminBusinessController::class, 'previewBrand'])->name('brand.preview');
        Route::get('businesses/{business}', [AdminBusinessController::class, 'show'])->name('businesses.show');
        Route::put('businesses/{business}/brand', [AdminBusinessController::class, 'updateBrand'])
            ->name('businesses.brand');
        Route::put('businesses/{business}/subscription', [AdminBusinessController::class, 'updateSubscription'])
            ->name('businesses.subscription');
        Route::put('businesses/{business}/status', [AdminBusinessController::class, 'updateStatus'])
            ->name('businesses.status');
    });
});

/*
|--------------------------------------------------------------------------
| Till harness
|--------------------------------------------------------------------------
|
| The no-build page that drove the API before the Vue till existed. Kept for
| checking the API on its own when something looks wrong in the app. Local only.
|
*/
Route::get('/till-harness', function () {
    abort_unless(app()->environment('local'), 404);

    return view('till-harness');
});
