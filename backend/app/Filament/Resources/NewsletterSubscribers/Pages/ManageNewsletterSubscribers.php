<?php

namespace App\Filament\Resources\NewsletterSubscribers\Pages;

use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Models\NewsletterSubscriber;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageNewsletterSubscribers extends ManageRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->schema([
                    Toggle::make('include_unsubscribed')->label('Include unsubscribed people')->default(false),
                ])
                ->action(fn (array $data) => $this->exportCsv((bool) ($data['include_unsubscribed'] ?? false))),
            CreateAction::make()->label('Add subscriber')
                ->mutateDataUsing(fn (array $data) => $data + ['source' => 'admin', 'consented_at' => now()]),
        ];
    }

    /** Small streamed download — no queued export needed for a mailing list. */
    protected function exportCsv(bool $includeUnsubscribed): StreamedResponse
    {
        $filename = 'promptaid-newsletter-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($includeUnsubscribed) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'status', 'source', 'consented_at', 'unsubscribed_at', 'unsubscribe_url']);

            NewsletterSubscriber::query()
                ->when(! $includeUnsubscribed, fn ($q) => $q->whereNull('unsubscribed_at'))
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $s) {
                        fputcsv($out, [
                            $this->csvSafe($s->email),
                            $this->csvSafe((string) $s->name),
                            $s->isSubscribed() ? 'subscribed' : 'unsubscribed',
                            $s->source,
                            $s->consented_at?->toDateTimeString(),
                            $s->unsubscribed_at?->toDateTimeString(),
                            $s->unsubscribeUrl(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection. */
    protected function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
