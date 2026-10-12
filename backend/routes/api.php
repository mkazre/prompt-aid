<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DoctorMatchController;
use App\Http\Controllers\Api\EmergencyContactController;
use App\Http\Controllers\Api\HealthArticleController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LabRequestController;
use App\Http\Controllers\Api\MedicalSchemeController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RideController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\TriageSubmissionController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true, 'app' => 'Prompt Aid API']));

// Search-as-you-type (grouped: specialities, conditions, doctors, clinics, products) + CMS pages for the app.
Route::middleware('throttle:90,1')->get('/search', \App\Http\Controllers\Api\SearchController::class);
Route::get('/app-pages/{key}', [\App\Http\Controllers\Api\AccountController::class, 'page'])->where('key', 'terms|about|privacy');

Route::post('/triage/submit', [TriageSubmissionController::class, 'store']);
Route::get('/emergency-contacts', [EmergencyContactController::class, 'index']);
Route::get('/app-links', fn () => response()->json(['data' => \App\Support\SiteLinks::forJs()]));
// Admin-editable copy + links for the mobile app (see App\Support\SiteContent).
Route::get('/site-content', fn () => response()->json(['data' => \App\Support\SiteContent::all() + [
    'topic' => [
        'title' => \App\Models\HealthTopic::current()?->title,
        'url' => route('topic.show'),
        'testimonial_url' => route('testimonials.create'),
    ],
    // Feature switches (Staff → System → Features); the app hides switched-off services.
    'features' => \App\Support\Features::all(),
]]));
// Feature switches, login activity (see routes/api_platform.php for its own auth groups).
require __DIR__.'/api_platform.php';
Route::get('/health-articles', [HealthArticleController::class, 'index']);
Route::get('/health-articles/{slug}', [HealthArticleController::class, 'show']);

// --- Public ---
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/medical-schemes', [MedicalSchemeController::class, 'index']);

Route::get('/clinics', [ClinicController::class, 'index']);
Route::get('/clinics/{clinic}', [ClinicController::class, 'show']);
Route::get('/doctors', [ClinicController::class, 'doctors']);
Route::get('/partners', [ClinicController::class, 'partners']);
Route::get('/top-rated', [ClinicController::class, 'topRated']);
Route::get('/doctors/{doctor}', [ClinicController::class, 'doctor']);
Route::get('/doctors/{doctor}/available-slots', [AppointmentController::class, 'availableSlots']);
Route::get('/doctors/{doctorId}/reviews', [ReviewController::class, 'forDoctor']);

Route::get('/pharmacies', [PharmacyController::class, 'index']);
Route::get('/pharmacies/{pharmacy}', [PharmacyController::class, 'show']);
Route::get('/products', [PharmacyController::class, 'products']);

Route::post('/rides/quote', [RideController::class, 'quote']);
Route::post('/rides/quote-by-address', [RideController::class, 'quoteByAddress']);

// Address typeahead + reverse lookup (OpenStreetMap Nominatim proxy, cached).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/geocode/autocomplete', [GeocodeController::class, 'autocomplete']);
    Route::get('/geocode/reverse', [GeocodeController::class, 'reverse']);
});

// Chat attachment download — authorised by a short-lived relative signature
// minted only for users who passed ConversationPolicy::view (see Message::attachmentUrl).
Route::get('/messages/{message}/attachment', [\App\Http\Controllers\Api\ConversationController::class, 'attachment'])
    ->middleware('signed:relative')
    ->whereNumber('message')
    ->name('chat.attachment');

