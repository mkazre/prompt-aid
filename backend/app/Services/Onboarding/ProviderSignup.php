<?php

namespace App\Services\Onboarding;

use App\Models\ProviderApplication;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use App\Services\Privacy\ConsentService;
use App\Support\SiteContent;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Provider self sign-up (mobile app; any client can use the API).
 *
 * The applicant picks a type, fills in the fields that type needs and sets
 * their OWN password. We create:
 *   - a User with the right role, status "inactive" (cannot sign in yet);
 *   - a ProviderApplication linked to it (+ uploaded documents);
 * and alert the platform admins. Approving the application in
 * Staff → Provider applications activates that same login and creates the
 * doctor / pharmacy / clinic / partner record — no emailed temporary password.
 *
 * The list of types (labels, fields, which are open) is served to the app
 * from here, so it changes without an app update; admins switch types on/off
 * in Staff → Site content → Sign up & sign in.
 */
class ProviderSignup
{
    /**
     * key => [label, description, application type, role, partner category, fields].
     * Field keys: business_name, registration_no, hpcsa_sapc_no, specialization, address, city, category.
     */
    public const TYPES = [
        'doctor' => [
            'label' => 'Doctor',
            'description' => 'GP or specialist in private practice — consults, bookings and urgent requests.',
            'icon' => 'activity',
            'app_type' => ProviderApplication::TYPE_DOCTOR,
            'role' => User::ROLE_DOCTOR,
            'business_label' => 'Practice name (optional)',
            'business_required' => false,
            'fields' => ['business_name', 'hpcsa_sapc_no', 'specialization', 'address', 'city'],
            'required' => ['hpcsa_sapc_no', 'specialization'],
            'number_label' => 'HPCSA registration number',
        ],
        'clinic' => [
            'label' => 'Practice / clinic',
            'description' => 'GP practice, clinic or day hospital — manage doctors, reception and bookings.',
            'icon' => 'home',
            'app_type' => ProviderApplication::TYPE_CLINIC,
            'role' => User::ROLE_CLINIC_ADMIN,
            'business_label' => 'Practice / clinic name',
            'business_required' => true,
            'fields' => ['business_name', 'registration_no', 'hpcsa_sapc_no', 'address', 'city'],
            'required' => ['address'],
            'number_label' => 'Practice number (BHF)',
        ],
        'pharmacy' => [
            'label' => 'Pharmacy',
            'description' => 'Sell medicines and fill scripts with delivery or collection.',
            'icon' => 'shopping-bag',
            'app_type' => ProviderApplication::TYPE_PHARMACY,
            'role' => User::ROLE_PHARMACY_ADMIN,
            'business_label' => 'Pharmacy name',
            'business_required' => true,
            'fields' => ['business_name', 'registration_no', 'hpcsa_sapc_no', 'address', 'city'],
            'required' => ['hpcsa_sapc_no', 'address'],
            'number_label' => 'SAPC pharmacy registration number',
        ],
        'laboratory' => [
            'label' => 'Laboratory',
            'description' => 'Pathology and blood tests, incl. home draws, with results to the patient record.',
            'icon' => 'droplet',
            'app_type' => ProviderApplication::TYPE_THIRD_PARTY,
            'role' => User::ROLE_THIRD_PARTY,
            'category' => 'lab',
            'business_label' => 'Laboratory name',
            'business_required' => true,
            'fields' => ['business_name', 'registration_no', 'hpcsa_sapc_no', 'address', 'city'],
            'required' => ['address'],
            'number_label' => 'Practice / accreditation number',
        ],
        'radiology' => [
            'label' => 'Radiology / imaging',
            'description' => 'X-ray, ultrasound, CT and MRI centres.',
            'icon' => 'aperture',
            'app_type' => ProviderApplication::TYPE_THIRD_PARTY,
            'role' => User::ROLE_THIRD_PARTY,
            'category' => 'imaging',
            'business_label' => 'Practice name',
            'business_required' => true,
            'fields' => ['business_name', 'registration_no', 'hpcsa_sapc_no', 'address', 'city'],
            'required' => ['address'],
            'number_label' => 'Practice number (BHF)',
        ],
        'allied' => [
            'label' => 'Allied health & other services',
            'description' => 'Physio, dental, optometry, dietetics, audiology, home nursing and more.',
            'icon' => 'heart',
            'app_type' => ProviderApplication::TYPE_THIRD_PARTY,
            'role' => User::ROLE_THIRD_PARTY,
            'business_label' => 'Practice / business name',
            'business_required' => true,
            'fields' => ['business_name', 'category', 'registration_no', 'hpcsa_sapc_no', 'address', 'city'],
            'required' => ['category', 'address'],
            'number_label' => 'Professional registration number',
        ],
    ];

