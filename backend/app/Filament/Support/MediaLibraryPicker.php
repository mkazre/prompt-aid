<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Storage;

/**
 * Backs the "Choose from library" control attached to image FileUploads
 * across the admin (page builder blocks, clinic/pharmacy branding, avatars,
 * theme logo). Deliberately self-contained rather than depending on
 * App\Filament\Pages\MediaLibrary, whose view is being redesigned separately
 * — same underlying disk/directories, duplicated on purpose.
 */
class MediaLibraryPicker
{
    public const DIRECTORIES = [
        'page-builder',
        'theme',
        'clinics/logos',
        'clinics/covers',
        'pharmacies/logos',
        'avatars',
    ];

    /**
     * @return array<int, array{path: string, url: string, name: string}>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::DIRECTORIES as $directory) {
            foreach (Storage::disk('public')->files($directory) as $path) {
                $files[] = [
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'name' => basename($path),
                ];
            }
        }

        return $files;
    }

    /**
     * A sibling schema component for the FileUpload named $fieldName — call
     * it with the same name so the picker's "Use this" click writes to the
     * same state path (repeater indices and all, resolved at render time).
     */
    public static function for(string $fieldName): View
    {
        return View::make('filament.forms.components.media-library-picker')
            ->statePath($fieldName)
            ->key($fieldName.'-media-library-picker');
    }
}
