<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Driver compliance documents from the app — upload, list, withdraw a
 * pending one. Admins review them on the Driver Profile page.
 */
class DriverDocumentController extends Controller
{
    protected function driver(Request $request): DriverProfile
    {
        $driver = $request->user()->driverProfile;
        abort_unless($driver, 403, 'No driver profile on this account.');

        return $driver;
    }

    public function index(Request $request)
    {
        $docs = $this->driver($request)->documents()->get()->map(fn (DriverDocument $d) => $this->payload($d));

        return response()->json([
            'data' => $docs->values(),
            'types' => collect(DriverDocument::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $driver = $this->driver($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DriverDocument::TYPES))],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:10240'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $file = $request->file('file');
        $doc = $driver->documents()->create([
            'type' => $data['type'],
            'path' => $file->store("driver-documents/{$driver->id}", 'public'),
            'original_name' => mb_substr($file->getClientOriginalName() ?: 'document', 0, 250),
            'expires_at' => $data['expires_at'] ?? null,
            'status' => 'pending',
        ]);

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                'Driver document uploaded',
                "{$request->user()->name} uploaded: {$doc->typeLabel()}.",
                icon: 'heroicon-o-document-arrow-up',
                color: 'warning',
            );
        }

        return response()->json(['data' => $this->payload($doc)], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $doc = $this->driver($request)->documents()->where('status', 'pending')->findOrFail($id);
        Storage::disk('public')->delete($doc->path);
        $doc->delete();

        return response()->json(['deleted' => true]);
    }

    protected function payload(DriverDocument $d): array
    {
        return [
            'id' => $d->id,
            'type' => $d->type,
            'type_label' => $d->typeLabel(),
            'original_name' => $d->original_name,
            'expires_at' => $d->expires_at?->format('Y-m-d'),
            'status' => $d->status,
            'review_note' => $d->review_note,
            'uploaded_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
