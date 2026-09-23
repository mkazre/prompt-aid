<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'unique:users,phone,'.$user->id],
        ]);

        $user->update($data);

        if ($user->isPatient()) {
            $user->patientProfile()->updateOrCreate([], $request->validate([
                'dob' => ['sometimes', 'nullable', 'date'],
                'gender' => ['sometimes', 'nullable', 'in:male,female,other'],
                'blood_group' => ['sometimes', 'nullable', 'string'],
                'address' => ['sometimes', 'nullable', 'string'],
                'lat' => ['sometimes', 'nullable', 'numeric'],
                'lng' => ['sometimes', 'nullable', 'numeric'],
                'emergency_contact_name' => ['sometimes', 'nullable', 'string'],
                'emergency_contact_phone' => ['sometimes', 'nullable', 'string'],
                'allergies' => ['sometimes', 'nullable', 'string'],
                'chronic_conditions' => ['sometimes', 'nullable', 'string'],
            ]));
        }

        if ($user->isDriver()) {
            $user->driverProfile()->update($request->validate([
                'vehicle_make' => ['sometimes', 'nullable', 'string'],
                'vehicle_model' => ['sometimes', 'nullable', 'string'],
                'vehicle_color' => ['sometimes', 'nullable', 'string'],
                'vehicle_plate_no' => ['sometimes', 'nullable', 'string'],
                'vehicle_type' => ['sometimes', 'in:sedan,suv,van,wheelchair_accessible,stretcher'],
            ]));
        }

        return new UserResource($user->fresh(['patientProfile', 'driverProfile', 'doctorProfile']));
    }
}
