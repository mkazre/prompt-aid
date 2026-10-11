<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Account\PrivacyController as WebPrivacyController;
use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\DataAccessLog;
use App\Models\DsrRequest;
use App\Models\LegalDocument;
use App\Services\Privacy\ConsentService;
use App\Services\Privacy\DataExportService;
use App\Services\Privacy\DsrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * POPIA endpoints for the mobile app (all auth:sanctum). Not yet used by
 * the app — exposed so a future app release can show consent prompts,
 * privacy toggles and data requests.
 */
class PrivacyController extends Controller
{
    public function __construct(
        protected ConsentService $consents,
        protected DsrService $dsr,
    ) {}

    /** GET /api/privacy/consents */
    public function consents(Request $request)
    {
        return response()->json(['data' => $this->consents->statusFor($request->user())]);
    }

    /**
     * GET /api/privacy/consents/required
     * `blocking` = documents the user must accept before continuing (a new
     * version an admin published with re-consent required). `not_on_record`
     * = required purposes with no consent on file at all (e.g. accounts made
     * by app builds without a terms checkbox) — the app may prompt softly.
     */
    public function required(Request $request)
    {
        $user = $request->user();
        $blocking = $this->consents->outstandingFor($user);
        $current = LegalDocument::currentByPurpose();

        $notOnRecord = [];
        foreach ($this->consents->requiredPurposesFor($user) as $purpose) {
            if (! $this->consents->has($user, $purpose) && isset($current[$purpose])) {
                $notOnRecord[] = $current[$purpose];
            }
        }

        $shape = fn (array $doc) => [
            'purpose' => $doc['purpose'],
            'version' => $doc['version'],
            'title' => $doc['title'],
            'summary_of_changes' => $doc['summary_of_changes'],
            'url' => $doc['page_slug'] ? url('/'.ltrim($doc['page_slug'], '/')) : null,
        ];

        return response()->json([
            'blocking' => array_map($shape, $blocking),
            'not_on_record' => array_map($shape, $notOnRecord),
        ]);
    }

    /** POST /api/privacy/consents {grant?: string[], withdraw?: string[]} */
    public function updateConsents(Request $request)
    {
        $data = $request->validate([
            'grant' => ['nullable', 'array'],
            'grant.*' => ['string', Rule::in(array_keys(Consent::PURPOSES))],
            'withdraw' => ['nullable', 'array'],
            'withdraw.*' => ['string', Rule::in(Consent::OPTIONAL)],
        ]);

        $user = $request->user();
        if (! empty($data['grant'])) {
            $this->consents->grant($user, $data['grant'], 'app', $request);
        }
        foreach ($data['withdraw'] ?? [] as $purpose) {
            $this->consents->withdraw($user, $purpose);
        }

        return response()->json(['data' => $this->consents->statusFor($user)]);
    }

    /** GET /api/privacy/requests */
    public function requests(Request $request)
    {
        $rows = DsrRequest::query()->where('user_id', $request->user()->id)->latest()->limit(50)->get()
            ->map(fn (DsrRequest $r) => $this->shape($r));

        return response()->json(['data' => $rows]);
    }

    /** POST /api/privacy/requests {type, details?} */
    public function storeRequest(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DsrRequest::TYPES))],
            'details' => [Rule::requiredIf(fn () => in_array($request->input('type'), [DsrRequest::TYPE_CORRECTION, DsrRequest::TYPE_OBJECTION], true)), 'nullable', 'string', 'max:5000'],
        ]);

        $dsr = $this->dsr->create($request->user(), $data['type'], $data['details'] ?? null, 'app');

        return response()->json(['data' => $this->shape($dsr)], 201);
    }

    /** GET /api/privacy/requests/{dsr}/download — owner only (token auth). */
    public function download(Request $request, DsrRequest $dsr)
    {
        abort_unless((int) $dsr->user_id === (int) $request->user()->id, 403);
        abort_unless($dsr->hasDownloadableExport(), 410, 'This download has expired.');

        $disk = Storage::disk(DataExportService::DISK);
        abort_unless($disk->exists($dsr->export_path), 410, 'This download has expired.');
        $ext = pathinfo($dsr->export_path, PATHINFO_EXTENSION) ?: 'json';

        return $disk->download($dsr->export_path, 'prompt-aid-my-data.'.$ext, ['Cache-Control' => 'no-store, private']);
    }

    /** GET /api/privacy/access-log — who opened my health record. */
    public function accessLog(Request $request)
    {
        $user = $request->user();
        $patientId = $user->patientProfile?->id;
        $labels = WebPrivacyController::roleLabels();

        $rows = $patientId
            ? DataAccessLog::query()->where('patient_profile_id', $patientId)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->with('user:id,name')->latest('created_at')->limit(100)->get()
                ->map(fn (DataAccessLog $l) => [
                    'when' => $l->created_at?->toIso8601String(),
                    'who' => $l->user?->name ?? 'System',
                    'role' => $labels[$l->role] ?? 'Staff',
                    'what' => $l->subjectLabel(),
                    'action' => $l->action,
                ])
            : collect();

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    protected function shape(DsrRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'details' => $r->details,
            'created_at' => $r->created_at?->toIso8601String(),
            'due_at' => $r->due_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'export_available' => $r->hasDownloadableExport(),
            'export_expires_at' => $r->export_expires_at?->toIso8601String(),
            'download_url' => $r->hasDownloadableExport() ? url('/api/privacy/requests/'.$r->id.'/download') : null,
        ];
    }
}
