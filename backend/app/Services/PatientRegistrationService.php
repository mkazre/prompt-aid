<?php

namespace App\Services;

use App\Models\PatientDocument;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Single place that turns wizard-collected fields into real rows —
 * shared by the web registration wizard and the mobile API registration
 * endpoint so the two never drift on what "a full patient signup" creates.
 */
class PatientRegistrationService
{
    /**
     * @param  array{name:string,email:string,phone?:?string,password:string}  $account
     * @param  array{dob?:?string,gender?:?string,blood_group?:?string,address?:?string,allergies?:?string,chronic_conditions?:?string,emergency_contact_name?:?string,emergency_contact_phone?:?string}  $medical
     * @param  array{medical_scheme_id?:?int,member_number?:?string,dependant_code?:?string,main_member_name?:?string}  $scheme
     */
    public function register(array $account, array $medical = [], array $scheme = [], ?UploadedFile $document = null, ?string $documentType = null): User
    {
        $user = User::query()->create([
            'name' => $account['name'],
            'email' => $account['email'],
            'phone' => $account['phone'] ?? null,
            'password' => Hash::make($account['password']),
            'role' => User::ROLE_PATIENT,
            'status' => 'active',
        ]);

        $patient = PatientProfile::query()->create(array_merge(
            ['user_id' => $user->id],
            array_filter($medical, fn ($value) => $value !== null && $value !== '')
        ));

        if (! empty($scheme['medical_scheme_id'])) {
            $patient->schemeMemberships()->create($scheme);
        }

        if ($document) {
            $path = $document->store('patient-documents', 'public');

            $patient->documents()->create([
                'type' => $documentType ?: PatientDocument::TYPE_ID,
                'path' => $path,
                'original_name' => $document->getClientOriginalName(),
            ]);
        }

        return $user;
    }
}