    public const ALLIED_CATEGORIES = [
        'physio' => 'Physiotherapy',
        'dental' => 'Dental',
        'optometry' => 'Optometry',
        'dietetics' => 'Dietetics',
        'audiology' => 'Audiology',
        'home_nursing' => 'Home nursing',
        'other' => 'Other',
    ];

    /** Types the admin has left open (all by default). @return array<int, string> */
    public static function openTypes(): array
    {
        $cfg = (array) SiteContent::get('signup.types', []);

        return array_values(array_filter(array_keys(self::TYPES), fn ($k) => (bool) ($cfg[$k] ?? true)));
    }

    /** Patients and drivers (their own flows) are switchable too. */
    public static function roleOpen(string $key): bool
    {
        return (bool) (((array) SiteContent::get('signup.types', []))[$key] ?? true);
    }

    /** What the app's "Join as…" screen shows. @return array<string, mixed> */
    public function catalogue(): array
    {
        $types = [];
        foreach (self::openTypes() as $key) {
            $t = self::TYPES[$key];
            $types[] = [
                'key' => $key,
                'label' => $t['label'],
                'description' => $t['description'],
                'icon' => $t['icon'],
                'fields' => $t['fields'],
                'required' => $t['required'],
                'business_label' => $t['business_label'],
                'business_required' => $t['business_required'],
                'number_label' => $t['number_label'],
            ];
        }

        return [
            'patient_open' => self::roleOpen('patient'),
            'driver_open' => self::roleOpen('driver'),
            'types' => $types,
            'categories' => collect(self::ALLIED_CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'intro' => (string) SiteContent::get('signup.provider_intro', ''),
            'review_note' => (string) SiteContent::get('signup.review_note', ''),
            'documents_hint' => 'Add your practice / registration certificate and ID (PDF or photo, up to 10 MB each). You can also send them later.',
        ];
    }

    /** @return array{application: ProviderApplication, user: User} */
    public function register(Request $request): array
    {
        $key = (string) $request->input('provider_type');
        if (! in_array($key, self::openTypes(), true)) {
            throw ValidationException::withMessages(['provider_type' => 'Choose what kind of provider you are.']);
        }
        $t = self::TYPES[$key];
        $req = fn (string $f) => in_array($f, $t['required'], true) ? 'required' : 'nullable';

        $data = $request->validate([
            'provider_type' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'business_name' => [$t['business_required'] ? 'required' : 'nullable', 'string', 'max:255'],
            'registration_no' => [$req('registration_no'), 'string', 'max:100'],
            'hpcsa_sapc_no' => [$req('hpcsa_sapc_no'), 'string', 'max:100'],
            'specialization' => [$req('specialization'), 'string', 'max:120'],
            'address' => [$req('address'), 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'category' => [$req('category'), Rule::in(array_keys(self::ALLIED_CATEGORIES))],
            'accept_terms' => ['accepted'],
            'documents' => ['nullable', 'array', 'max:6'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'accept_terms.accepted' => 'Please accept the terms of use and privacy policy to continue.',
            'email.unique' => 'An account with this email already exists — sign in instead, or use another email.',
            'phone.unique' => 'An account with this phone number already exists.',
        ]);

        $category = $t['category'] ?? ($data['category'] ?? null);
        $business = trim((string) ($data['business_name'] ?? '')) ?: ($key === 'doctor' ? 'Dr. '.$data['name'] : $data['name']);

        [$application, $user] = DB::transaction(function () use ($data, $t, $key, $category, $business, $request) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'role' => $t['role'],
                // Cannot sign in until an admin approves the application.
                'status' => 'inactive',
            ]);
            $application = ProviderApplication::query()->create([
                'user_id' => $user->id,
                'type' => $t['app_type'],
                'business_name' => $business,
                'contact_name' => $data['name'],
                'email' => $user->email,
                'phone' => $data['phone'],
                'registration_no' => $data['registration_no'] ?? null,
                'hpcsa_sapc_no' => $data['hpcsa_sapc_no'] ?? null,
                'status' => ProviderApplication::STATUS_SUBMITTED,
                'source' => 'app',
                'notes' => $t['label'].($category && isset(self::ALLIED_CATEGORIES[$category]) && $key === 'allied' ? ' — '.self::ALLIED_CATEGORIES[$category] : ''),
                'meta' => array_filter([
                    'signup_type' => $key,
                    'category' => $category,
                    'specialization' => $data['specialization'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            foreach ((array) $request->file('documents', []) as $file) {
                if ($file instanceof UploadedFile) {
                    $application->documents()->create([
                        'label' => 'Supporting document',
                        'path' => $file->store('provider-applications', 'public'),
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    ]);
                }
            }

            return [$application, $user];
        });

        app(ConsentService::class)->recordRegistration($user, ConsentService::purposesFromRequest($request), 'app', $request);

        try {
            $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
            if ($admins->isNotEmpty()) {
                StaffNotifier::alert(
                    $admins,
                    'New provider sign-up: '.$business,
                    $t['label'].' · '.$data['name'].' · '.$user->email.' · '.$data['phone'].' (from the app — approve to activate their login)',
                    icon: 'heroicon-o-building-office',
                    color: 'info',
                    url: Route::has('filament.admin.resources.provider-applications.edit') ? route('filament.admin.resources.provider-applications.edit', $application) : null,
                    actionLabel: 'Review',
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['application' => $application, 'user' => $user];
    }

    /**
     * Approval: activate the applicant's own login (app sign-up) and tell them.
     * Returns null when the application has no linked login (website form).
     */
    public function activateLinkedUser(ProviderApplication $record, string $role): ?User
    {
        $user = $record->user_id ? User::query()->find($record->user_id) : null;
        if (! $user) {
            return null;
        }
        if ($user->role !== $role) {
            throw new \RuntimeException("{$user->email} signed up as '{$user->role}', not '{$role}'. Change the application type or that account's role first.");
        }
        $user->forceFill(['status' => 'active'])->save();
        try {
            app(InAppNotifier::class)->send($user, 'account', 'You are approved', 'Your Prompt Aid application for '.$record->business_name.' was approved. Sign in with the email and password you chose.');
        } catch (\Throwable $e) {
            report($e);
        }

        return $user;
    }

    /** Partner (lab / imaging / allied) record on approval. */
    public function partnerProfile(ProviderApplication $record, User $user): ThirdPartyProfile
    {
        $meta = (array) $record->meta;
        $category = $meta['category'] ?? 'other';

        return ThirdPartyProfile::query()->firstOrCreate(['user_id' => $user->id], [
            'company_name' => $record->business_name,
            // service_type is a legacy enum (lab / imaging / pharmacy_delivery / home_nursing); category carries the real kind.
            'service_type' => in_array($category, ['imaging', 'home_nursing'], true) ? $category : 'lab',
            'category' => in_array($category, ThirdPartyProfile::CATEGORIES, true) ? $category : 'other',
            'license_no' => $record->hpcsa_sapc_no ?: $record->registration_no,
            'service_area' => ! empty($meta['city']) ? [$meta['city']] : null,
            'status' => 'active',
        ]);
    }

    /** Why a login is not active yet (app + website sign-in message). */
    public static function inactiveMessage(User $user): ?string
    {
        $app = ProviderApplication::query()->where('user_id', $user->id)->latest('id')->first();
        if (! $app) {
            return null;
        }
        if ($app->status === ProviderApplication::STATUS_REJECTED) {
            return 'Your application for '.$app->business_name.' was not approved'.($app->rejection_reason ? ': '.$app->rejection_reason : '.').' Contact support if you have questions.';
        }
        if ($app->status !== ProviderApplication::STATUS_APPROVED) {
            return 'Thanks — your application for '.$app->business_name.' is being reviewed. We will let you know as soon as you can sign in (usually within two working days).';
        }

        return null;
    }
}
