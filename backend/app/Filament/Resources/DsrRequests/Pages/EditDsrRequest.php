<?php

namespace App\Filament\Resources\DsrRequests\Pages;

use App\Contracts\NotificationDispatcherInterface;
use App\Filament\Resources\DsrRequests\DsrRequestResource;
use App\Models\DsrRequest;
use App\Services\Privacy\AccountAnonymiser;
use App\Services\Privacy\DataExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDsrRequest extends EditRecord
{
    protected static string $resource = DsrRequestResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['handled_by'] = Auth::id();

        if (in_array($data['status'] ?? null, [DsrRequest::STATUS_COMPLETED, DsrRequest::STATUS_REJECTED], true) && ! $this->record->completed_at) {
            $data['completed_at'] = now();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateExport')
                ->label(fn (DsrRequest $record) => $record->export_path ? 'Regenerate export' : 'Generate export')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (DsrRequest $record) => $record->type === DsrRequest::TYPE_ACCESS && $record->user_id)
                ->requiresConfirmation()
                ->modalDescription('Builds a JSON/ZIP of everything held about this user. The user downloads it from their privacy page; it expires automatically.')
                ->action(function (DsrRequest $record): void {
                    try {
                        app(DataExportService::class)->generate($record);
                        $record->forceFill(['handled_by' => Auth::id()])->save();
                        $this->notifyUser($record, 'Your Prompt Aid data export is ready', 'Your copy of the personal information we hold is ready. Sign in and open Account → Your data & consents to download it.');
                        $this->refreshFormData(['status', 'export_path']);
                        Notification::make()->title('Export generated')->success()->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Could not generate export')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('approveDeletion')
                ->label('Approve & anonymise')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->visible(fn (DsrRequest $record) => $record->type === DsrRequest::TYPE_DELETION && ! $record->anonymised_at && $record->user_id)
                ->requiresConfirmation()
                ->modalHeading('Anonymise this account?')
                ->modalDescription('Removes login, contact details, addresses, ID uploads, notifications and consents now. Clinical records are kept (HPCSA retention) and anonymised automatically when the retention period ends; billing records are kept. This cannot be undone.')
                ->action(function (DsrRequest $record): void {
                    try {
                        // Capture contact details BEFORE they are erased, to confirm completion.
                        $user = $record->user;
                        $email = $user?->email;
                        $name = $user?->name;

                        $result = app(AccountAnonymiser::class)->anonymise($record, Auth::user());

                        if ($email) {
                            try {
                                $transient = new \App\Models\User(['name' => $name, 'email' => $email]);
                                app(NotificationDispatcherInterface::class)->email(
                                    $transient,
                                    'Your Prompt Aid account has been deleted',
                                    "Hi {$name},\n\nYour account deletion request has been completed. Your login and contact details have been removed."
                                    .($result['retain_until'] ? "\n\nBy law we must keep your clinical records until {$result['retain_until']}; they will then be anonymised automatically." : '')
                                    ."\n\nThis is the last email you will receive from us.",
                                );
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        }

                        $this->refreshFormData(['status', 'retention_summary']);
                        Notification::make()->title('Account anonymised')->body(count($result['removed']).' categories removed, '.count($result['kept']).' retained.')->success()->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title('Could not anonymise')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (DsrRequest $record) => $record->isOpen())
                ->schema([
                    Textarea::make('reason')->label('Reason (shown to the data subject)')->required()->rows(3),
                ])
                ->action(function (array $data, DsrRequest $record): void {
                    $record->forceFill([
                        'status' => DsrRequest::STATUS_REJECTED,
                        'notes' => trim(($record->notes ? $record->notes."\n\n" : '').$data['reason']),
                        'handled_by' => Auth::id(),
                        'completed_at' => now(),
                    ])->save();
                    $this->notifyUser($record, 'Update on your Prompt Aid privacy request', "We could not complete your request.\n\nReason: {$data['reason']}\n\nYou may contact our Information Officer or complain to the Information Regulator.");
                    $this->refreshFormData(['status', 'notes']);
                    Notification::make()->title('Request rejected')->success()->send();
                }),
        ];
    }

    protected function notifyUser(DsrRequest $record, string $subject, string $body): void
    {
        try {
            if ($record->user && ! str_ends_with((string) $record->user->email, '@anonymised.invalid')) {
                app(NotificationDispatcherInterface::class)->email($record->user, $subject, $body);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
