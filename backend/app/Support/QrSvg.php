<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Server-side QR codes as inline SVG, using chillerlan/php-qrcode (pure PHP,
 * already installed via Filament's two-factor dependency). Returns null if
 * the library is missing so callers can fall back to a client-side render.
 */
class QrSvg
{
    public static function available(): bool
    {
        return class_exists(\chillerlan\QRCode\QRCode::class) && class_exists(\chillerlan\QRCode\Output\QRMarkupSVG::class);
    }

    public static function make(string $data): ?string
    {
        if (! static::available()) {
            return null;
        }

        try {
            return Cache::remember('qrsvg:'.md5($data), 86400, function () use ($data) {
                $options = new \chillerlan\QRCode\QROptions([
                    'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                    'outputBase64' => false,
                    'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
                    'addQuietzone' => true,
                    'quietzoneSize' => 2,
                    'svgAddXmlHeader' => false,
                    'drawLightModules' => false,
                    'connectPaths' => true,
                ]);

                return (new \chillerlan\QRCode\QRCode($options))->render($data);
            });
        } catch (\Throwable $e) {
            Log::warning('[QrSvg] render failed: '.$e->getMessage());

            return null;
        }
    }
}
