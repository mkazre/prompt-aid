<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\DataAccessLog;
use App\Models\DsrRequest;
use App\Models\User;
use App\Services\Privacy\ConsentService;
use App\Services\Privacy\DataExportService;
use App\Services\Privacy\DsrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * /account/privacy — consents, data subject requests, record access history,
 * plus the re-consent interstitial shown by RequireCurrentConsents.
 */
class PrivacyController extends Controller
{
    public function __construct(
        protected ConsentService $consents,
        protected DsrService $dsr,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $requests = DsrRequest::query()->where('user_id', $user->id)->latest()->limit(20)->get();
        $downloadUrls = [];
        foreach ($requests as $r) {
            $downloadUrls[$r->id] = $this->dsr->downloadUrl($r);
        }

        $patientId = $user->patientProfile?->id;
        $accessLog = $patientId
            ? DataAccessLog::query()->where('patient_profile_id', $patientId)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->with('user:id,name')
                ->latest('created_at')->limit(50)->get()
            : collect();

        return view('account.privacy', [
            'user' => $user,
            'consentStatus' => $this->consents->statusFor($user),
            'requests' => $requests,
            'downloadUrls' => $downloadUrls,
            'accessLog' => $accessLog,
            'roleLabels' => self::roleLabels(),
            'dsrTypes' => DsrRequest::TYPES,
            'dsrStatuses' => DsrRequest::STATUSES,
        ]);
    }

    public function updateConsents(Request $request)
    {
        $user = $request->user();
        $ticked = (array) $request->input('consents', []);

        foreach (Consent::OPTIONAL as $purpose) {
            $want = in_array($purpose, $ticked, true);
            $has = $this->consents->has($user, $purpose);

            if ($want && ! $has) {
                $this->consents->grant($user, [$purpose], 'web', $request);
            } elseif (! $want && $has) {
                $this->consents->withdraw($user, $purpose);
            }
        }

        return redirect()->route('account.privacy')->with('success', 'Your privacy choices have been saved.');
    }

    public function storeRequest(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DsrRequest::TYPES))],
            'details' => [Rule::requiredIf(fn () => in_array($request->input('type'), [DsrRequest::TYPE_CORRECTION, DsrRequest::TYPE_OBJECTION], true)), 'nullable', 'string', 'max:5000'],
            'confirm_deletion' => [Rule::requiredIf(fn () => $request->input('type') === DsrRequest::TYPE_DELETION), 'nullable', 'accepted'],
        ], [
            'details.required' => 'Please tell us what needs to change or what you object to.',
            'confirm_deletion.required' => 'Please confirm you understand what happens when your account is deleted.',
        ]);

        $dsr = $this->dsr->create($request->user(), $data['type'], $data['details'] ?? null, 'web');

        $message = match (true) {
            $dsr->type === DsrRequest::TYPE_ACCESS && $dsr->hasDownloadableExport() => 'Your data export is ready to download below.',
            $dsr->type === DsrRequest::TYPE_ACCESS => 'We received your request and will email you when your export is ready.',
            default => 'We received your request. We will respond by '.$dsr->due_at?->toFormattedDateString().'.',
        };

        return redirect()->route('account.privacy')->with('success', $message);
    }

    /** Signed + auth + owner-only download of an access-request export. */
    public function export(Request $request, DsrRequest $dsr)
    {
        abort_unless($request->user() && (int) $dsr->user_id === (int) $request->user()->id, 403);
        abort_unless($dsr->hasDownloadableExport(), 410, 'This download has expired. Request a new copy from your privacy page.');

        $disk = Storage::disk(DataExportService::DISK);
        abort_unless($disk->exists($dsr->export_path), 410, 'This download has expired. Request a new copy from your privacy page.');

        $ext = pathinfo($dsr->export_path, PATHINFO_EXTENSION) ?: 'json';

        return $disk->download($dsr->export_path, 'prompt-aid-my-data-'.now()->format('Ymd').'.'.$ext, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function showReconsent(Request $request)
    {
        $outstanding = $this->consents->outstandingFor($request->user());

        if ($outstanding === []) {
            return redirect()->to($this->intended($request));
        }

        return view('privacy.reconsent', ['documents' => $outstanding]);
    }

    public function acceptReconsent(Request $request)
    {
        $user = $request->user();
        $outstanding = $this->consents->outstandingFor($user);
        $purposes = array_column($outstanding, 'purpose');

        $accepted = (array) $request->input('accept', []);
        $missing = array_diff($purposes, $accepted);
        if ($missing !== []) {
            return back()->withErrors(['accept' => 'Please read and accept each updated document to continue.']);
        }

        $this->consents->grant($user, $purposes, 'web', $request);

        return redirect()->to($this->intended($request))->with('success', 'Thank you — your acceptance has been recorded.');
    }

    protected function intended(Request $request): string
    {
        $next = (string) $request->session()->pull('privacy.reconsent_next', '');

        // Same-origin paths only (no open redirect).
        return ($next !== '' && str_starts_with($next, '/') && ! str_starts_with($next, '//')) ? $next : route('dashboard');
    }

    /** @return array<string, string> */
    public static function roleLabels(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Prompt Aid administrator',
            User::ROLE_CLINIC_ADMIN => 'Clinic administrator',
            User::ROLE_DOCTOR => 'Doctor',
            User::ROLE_THIRD_PARTY => 'Lab / diagnostics partner',
            User::ROLE_PHARMACY_ADMIN => 'Pharmacy',
            User::ROLE_DRIVER => 'Shuttle driver',
            User::ROLE_PATIENT => 'Patient',
        ];
    }
}
