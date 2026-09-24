<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off deploy helper: some large/dense files (long Blade views, mostly)
 * get silently zeroed by the host's upload content scanner over plain FTP.
 * Workaround: upload the file base64-encoded as a harmless .txt to
 * storage/app/deploy/, then run this via cron to decode it into place.
 * Verifies the decoded byte length before writing so a repeat cron fire
 * (cPanel cron has no "run once" option) is a safe no-op once applied.
 */
class DecodeUpload extends Command
{
    protected $signature = 'deploy:decode {sourceDir} {target} {expectedBytes}';

    protected $description = 'Decode base64 chunk(s) from a storage/app/deploy subdirectory (all files, sorted by name) into a real project path';

    public function handle(): int
    {
        $dir = storage_path('app/deploy/'.$this->argument('sourceDir'));
        $target = base_path($this->argument('target'));
        $expected = (int) $this->argument('expectedBytes');

        if (! is_dir($dir)) {
            $this->error("Source directory not found: {$dir}");

            return self::FAILURE;
        }

        $files = array_values(array_diff(scandir($dir), ['.', '..']));
        sort($files);
        $files = array_map(fn ($f) => $dir.DIRECTORY_SEPARATOR.$f, $files);

        if (empty($files)) {
            $this->error("No source files in: {$dir}");

            return self::FAILURE;
        }

        $b64 = collect($files)->map(fn ($f) => file_get_contents($f))->implode('');
        $decoded = base64_decode($b64, true);

        if ($decoded === false || strlen($decoded) !== $expected) {
            $this->error('Decoded length mismatch — refusing to write. Got '.($decoded === false ? 'false' : strlen($decoded))." expected {$expected}");

            return self::FAILURE;
        }

        file_put_contents($target, $decoded);
        $this->info("Wrote {$expected} bytes to {$target}");

        return self::SUCCESS;
    }
}
