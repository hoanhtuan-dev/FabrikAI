<?php

namespace AppJobs;

use AppServicesStudioLibraryService;
use IlluminateBusQueueable;
use IlluminateContractsQueueShouldQueue;
use IlluminateFoundationBusDispatchable;
use IlluminateQueueInteractsWithQueue;
use IlluminateQueueSerializesModels;
use IlluminateSupportFacadesStorage;

class CleanOrphanFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $days = 14, public bool $dryRun = false) {}

    public function handle(StudioLibraryService $library): void
    {
        $cutoff = now()->subDays($this->days);
        $orphans = $library->scanOrphanFiles($cutoff);

        foreach ($orphans as $file => $size) {
            if ($this->dryRun) {
                logger()->info('Orphan file (dry-run): '.$file.' ('.$size.' bytes)');
                continue;
            }
            Storage::disk('public')->delete($file);
            logger()->info('Deleted orphan: '.$file);
        }
    }
}
