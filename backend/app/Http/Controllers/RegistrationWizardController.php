<?php

namespace App\Http\Controllers;

use App\Models\MedicalScheme;
use App\Models\PatientDocument;
use App\Services\PatientRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Patient-only signup wizard, separate from WebAuthController's flat
 * /register (kept untouched for backward compatibility — it's still linked
 * from the homepage and handles the driver signup path this wizard
 * doesn't cover). Steps stash their data in the session and only write to
 * the database on the final step, so an abandoned signup leaves no
 * half-created User row behind.
 */
class RegistrationWizardController extends Controller
{
    private const SESSION_KEY = 'patient_registration_wizard';

    public function showAccount()
    {
        return view('auth.wizard.account');
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $wizard = $request->session()->get(self::SESSION_KEY, []);
        // Kept in the session (server-side, not the cookie itself) only for
        // the few minutes it takes to finish the wizard; User::create()
        // hashes it once, on the final step.
        $wizard['account'] = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ];
        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('register.wizard.medical');
    }

    public function showMedical(Request $request)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        return view('auth.wizard.medical');
    }

    public function storeMedical(Request $request)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        $data = $request->validate([
            'allergies' => ['nullable', 'string'],
            'chronic_conditions' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $wizard = $request->session()->get(self::SESSION_KEY, []);
        $wizard['medical'] = $data;
        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('register.wizard.scheme');
    }

    public function showScheme(Request $request)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        $schemes = MedicalScheme::where('active', true)->orderBy('name')->get();

        return view('auth.wizard.scheme', compact('schemes'));
    }

    public function storeScheme(Request $request)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        $wizard = $request->session()->get(self::SESSION_KEY, []);

        if ($request->boolean('skip') || ! $request->filled('medical_scheme_id')) {
            $wizard['scheme'] = [];
        } else {
            $data = $request->validate([
                'medical_scheme_id' => ['required', 'exists:medical_schemes,id'],
                'member_number' => ['required', 'string', 'max:50'],
                'dependant_code' => ['nullable', 'string', 'max:10'],
                'main_member_name' => ['nullable', 'string', 'max:255'],
            ]);
            $wizard['scheme'] = $data;
        }

        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('register.wizard.documents');
    }

    public function showDocuments(Request $request)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        return view('auth.wizard.documents');
    }

    public function storeDocuments(Request $request, PatientRegistrationService $registrar)
    {
        if (! $this->hasAccountStep($request)) {
            return redirect()->route('register.wizard.account');
        }

        $wizard = $request->session()->get(self::SESSION_KEY, []);

        $document = null;
        $documentType = null;

        if (! $request->boolean('skip') && $request->hasFile('document')) {
            $request->validate([
                'document' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
                'document_type' => ['nullable', 'in:'.PatientDocument::TYPE_ID.','.PatientDocument::TYPE_MEDICAL_AID_CARD],
            ]);
            $document = $request->file('document');
            $documentType = $request->input('document_type', PatientDocument::TYPE_ID);
        }

        $user = $registrar->register(
            $wizard['account'],
            $wizard['medical'] ?? [],
            $wizard['scheme'] ?? [],
            $document,
            $documentType
        );

        $request->session()->forget(self::SESSION_KEY);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function hasAccountStep(Request $request): bool
    {
        return $request->session()->has(self::SESSION_KEY.'.account');
    }
}
