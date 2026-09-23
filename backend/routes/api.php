<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LabRequestController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RideController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true, 'app' => 'Prompt Aid API']));

// --- Public ---
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/clinics', [ClinicController::class, 'index']);
Route::get('/clinics/{clinic}', [ClinicController::class, 'show']);
Route::get('/doctors', [ClinicController::class, 'doctors']);
Route::get('/doctors/{doctor}', [ClinicController::class, 'doctor']);
Route::get('/doctors/{doctor}/available-slots', [AppointmentController::class, 'availableSlots']);
Route::get('/doctors/{doctorId}/reviews', [ReviewController::class, 'forDoctor']);

Route::get('/pharmacies', [PharmacyController::class, 'index']);
Route::get('/pharmacies/{pharmacy}', [PharmacyController::class, 'show']);
Route::get('/products', [PharmacyController::class, 'products']);

Route::post('/rides/quote', [RideController::class, 'quote']);

// --- Authenticated (any role) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // --- Patient-only ---
    Route::middleware('role:'.User::ROLE_PATIENT)->group(function () {
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);
        Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);

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

        Route::post('/prescriptions', [PharmacyController::class, 'uploadPrescription']);
        Route::get('/prescriptions', [PharmacyController::class, 'myPrescriptions']);
        Route::post('/orders/checkout', [PharmacyController::class, 'checkout']);
        Route::get('/orders', [PharmacyController::class, 'myOrders']);
        Route::post('/orders/{order}/pay', [PharmacyController::class, 'payOrder']);
    });

    // --- Doctor-only ---
    Route::middleware('role:'.User::ROLE_DOCTOR)->prefix('doctor')->group(function () {
        Route::get('/stats', [DoctorController::class, 'stats']);
        Route::get('/appointments', [DoctorController::class, 'appointments']);
        Route::post('/appointments/{id}/status', [DoctorController::class, 'updateAppointmentStatus']);
        Route::get('/patients', [DoctorController::class, 'patients']);
        Route::get('/lab-requests', [LabRequestController::class, 'doctorIndex']);
        Route::post('/lab-requests', [LabRequestController::class, 'store']);
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
        Route::post('/rides/{ride}/status', [RideController::class, 'updateStatus']);
        Route::post('/location', [RideController::class, 'updateLocation']);
        Route::post('/availability', [RideController::class, 'toggleAvailability']);
    });

    // --- Shared ride detail/cancel (patient or driver, ownership-checked in controller) ---
    Route::get('/rides/{ride}', [RideController::class, 'show']);
    Route::post('/rides/{ride}/cancel', [RideController::class, 'cancel']);
});
