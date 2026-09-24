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

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('media-library.view');
    }

    public function getFiles(): array
    {
        $files = [];

        foreach (['page-builder', 'theme'] as $dir) {
            foreach (Storage::disk('public')->files($dir) as $path) {
                $files[] = [
                    'path' => $path,
                    // Cache-bust on the file's mtime so a crop (which
                    // overwrites the same path) shows immediately instead
                    // of the browser's cached copy of the old crop.
                    'url' => Storage::disk('public')->url($path).'?v='.Storage::disk('public')->lastModified($path),
                    'size' => Storage::disk('public')->size($path),
                ];
            }
        }

        return $files;
    }

    public function deleteFile(string $path): void
    {
        $this->assertManaged($path);

        Storage::disk('public')->delete($path);

        Notification::make()->title('File deleted')->success()->send();
    }

    /**
     * Crop in place: (x, y, width, height) are pixel coordinates in the
     * original image, as produced by Cropper.js's getData(true).
     */
    public function cropFile(string $path, int $x, int $y, int $width, int $height): void
    {
        $this->assertManaged($path);

        if (! extension_loaded('gd') || $width < 1 || $height < 1) {
            Notification::make()->title('Could not crop image')->danger()->send();

            return;
        }

        $fullPath = Storage::disk('public')->path($path);
        $source = @imagecreatefromstring((string) file_get_contents($fullPath));

        if (! $source) {
            Notification::make()->title('Could not read image')->danger()->send();

            return;
        }

        $cropped = imagecrop($source, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);

        if ($cropped === false) {
            imagedestroy($source);
            Notification::make()->title('Crop failed')->danger()->send();

            return;
        }

        ob_start();

        match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => imagepng($cropped),
            'webp' => imagewebp($cropped),
            'gif' => imagegif($cropped),
            default => imagejpeg($cropped, null, 90),
        };

        Storage::disk('public')->put($path, (string) ob_get_clean());

        imagedestroy($source);
        imagedestroy($cropped);

        Notification::make()->title('Image cropped')->success()->send();
    }

    protected function assertManaged(string $path): void
    {
        if (! str_starts_with($path, 'page-builder/') && ! str_starts_with($path, 'theme/')) {
            abort(403);
        }
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
