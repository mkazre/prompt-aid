<?php

use App\Http\Controllers\Account\AppointmentsController as AccountAppointmentsController;
use App\Http\Controllers\Account\InvoicesController as AccountInvoicesController;
use App\Http\Controllers\Account\OrdersController as AccountOrdersController;
use App\Http\Controllers\Account\PrescriptionsController as AccountPrescriptionsController;
use App\Http\Controllers\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\Account\RecordController as AccountRecordController;
use App\Http\Controllers\Account\ResultsController as AccountResultsController;
use App\Http\Controllers\Account\RidesController as AccountRidesController;
use App\Http\Controllers\Account\SettingsController as AccountSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmergencyResultsController;
use App\Http\Controllers\PageBuilderPreviewController;
use App\Http\Controllers\PageBuilderRenderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PharmacyPageController;
use App\Http\Controllers\RegistrationWizardController;
use App\Http\Controllers\RideTrackingController;
use App\Http\Controllers\ShopPageController;
use App\Http\Controllers\ShuttlePageController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\ThirdPartyPageController;
use App\Http\Controllers\TriageConfigController;
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
// Find care landing pages (one per tab) + virtual consult.
Route::get('/general-practitioners', fn (\Illuminate\Http\Request $r) => app(PageController::class)->doctors($r, 'gp'))->name('care.gp');
Route::get('/dentists', fn (\Illuminate\Http\Request $r) => app(PageController::class)->doctors($r, 'dentists'))->name('care.dentists');
Route::get('/specialists', fn (\Illuminate\Http\Request $r) => app(PageController::class)->doctors($r, 'specialists'))->name('specialists.index');
Route::get('/virtual-consult', fn (\Illuminate\Http\Request $r) => app(PageController::class)->doctors($r, 'video'))->name('virtual-consult');
Route::get('/doctors/{doctor}', [PageController::class, 'doctorShow'])->name('doctors.show');
Route::get('/doctors/{doctor}/slots', [PageController::class, 'doctorSlots'])->name('doctors.slots');

Route::get('/pharmacies', [PharmacyPageController::class, 'index'])->name('pharmacies.index');
Route::get('/pharmacies/{pharmacy}', [PharmacyPageController::class, 'show'])->name('pharmacies.show');

Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'contactSubmit'])->name('contact.submit');

Route::get('/for-providers', [StaticPageController::class, 'forProviders'])->name('for-providers');
Route::post('/for-providers', [StaticPageController::class, 'forProvidersSubmit'])->name('for-providers.submit');

Route::get('/emergency', [StaticPageController::class, 'emergency'])->name('emergency');
Route::get('/emergency-results', [EmergencyResultsController::class, 'show'])->name('emergency.results');
Route::post('/emergency/submit', [\App\Http\Controllers\TriageSubmissionController::class, 'store'])->name('emergency.submit');
Route::post('/emergency/alert-contact', [EmergencyResultsController::class, 'alertContact'])->name('emergency.alert-contact');
Route::get('/topic-of-the-month', [\App\Http\Controllers\HealthTopicController::class, 'current'])->name('topic.show');
Route::get('/topics/{topic}', [\App\Http\Controllers\HealthTopicController::class, 'show'])->name('topic.view');
Route::get('/testimonials/submit', [\App\Http\Controllers\HealthTopicController::class, 'createTestimonial'])->name('testimonials.create');
Route::post('/testimonials/submit', [\App\Http\Controllers\HealthTopicController::class, 'storeTestimonial'])->middleware('throttle:10,1')->name('testimonials.store');
// "Get connected to a Doctor in 30min" → the recovered doctor-now flow (login required).
Route::redirect('/see-a-doctor-now', '/account/see-a-doctor')->name('instant-consult');
Route::get('/assets/triage-config.json', [TriageConfigController::class, 'show'])->name('triage-config.json');

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
Route::post('/shuttle/quote', [ShuttlePageController::class, 'quote'])->middleware('throttle:30,1')->name('shuttle.quote');

// Public read-only "follow my trip" link (tokenized, expires when the ride ends).
Route::get('/trip/{token}', [\App\Http\Controllers\ShareTripController::class, 'show'])->middleware('throttle:30,1')->name('trip.share');
Route::get('/trip/{token}/status', [\App\Http\Controllers\ShareTripController::class, 'status'])->middleware('throttle:40,1')->name('trip.status');

// Cron-free background jobs trigger for an external pinger (key in settings; 404 while unset).
Route::get('/tick', \App\Http\Controllers\TickController::class)->middleware('throttle:10,1')->name('tick');

Route::get('/shop', [ShopPageController::class, 'index'])->name('shop.index');
Route::get('/shop/{product}', [ShopPageController::class, 'show'])->name('shop.show');

Route::get('/health-articles', [\App\Http\Controllers\HealthArticlePageController::class, 'index'])->name('health-articles.index');
Route::get('/health-articles/{slug}', [\App\Http\Controllers\HealthArticlePageController::class, 'show'])->name('health-articles.show');

