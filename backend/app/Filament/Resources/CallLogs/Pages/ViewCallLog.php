<?php

namespace App\Filament\Resources\CallLogs\Pages;

use App\Filament\Resources\CallLogs\CallLogResource;
use App\Models\CallLog;
use App\Models\CallRecording;
use App\Services\PracticeCalls\CallRecordingService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/** One call: parties, timeline, booking, recordings (signed players), access log, legal hold / delete. */
class ViewCallLog extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CallLogResource::class;

    protected string $view = 'filament.resources.call-logs.view-call-log';

    /** @var array<int, array{play: ?string, download: ?string}> signed URLs per recording id, made once on mount */
    public array $media = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(CallLogResource::canView($this->record), 404);
        $svc = app(CallRecordingService::class);
        foreach ($this->call()->recordings as $r) {
            $ok = $r->isPlayable() && $svc->canAccess($r, Auth::user());
            $this->media[$r->id] = ['play' => $ok ? $svc->signedUrl($r) : null, 'download' => $ok ? $svc->signedUrl($r, true) : null];
        }
    }

    public function call(): CallLog
    {
        /** @var CallLog $c */
        $c = $this->getRecord();

        return $c->loadMissing(['patientUser', 'clinic', 'doctor.user', 'practiceUser', 'endedBy', 'appointment', 'recordings.accessLogs.user']);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Call #'.$this->call()->id;
    }

    protected function canManage(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('call-recordings.manage'));
    }

    protected function getHeaderActions(): array
    {
        $rec = fn (): ?CallRecording => $this->call()->recordings()->whereIn('status', [CallRecording::STORED])->latest('id')->first();
        $appt = $this->call()->appointment_id;

        return [
            Action::make('evidence_pdf')->label('Consultation evidence (PDF)')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn () => (bool) $appt)->url(fn () => route('consult-evidence.download', ['appointment' => $appt, 'format' => 'pdf']), true),
            Action::make('evidence_csv')->label('CSV')->icon('heroicon-o-table-cells')->color('gray')
                ->visible(fn () => (bool) $appt)->url(fn () => route('consult-evidence.download', ['appointment' => $appt, 'format' => 'csv']), true),
            Action::make('hold')->label('Legal hold')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn () => $this->canManage() && ($r = $rec()) && ! $r->legal_hold)
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(function (array $data) use ($rec) {
                    app(CallRecordingService::class)->setHold($rec(), Auth::user(), true, $data['reason']);
                    Notification::make()->title('Recording placed on legal hold')->success()->send();
                }),
            Action::make('release')->label('Release hold')->icon('heroicon-o-lock-open')->color('gray')
                ->visible(fn () => $this->canManage() && ($r = $rec()) && $r->legal_hold)->requiresConfirmation()
                ->action(function () use ($rec) {
                    app(CallRecordingService::class)->setHold($rec(), Auth::user(), false);
                    Notification::make()->title('Hold released')->success()->send();
                }),
            Action::make('delete_rec')->label('Delete recording')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => $this->canManage() && ($r = $rec()) && ! $r->legal_hold)->requiresConfirmation()
                ->action(function () use ($rec) {
                    $ok = app(CallRecordingService::class)->deleteRecording($rec(), Auth::user());
                    $ok ? Notification::make()->title('Recording deleted')->success()->send() : Notification::make()->title('Could not delete the recording (storage error or legal hold)')->danger()->send();
                }),
        ];
    }
}