// --- Authenticated (any role) ---
Route::middleware('auth:sanctum')->group(function () {
    require __DIR__.'/api_doctor_match.php';
    require __DIR__.'/api_privacy.php';
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::middleware('throttle:10,1')->post('/profile/password', [\App\Http\Controllers\Api\AccountController::class, 'changePassword']);

    // Config-driven home/screen widgets — role-scoped inside the controller.
    Route::get('/app-widgets', [WidgetController::class, 'index']);
    Route::get('/widgets/data', [WidgetController::class, 'data']);

    // --- Comms: chat, in-app notifications, video (patient + doctor; policy/ownership-checked in controllers) ---
    Route::get('/conversations', [\App\Http\Controllers\Api\ConversationController::class, 'index']);
    Route::post('/conversations', [\App\Http\Controllers\Api\ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}', [\App\Http\Controllers\Api\ConversationController::class, 'show'])->whereNumber('conversation');
    Route::get('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\ConversationController::class, 'messages'])->whereNumber('conversation');
    Route::middleware('throttle:60,1')->post('/conversations/{conversation}/messages', [\App\Http\Controllers\Api\ConversationController::class, 'send'])->whereNumber('conversation');
    Route::post('/conversations/{conversation}/read', [\App\Http\Controllers\Api\ConversationController::class, 'markRead'])->whereNumber('conversation');

    Route::post('/push-tokens', [\App\Http\Controllers\Api\PushTokenController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/push-tokens', [\App\Http\Controllers\Api\PushTokenController::class, 'destroy']);
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead'])->whereNumber('id');
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy'])->whereNumber('id');
    Route::delete('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'clear']);

    Route::get('/appointments/{appointment}/video', [\App\Http\Controllers\Api\VideoConsultController::class, 'show'])->whereNumber('appointment');
    Route::post('/appointments/{appointment}/video/end', [\App\Http\Controllers\Api\VideoConsultController::class, 'end'])->whereNumber('appointment');

    // --- Patient-only ---
    Route::middleware('role:'.User::ROLE_PATIENT)->group(function () {
        // Named api.appointments.* so they don't shadow the website's
        // appointments.store booking route (and so route:cache works).
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show'])->names('api.appointments');
        Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);

        Route::post('/request-doctor', [DoctorMatchController::class, 'store']);

        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
        Route::post('/invoices/{id}/pay', [InvoiceController::class, 'pay']);

        Route::post('/reviews', [ReviewController::class, 'store']);

        Route::get('/rides', [RideController::class, 'index']);
        Route::post('/rides', [RideController::class, 'store']);
        Route::post('/rides/{ride}/review', [RideController::class, 'review']);
        Route::post('/rides/{ride}/return', [RideController::class, 'requestReturn']);

        Route::get('/ride-series', [RideController::class, 'indexSeries']);
        Route::post('/ride-series', [RideController::class, 'storeSeries']);
        Route::post('/ride-series/{rideSeries}/pause', [RideController::class, 'pauseSeries']);
        Route::post('/ride-series/{rideSeries}/resume', [RideController::class, 'resumeSeries']);

        Route::get('/lab-requests', [LabRequestController::class, 'patientIndex']);

        Route::get('/triage/latest', [TriageSubmissionController::class, 'latest']);

        Route::post('/prescriptions', [PharmacyController::class, 'uploadPrescription']);
        Route::get('/prescriptions', [PharmacyController::class, 'myPrescriptions']);
        Route::post('/orders/checkout', [PharmacyController::class, 'checkout']);
        Route::get('/orders', [PharmacyController::class, 'myOrders']);
        Route::post('/orders/{order}/pay', [PharmacyController::class, 'payOrder']);
    });

    // --- Doctor-only ---
    Route::middleware('role:'.User::ROLE_DOCTOR)->prefix('doctor')->group(function () {
        // Settings hub + calendar booking (all scoped to the signed-in doctor).
        Route::get('/settings', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'overview']);
        Route::post('/profile', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'updateProfile']);
        Route::put('/fee', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'updateFee']);
        Route::get('/services', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'services']);
        Route::post('/services', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'storeService']);
        Route::put('/services/{id}', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'updateService']);
        Route::delete('/services/{id}', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'destroyService']);
        Route::get('/time-off', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'timeOff']);
        Route::post('/time-off', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'storeTimeOff']);
        Route::delete('/time-off/{id}', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'destroyTimeOff']);
        Route::get('/sessions', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'sessions']);
        Route::put('/sessions', [\App\Http\Controllers\Api\DoctorSettingsController::class, 'replaceSessions']);
        Route::get('/slots', [\App\Http\Controllers\Api\DoctorBookingController::class, 'slots']);
        Route::post('/appointments', [\App\Http\Controllers\Api\DoctorBookingController::class, 'store']);
        Route::post('/appointments/{id}/reschedule', [\App\Http\Controllers\Api\DoctorBookingController::class, 'reschedule']);
        Route::get('/appointments/{id}/encounter', [\App\Http\Controllers\Api\DoctorEncounterController::class, 'show']);
        Route::post('/appointments/{id}/encounter', [\App\Http\Controllers\Api\DoctorEncounterController::class, 'store']);

        Route::get('/stats', [DoctorController::class, 'stats']);
        Route::get('/appointments', [DoctorController::class, 'appointments']);
        Route::post('/appointments/{id}/status', [DoctorController::class, 'updateAppointmentStatus']);
        Route::get('/patients', [DoctorController::class, 'patients']);
        Route::get('/lab-requests', [LabRequestController::class, 'doctorIndex']);
        Route::post('/lab-requests', [LabRequestController::class, 'store']);

        Route::get('/requests/available', [DoctorMatchController::class, 'available']);
        Route::post('/requests/{appointment}/accept', [DoctorMatchController::class, 'accept']);
        Route::post('/availability', [DoctorMatchController::class, 'toggleAvailability']);
    });

    // --- Third-party (lab/imaging partner) ---
    Route::middleware('role:'.User::ROLE_THIRD_PARTY)->prefix('third-party')->group(function () {
        Route::get('/lab-requests/available', [LabRequestController::class, 'available']);
        Route::get('/lab-requests', [LabRequestController::class, 'myRequests']);
        Route::post('/lab-requests/{labRequest}/accept', [LabRequestController::class, 'accept']);
        Route::post('/lab-requests/{labRequest}/status', [LabRequestController::class, 'updateStatus']);
        Route::post('/lab-requests/{labRequest}/results', [LabRequestController::class, 'uploadResult']);
    });

    // --- Shared lab request detail (doctor/patient/third-party, ownership-checked in controller) ---
    Route::get('/lab-requests/{labRequest}', [LabRequestController::class, 'show']);

    // --- Driver-only ---
    Route::middleware('role:'.User::ROLE_DRIVER)->prefix('driver')->group(function () {
        Route::get('/rides/available', [RideController::class, 'available']);
        Route::get('/rides', [RideController::class, 'myRides']);
        Route::post('/rides/{ride}/accept', [RideController::class, 'accept']);
        Route::post('/rides/{ride}/decline', [RideController::class, 'decline']);
        Route::post('/rides/{ride}/no-show', [RideController::class, 'noShow']);
        Route::post('/rides/{ride}/status', [RideController::class, 'updateStatus']);
        Route::post('/location', [RideController::class, 'updateLocation'])->middleware('throttle:60,1');
        Route::post('/availability', [RideController::class, 'toggleAvailability']);
        Route::get('/documents', [\App\Http\Controllers\Api\DriverDocumentController::class, 'index']);
        Route::post('/documents', [\App\Http\Controllers\Api\DriverDocumentController::class, 'store']);
        Route::delete('/documents/{id}', [\App\Http\Controllers\Api\DriverDocumentController::class, 'destroy']);
    });

    // --- Shared ride detail/cancel (patient or driver, ownership-checked in controller) ---
    Route::get('/rides/{ride}', [RideController::class, 'show']);
    Route::get('/rides/{ride}/live', [RideController::class, 'live'])->middleware('throttle:120,1');
    Route::get('/rides/{ride}/cancel-preview', [RideController::class, 'cancelPreview']);
    Route::post('/rides/{ride}/cancel', [RideController::class, 'cancel']);
    Route::post('/rides/{ride}/sos', [RideController::class, 'sos'])->middleware('throttle:5,1');

    // --- Pharmacy vendor only ---
    Route::middleware('role:'.User::ROLE_PHARMACY_ADMIN)->prefix('vendor')->group(function () {
        Route::get('/orders', [VendorController::class, 'orders']);
        Route::get('/orders/{order}', [VendorController::class, 'showOrder']);
        Route::post('/orders/{order}/advance', [VendorController::class, 'advanceOrder']);
        Route::get('/prescriptions/pending', [VendorController::class, 'pendingPrescriptions']);
        Route::post('/prescriptions/{prescriptionUpload}/approve', [VendorController::class, 'approvePrescription']);
        Route::post('/prescriptions/{prescriptionUpload}/reject', [VendorController::class, 'rejectPrescription']);
        Route::get('/products', [VendorController::class, 'products']);
        Route::post('/products/{product}/stock', [VendorController::class, 'updateStock']);
    });
});