Route::get('/radiology', [ThirdPartyPageController::class, 'radiologyIndex'])->name('radiology.index');
Route::get('/laboratories', [ThirdPartyPageController::class, 'laboratoriesIndex'])->name('laboratories.index');
Route::get('/allied', [ThirdPartyPageController::class, 'alliedIndex'])->name('allied.index');
Route::get('/allied/{provider}', [ThirdPartyPageController::class, 'alliedShow'])->name('allied.show');
Route::redirect('/labs', '/laboratories', 301)->name('labs.index');
Route::get('/labs/{lab}', [ThirdPartyPageController::class, 'labsShow'])->name('labs.show');
// Old "Physio & specialists" detail URLs now live under /allied.
Route::get('/specialists/{specialist}', fn (\App\Models\ThirdPartyProfile $specialist) => redirect()->route('allied.show', $specialist, 301))->name('specialists.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->name('login.store');
    Route::get('/register', [WebAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [WebAuthController::class, 'register'])->name('register.store');

    // Patient-only signup wizard — separate flow from the flat /register
    // above (which stays as-is for drivers and anyone linking straight to
    // it). See RegistrationWizardController for why.
    Route::prefix('join')->name('register.wizard.')->group(function () {
        Route::get('/', [RegistrationWizardController::class, 'showAccount'])->name('account');
        Route::post('/', [RegistrationWizardController::class, 'storeAccount'])->name('account.store');
        Route::get('/medical', [RegistrationWizardController::class, 'showMedical'])->name('medical');
        Route::post('/medical', [RegistrationWizardController::class, 'storeMedical'])->name('medical.store');
        Route::get('/scheme', [RegistrationWizardController::class, 'showScheme'])->name('scheme');
        Route::post('/scheme', [RegistrationWizardController::class, 'storeScheme'])->name('scheme.store');
        Route::get('/documents', [RegistrationWizardController::class, 'showDocuments'])->name('documents');
        Route::post('/documents', [RegistrationWizardController::class, 'storeDocuments'])->name('documents.store');
    });
});

