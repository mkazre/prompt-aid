<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PharmacyPageController;
use App\Http\Controllers\RideTrackingController;
use App\Http\Controllers\WebAppointmentController;
use App\Http\Controllers\WebAuthController;
use App\Http\Controllers\WebRideController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/clinics', [PageController::class, 'clinics'])->name('clinics.index');
Route::get('/doctors', [PageController::class, 'doctors'])->name('doctors.index');
Route::get('/doctors/{doctor}', [PageController::class, 'doctorShow'])->name('doctors.show');
Route::get('/doctors/{doctor}/slots', [PageController::class, 'doctorSlots'])->name('doctors.slots');

Route::get('/pharmacies', [PharmacyPageController::class, 'index'])->name('pharmacies.index');
Route::get('/pharmacies/{pharmacy}', [PharmacyPageController::class, 'show'])->name('pharmacies.show');

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
    Route::get('/rides/{ride}/track', [RideTrackingController::class, 'show'])->name('rides.track');
    Route::get('/rides/{ride}/status', [RideTrackingController::class, 'status'])->name('rides.status');
    Route::post('/pharmacies/{pharmacy}/checkout', [PharmacyPageController::class, 'checkout'])->name('pharmacies.checkout');
});
