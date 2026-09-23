<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Browses the "public" disk's page-builder/ and theme/ directories — the
 * only two things anything on the site actually references by path
 * (ImageBlock, ThemeSettings' logo/favicon). Deliberately does not expose
 * prescriptions/ or other patient-uploaded directories here; those hold
 * real patient data and are reached through their own clinical workflows,
 * not a general media browser.
 */
class MediaLibrary extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Media Library';

    protected string $view = 'filament.pages.media-library';

    public function getFiles(): array
    {
        $files = [];

        foreach (['page-builder', 'theme'] as $dir) {
            foreach (Storage::disk('public')->files($dir) as $path) {
                $files[] = [
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'size' => Storage::disk('public')->size($path),
                ];
            }
        }

        return $files;
    }

    public function deleteFile(string $path): void
    {
        if (! str_starts_with($path, 'page-builder/') && ! str_starts_with($path, 'theme/')) {
            abort(403);
        }

        Storage::disk('public')->delete($path);

        Notification::make()->title('File deleted')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload image')
                ->schema([
                    FileUpload::make('file')->image()->disk('public')->directory('page-builder')->required(),
                ])
                ->action(function (array $data): void {
                    Notification::make()->title('Image uploaded')->success()->send();
                }),
        ];
    }
}