Route::middleware('auth')->group(function () {
    require __DIR__.'/web_doctor_match.php';
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/appointments', [WebAppointmentController::class, 'store'])->name('appointments.store');

    Route::prefix('account')->name('account.')->group(function () {
        Route::get('/appointments', [AccountAppointmentsController::class, 'index'])->name('appointments');
        Route::post('/appointments/{appointment}/cancel', [AccountAppointmentsController::class, 'cancel'])->name('appointments.cancel');
        Route::get('/prescriptions', [AccountPrescriptionsController::class, 'index'])->name('prescriptions');
        Route::get('/results', [AccountResultsController::class, 'index'])->name('results');
        Route::get('/record', [AccountRecordController::class, 'index'])->name('record');
        Route::get('/orders', [AccountOrdersController::class, 'index'])->name('orders');
        Route::get('/rides', [AccountRidesController::class, 'index'])->name('rides');
        Route::get('/invoices', [AccountInvoicesController::class, 'index'])->name('invoices');
        Route::get('/profile', [AccountProfileController::class, 'edit'])->name('profile');
        Route::post('/profile', [AccountProfileController::class, 'update'])->name('profile.update');
        Route::get('/settings', [AccountSettingsController::class, 'edit'])->name('settings');
        Route::post('/settings/password', [AccountSettingsController::class, 'updatePassword'])->name('settings.password');

        // Chat, notifications, video (policy/ownership-checked in the controllers).
        Route::get('/appointments/{appointment}/video', [AccountAppointmentsController::class, 'video'])->name('appointments.video');
        Route::get('/messages', [\App\Http\Controllers\Account\MessagesController::class, 'index'])->name('messages');
        Route::post('/messages/start', [\App\Http\Controllers\Account\MessagesController::class, 'start'])->name('messages.start');
        Route::get('/messages/{conversation}/poll', [\App\Http\Controllers\Account\MessagesController::class, 'poll'])->whereNumber('conversation')->name('messages.poll');
        Route::middleware('throttle:60,1')->post('/messages/{conversation}', [\App\Http\Controllers\Account\MessagesController::class, 'send'])->whereNumber('conversation')->name('messages.send');
        Route::get('/notifications', [\App\Http\Controllers\Account\NotificationsController::class, 'index'])->name('notifications');
        Route::get('/notifications/poll', [\App\Http\Controllers\Account\NotificationsController::class, 'poll'])->middleware('throttle:60,1')->name('notifications.poll');
        Route::post('/notifications/read-all', [\App\Http\Controllers\Account\NotificationsController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/clear', [\App\Http\Controllers\Account\NotificationsController::class, 'clear'])->name('notifications.clear');
        Route::get('/notifications/{id}/open', [\App\Http\Controllers\Account\NotificationsController::class, 'open'])->whereNumber('id')->name('notifications.open');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\Account\NotificationsController::class, 'destroy'])->whereNumber('id')->name('notifications.destroy');
    });
    Route::post('/rides', [WebRideController::class, 'store'])->middleware('throttle:10,1')->name('rides.store');
    Route::post('/rides/{ride}/return', [WebRideController::class, 'requestReturn'])->name('rides.return');
    Route::get('/rides/{ride}/track', [RideTrackingController::class, 'show'])->name('rides.track');
    Route::get('/rides/{ride}/status', [RideTrackingController::class, 'status'])->middleware('throttle:120,1')->name('rides.status');
    Route::get('/rides/{ride}/cancel-preview', [WebRideController::class, 'cancelPreview'])->middleware('throttle:30,1')->name('rides.cancel-preview');
    Route::post('/rides/{ride}/cancel', [WebRideController::class, 'cancel'])->middleware('throttle:10,1')->name('rides.cancel');
    Route::post('/rides/{ride}/retry', [WebRideController::class, 'retry'])->middleware('throttle:10,1')->name('rides.retry');
    Route::post('/rides/{ride}/sos', [WebRideController::class, 'sos'])->middleware('throttle:5,1')->name('rides.sos');
    Route::post('/rides/{ride}/rate', [WebRideController::class, 'rate'])->middleware('throttle:10,1')->name('rides.rate');

    // Web driver console (drivers test/drive from a phone browser).
    Route::middleware('role:'.\App\Models\User::ROLE_DRIVER)->prefix('driver')->name('driver.')->group(function () {
        Route::get('/', [\App\Http\Controllers\DriverConsoleController::class, 'index'])->name('console');
        Route::get('/state', [\App\Http\Controllers\DriverConsoleController::class, 'state'])->middleware('throttle:120,1')->name('state');
        Route::post('/online', [\App\Http\Controllers\DriverConsoleController::class, 'online'])->middleware('throttle:20,1')->name('online');
        Route::post('/location', [\App\Http\Controllers\DriverConsoleController::class, 'location'])->middleware('throttle:60,1')->name('location');
        Route::post('/offers/{offer}/accept', [\App\Http\Controllers\DriverConsoleController::class, 'acceptOffer'])->middleware('throttle:30,1')->name('offers.accept');
        Route::post('/offers/{offer}/decline', [\App\Http\Controllers\DriverConsoleController::class, 'declineOffer'])->middleware('throttle:30,1')->name('offers.decline');
        Route::post('/rides/{ride}/claim', [\App\Http\Controllers\DriverConsoleController::class, 'claim'])->middleware('throttle:30,1')->name('rides.claim');
        Route::post('/rides/{ride}/status', [\App\Http\Controllers\DriverConsoleController::class, 'status'])->middleware('throttle:30,1')->name('rides.status');
        Route::post('/rides/{ride}/cancel', [\App\Http\Controllers\DriverConsoleController::class, 'cancel'])->middleware('throttle:10,1')->name('rides.cancel');
        Route::post('/rides/{ride}/no-show', [\App\Http\Controllers\DriverConsoleController::class, 'noShow'])->middleware('throttle:10,1')->name('rides.no-show');
        Route::post('/rides/{ride}/rate', [\App\Http\Controllers\DriverConsoleController::class, 'rate'])->middleware('throttle:10,1')->name('rides.rate');
    });
    Route::post('/pharmacies/{pharmacy}/checkout', [PharmacyPageController::class, 'checkout'])->name('pharmacies.checkout');
    Route::get('/staff/notifications/unread-count', function () {
        $user = request()->user();
        $latest = $user->unreadNotifications()->latest()->first();

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
            'latest' => $latest ? ['title' => $latest->data['title'] ?? null, 'body' => isset($latest->data['body']) ? strip_tags((string) $latest->data['body']) : null] : null,
        ]);
    })->middleware('throttle:60,1')->name('staff.notifications.unread-count');
    Route::get('/staff/preview/pages/{page}', [PageBuilderPreviewController::class, 'show'])->name('page-builder.preview');
    require __DIR__.'/web_privacy.php';
    require __DIR__.'/web_commerce.php';
});

// Page builder catch-all — must stay last. Never matches a path starting
// with a reserved segment (auth, dashboard, checkout, rides, the Filament
// panels, or the API), regardless of route registration order, since that
// exclusion is baked into the route's own pattern rather than relying on
// "runs after everything else" alone.
Route::get('/{path}', [PageBuilderRenderController::class, 'render'])
    ->where('path', '^(?!(login|register|join|dashboard|account|logout|appointments|checkout|rides|staff|vendor|partner|api|contact|doctors|clinics|pharmacies|storage|build|about|how-it-works|for-providers|faq|privacy|terms|legal-popia|shop|labs|specialists|shuttle|emergency|assets|driver|trip|tick|health-articles|general-practitioners|dentists|virtual-consult|radiology|laboratories|allied|see-a-doctor-now|topic-of-the-month|topics|testimonials)(/|$)).+$')
    ->name('page-builder.render');
