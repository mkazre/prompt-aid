<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\MedicalScheme;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $patient = $user->patientProfile;
        $membership = $patient->schemeMemberships()->with('scheme')->latest()->first();
        $schemes = MedicalScheme::where('active', true)->orderBy('name')->get();

        return view('account.profile', compact('user', 'patient', 'membership', 'schemes'));
    }

    // Mirrors Api\ProfileController's validation rules for the same fields,
    // kept separate since this one also handles the web-only scheme form.
    public function update(Request $request)
    {
        $user = $request->user();

        // 'sometimes' throughout: the personal-details, clinical-flags and
        // scheme forms on this page post independently, so any given
        // request may be missing the other forms' fields entirely.
        $user->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'unique:users,phone,'.$user->id],
        ]));

        $user->patientProfile()->updateOrCreate([], $request->validate([
            'dob' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other'],
            'blood_group' => ['sometimes', 'nullable', 'string', 'max:5'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'allergies' => ['sometimes', 'nullable', 'string'],
            'chronic_conditions' => ['sometimes', 'nullable', 'string'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]));

        if ($request->filled('medical_scheme_id')) {
            $schemeData = $request->validate([
                'medical_scheme_id' => ['required', 'exists:medical_schemes,id'],
                'member_number' => ['required', 'string', 'max:50'],
                'dependant_code' => ['nullable', 'string', 'max:10'],
                'main_member_name' => ['nullable', 'string', 'max:255'],
            ]);

            $user->patientProfile->schemeMemberships()->updateOrCreate(
                ['medical_scheme_id' => $schemeData['medical_scheme_id']],
                collect($schemeData)->except('medical_scheme_id')->all()
            );
        }

        return back()->with('success', 'Profile updated.');
    }
}
