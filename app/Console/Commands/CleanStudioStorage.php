<?php

namespace App\Console\Commands;

use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanStudioStorage extends Command
{
    protected $signature = 'studio:clean-storage {--days=14 : minimum age in days for a file to be deleted} {--dry-run : only report, do not delete}';

    protected $description = 'Delete intermediate/orphan files in storage/app/public/studio that are not referenced by any generation.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // 1) Collect every path referenced by generations (final results must never be deleted).
        $referenced = [];
        Generation::query()->select(['media_url', 'base_image', 'mask_image'])->chunk(500, function ($gens) use (&$referenced) {
            foreach ($gens as $g) {
                foreach (['media_url', 'base_image', 'mask_image'] as $field) {
                    $url = (string) ($g->{$field} ?? '');
                    if ($url === '') {
                        continue;
                    }
                    $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                    // Normalize: /storage/studio/x.png  |  /public_html/storage/studio/x.png  ->  studio/x.png
                    $path = preg_replace('#^(public_html/)?(storage/)?#', '', $path);
                    $referenced[$path] = true;
                }
            }
        });
        $this->info('Referenced paths: '.count($referenced));

        $disk = Storage::disk('public');
        $dir = 'studio';
        $protectedDirs = ['studio/dang-nguoi-mau', 'studio/khuon-mat', 'studio/assets', 'studio/ref', 'studio/logo'];
        $protectedPrefixes = ['background-', 'ref/'];

        $deleted = 0; $bytes = 0; $orphan = 0; $skipped = 0;
        $files = $disk->allFiles($dir);

        foreach ($files as $file) {
            // Skip protected dirs / prefixes (user resources, not intermediates).
            $skip = false;
            foreach ($protectedDirs as $pd) {
                if (str_starts_with($file, $pd.'/')) { $skip = true; break; }
            }
            foreach ($protectedPrefixes as $pfx) {
                if (str_starts_with(basename($file), $pfx)) { $skip = true; break; }
            }
            if ($skip) { $skipped++; continue; }

            // Keep anything still referenced by a generation.
            if (isset($referenced[$file]) || isset($referenced['storage/'.$file])) { $skipped++; continue; }

            // Only touch files older than the cutoff.
            if ($disk->lastModified($file) > $cutoff->timestamp) { $skipped++; continue; }

            $size = $disk->size($file);
            if ($dry) {
                $orphan++; $bytes += $size;
                if ($orphan <= 40) { $this->line('  would delete: '.$file); }
                continue;
            }
            if ($disk->delete($file)) { $deleted++; $bytes += $size; }
        }

        $this->info(sprintf(
            'Studio cleanup%s: %d deleted (%.1f MB), %d orphaned, %d skipped (protected/referenced/recent).',
            $dry ? ' (dry-run)' : '',
            $dry ? $orphan : $deleted,
            $bytes / 1048576,
            $orphan,
            $skipped
        ));

        return 0;
    }
}
