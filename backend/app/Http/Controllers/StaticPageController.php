<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use App\Models\ProviderApplication;
use App\Models\TriageConfig;
use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;

/**
 * For Providers carries a real lead-capture form, so — unlike About, How
 * It Works, FAQ, Privacy, Terms and the POPIA notice — it stays compiled
 * Blade+controller code rather than a page-builder page: builder pages
 * store raw HTML, which can't run Blade's route()/@csrf for a working POST.
 */
class StaticPageController extends Controller
{
    public function forProviders()
    {
        return view('pages.for-providers');
    }

    public function emergency()
    {
        $emergencyContacts = EmergencyContact::activeOrdered();

        return view('pages.emergency', [
            'emergencyContacts' => $emergencyContacts,
            'primaryEmergencyContact' => $emergencyContacts->firstWhere('is_primary', true),
            'triageConfig' => TriageConfig::current(),
        ]);
    }

    /**
     * The public "type" options map to the real ProviderApplication::TYPE_*
     * values so an approval can create the matching live provider record.
     *
     * @var array<string, string>
     */
    protected const TYPE_MAP = [
        'Doctor in private practice' => ProviderApplication::TYPE_DOCTOR,
        'Clinic or day hospital' => ProviderApplication::TYPE_CLINIC,
        'Pharmacy' => ProviderApplication::TYPE_PHARMACY,
        'Laboratory or imaging' => ProviderApplication::TYPE_THIRD_PARTY,
        'Physiotherapy or specialist service' => ProviderApplication::TYPE_THIRD_PARTY,
        'Shuttle driver or fleet' => ProviderApplication::TYPE_THIRD_PARTY,
    ];

    public function forProvidersSubmit(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:255'],
            'hpcsa_sapc_no' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'terms_accepted' => ['required', 'accepted'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $application = ProviderApplication::create([
            'type' => self::TYPE_MAP[$data['type']] ?? ProviderApplication::TYPE_THIRD_PARTY,
            'business_name' => $data['business_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'registration_no' => $data['registration_no'] ?? null,
            'hpcsa_sapc_no' => $data['hpcsa_sapc_no'] ?? null,
            'status' => ProviderApplication::STATUS_SUBMITTED,
            'notes' => $data['type'],
        ]);

        foreach ($request->file('documents', []) as $file) {
            $path = $file->store('provider-applications', 'public');

            $application->documents()->create([
                'label' => 'Supporting document',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ]);
        }

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                "Provider application: {$data['business_name']}",
                "{$data['type']} · {$data['contact_name']} · {$data['email']} · {$data['phone']}".
                    (! empty($data['registration_no']) ? " · Reg. {$data['registration_no']}" : ''),
                icon: 'heroicon-o-building-office',
                color: 'info',
                url: 'mailto:'.$data['email'],
                actionLabel: 'Reply by email',
            );
        }

        return back()->with('success', "Thanks {$data['contact_name']}, we've received your application and will be in touch within two working days.");
    }
}
