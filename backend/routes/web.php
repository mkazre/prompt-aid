<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PageBuilderPreviewController;
use App\Http\Controllers\PageBuilderRenderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PharmacyPageController;
use App\Http\Controllers\RideTrackingController;
use App\Http\Controllers\ShopPageController;
use App\Http\Controllers\ShuttlePageController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\ThirdPartyPageController;
use App\Http\Controllers\WebAppointmentController;
use App\Http\Controllers\WebAuthController;
use App\Http\Controllers\WebRideController;
use Illuminate\Support\Facades\Route;

// Note: /storage/{path} is served by Laravel's own built-in route (the
// 'public' disk's `serve` => true in config/filesystems.php) — no symlink
// needed, works on hosts that block them. See that file for details.

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/clinics', [PageController::class, 'clinics'])->name('clinics.index');
Route::get('/clinics/{clinic}', [PageController::class, 'clinicShow'])->name('clinics.show');
Route::get('/doctors', [PageController::class, 'doctors'])->name('doctors.index');
Route::get('/doctors/{doctor}', [PageController::class, 'doctorShow'])->name('doctors.show');
Route::get('/doctors/{doctor}/slots', [PageController::class, 'doctorSlots'])->name('doctors.slots');

Route::get('/pharmacies', [PharmacyPageController::class, 'index'])->name('pharmacies.index');
Route::get('/pharmacies/{pharmacy}', [PharmacyPageController::class, 'show'])->name('pharmacies.show');

Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'contactSubmit'])->name('contact.submit');

Route::get('/for-providers', [StaticPageController::class, 'forProviders'])->name('for-providers');
Route::post('/for-providers', [StaticPageController::class, 'forProvidersSubmit'])->name('for-providers.submit');

Route::get('/emergency', [StaticPageController::class, 'emergency'])->name('emergency');
Route::get('/emergency-results', [StaticPageController::class, 'emergencyResults'])->name('emergency.results');

// About, How It Works, FAQ, Privacy, Terms and the POPIA notice are pure
// content — real page-builder pages (see SeedSitePages), editable from
// /staff/pages without a deploy. Named routes to the same renderer the
// catch-all uses below, so route('about') etc. still resolve from Blade.
foreach (['about', 'how-it-works', 'faq', 'privacy', 'terms', 'legal-popia'] as $contentSlug) {
    Route::get("/{$contentSlug}", fn (
        \Illuminate\Http\Request $request,
        PageBuilderRenderController $controller,
        \App\PageBuilder\Rendering\PageRenderer $renderer,
        \App\PageBuilder\Templates\TemplateResolver $templates,
    ) => $controller->render($request, $contentSlug, $renderer, $templates))->name($contentSlug);
}

Route::get('/shuttle', [ShuttlePageController::class, 'index'])->name('shuttle');
Route::post('/shuttle/quote', [ShuttlePageController::class, 'quote'])->name('shuttle.quote');

Route::get('/shop', [ShopPageController::class, 'index'])->name('shop.index');
Route::get('/shop/{product}', [ShopPageController::class, 'show'])->name('shop.show');

Route::get('/labs', [ThirdPartyPageController::class, 'labsIndex'])->name('labs.index');
Route::get('/labs/{lab}', [ThirdPartyPageController::class, 'labsShow'])->name('labs.show');
Route::get('/specialists', [ThirdPartyPageController::class, 'specialistsIndex'])->name('specialists.index');
Route::get('/specialists/{specialist}', [ThirdPartyPageController::class, 'specialistsShow'])->name('specialists.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->name('login.store');
    Route::get('/register', [WebAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [WebAuthController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/appointments', [WebAppointmentController::class, 'store'])->name('appointments.store');
    Route::post('/rides', [WebRideController::class, 'store'])->name('rides.store');
    Route::post('/rides/{ride}/return', [WebRideController::class, 'requestReturn'])->name('rides.return');
    Route::get('/rides/{ride}/track', [RideTrackingController::class, 'show'])->name('rides.track');
    Route::get('/rides/{ride}/status', [RideTrackingController::class, 'status'])->name('rides.status');
    Route::post('/pharmacies/{pharmacy}/checkout', [PharmacyPageController::class, 'checkout'])->name('pharmacies.checkout');
    Route::get('/staff/notifications/unread-count', function () {
        return response()->json(['count' => request()->user()->unreadNotifications()->count()]);
    })->name('staff.notifications.unread-count');
    Route::get('/staff/preview/pages/{page}', [PageBuilderPreviewController::class, 'show'])->name('page-builder.preview');
});

// Page builder catch-all — must stay last. Never matches a path starting
// with a reserved segment (auth, dashboard, checkout, rides, the Filament
// panels, or the API), regardless of route registration order, since that
// exclusion is baked into the route's own pattern rather than relying on
// "runs after everything else" alone.
Route::get('/{path}', [PageBuilderRenderController::class, 'render'])
    ->where('path', '^(?!(login|register|dashboard|logout|appointments|checkout|rides|staff|vendor|partner|api|contact|doctors|clinics|pharmacies|storage|build|about|how-it-works|for-providers|faq|privacy|terms|legal-popia|shop|labs|specialists|shuttle|emergency|assets)(/|$)).+$')
    ->name('page-builder.render');
