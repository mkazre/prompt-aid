<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DriverProfile;
use App\Models\PatientDocument;
use App\Models\User;
use App\Services\PatientRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Self-service registration — always creates a patient or driver
     * account (staff accounts are provisioned by an admin in Filament).
     *
     * The mobile wizard posts here once, on its final step, with all the
     * optional patient fields collected along the way (medical, scheme,
     * document) added on top of the original account-only shape. Drivers
     * only ever send the account fields, same as before.
     */
    public function register(Request $request, PatientRegistrationService $registrar)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_PATIENT, User::ROLE_DRIVER])],
            'dob' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'blood_group' => ['nullable', 'string', 'max:5'],
            'address' => ['nullable', 'string', 'max:255'],
            'allergies' => ['nullable', 'string'],
            'chronic_conditions' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'medical_scheme_id' => ['nullable', 'exists:medical_schemes,id'],
            'member_number' => ['required_with:medical_scheme_id', 'nullable', 'string', 'max:50'],
            'dependant_code' => ['nullable', 'string', 'max:10'],
            'main_member_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'document_type' => ['nullable', 'in:'.PatientDocument::TYPE_ID.','.PatientDocument::TYPE_MEDICAL_AID_CARD],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if ($data['role'] === User::ROLE_PATIENT) {
            $user = $registrar->register(
                account: $data,
                medical: collect($data)->only([
                    'dob', 'gender', 'blood_group', 'address',
                    'allergies', 'chronic_conditions',
                    'emergency_contact_name', 'emergency_contact_phone',
                ])->all(),
                scheme: collect($data)->only([
                    'medical_scheme_id', 'member_number', 'dependant_code', 'main_member_name',
                ])->all(),
                document: $request->file('document'),
                documentType: $data['document_type'] ?? null,
            );
        } else {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'status' => 'active',
            ]);

            DriverProfile::query()->create([
                'user_id' => $user->id,
                'license_no' => 'PENDING-'.$user->id,
                'status' => 'pending_approval',
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load(['patientProfile', 'driverProfile'])),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is not active. Please contact support.'], 403);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load(['patientProfile', 'driverProfile', 'doctorProfile', 'thirdPartyProfile'])),
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load(['patientProfile', 'driverProfile', 'doctorProfile', 'thirdPartyProfile']));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
