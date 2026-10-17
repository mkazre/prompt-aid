<?php

namespace App\Filament\Resources\RideRecordings\Pages;

use App\Filament\Resources\RideRecordings\RideRecordingActions;
use App\Filament\Resources\RideRecordings\RideRecordingResource;
use App\Filament\Resources\Rides\RideResource;
use App\Models\RideRecording;
use App\Services\Safety\RideRecordingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * One trip recording: inline player (signed URLs issued to the viewing staff
 * member), download, evidence hash + per-piece manifest, access log, and
 * legal hold / release / delete.
 */
class ViewRideRecording extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RideRecordingResource::class;

    protected string $view = 'filament.resources.ride-recordings.view-ride-recording';

    /** Signed media URLs, generated once on mount (re-renders must not reset the players). */
    public array $media = [];

    public static function canAccess(array $parameters = []): bool
    {
        return RideRecordingResource::canViewAny();
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::canAccess(['record' => $this->record]), 403);
        $this->media = app(RideRecordingService::class)->present($this->recording()->loadMissing('user'), Auth::user());
    }

    public function recording(): RideRecording
    {
        /** @var RideRecording $r */
        $r = $this->getRecord();

        return $r;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Recording #'.$this->recording()->id.' · '.($this->recording()->ride?->ride_ref ?? 'ride');
    }

    protected function getHeaderActions(): array
    {
        $rec = fn () => $this->recording()->fresh();

        return [
            Action::make('download')
                ->label(fn () => $this->recording()->layout === RideRecording::LAYOUT_SEGMENTS && $this->recording()->chunk_count > 1 ? 'Download (zip + manifest)' : 'Download')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => ! empty($this->media['download_url']))
                ->url(fn () => app(RideRecordingService::class)->signedUrl($this->recording(), Auth::user(), null, true))
                ->openUrlInNewTab(),
            Action::make('ride')
                ->label('Open ride')
                ->icon('heroicon-o-truck')
                ->color('gray')
                ->visible(fn () => $this->recording()->ride_id && RideResource::canViewAny())
                ->url(fn () => RideResource::getUrl('view', ['record' => $this->recording()->ride_id])),
            RideRecordingActions::hold($rec),
            RideRecordingActions::release($rec),
            RideRecordingActions::delete($rec, 'deleteRecording', fn () => $this->redirect(RideRecordingResource::getUrl('index'))),
        ];
    }
}
