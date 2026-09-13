<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'doctor_profile_id' => ['nullable', 'integer', 'exists:doctor_profiles,id'],
            'clinic_id' => ['nullable', 'integer', 'exists:clinics,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = Review::query()->create([
            ...$data,
            'patient_profile_id' => $request->user()->patientProfile->id,
            'status' => 'pending',
        ]);

        return response()->json($review, 201);
    }

    public function forDoctor(int $doctorId)
    {
        $reviews = Review::query()
            ->where('doctor_profile_id', $doctorId)
            ->where('status', 'approved')
            ->with('patient.user')
            ->latest()
            ->paginate(15);

        return response()->json($reviews);
    }
}
