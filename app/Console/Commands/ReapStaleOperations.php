<?php

namespace App\Console\Commands;

use App\Services\Operations\StaleOperationReaper;
use Illuminate\Console\Command;

/**
 * Releases operations that were abandoned by a dead queue worker.
 *
 * Safe to run from cron every few minutes on hosts without a process
 * supervisor. It only touches operations older than the configured threshold.
 */
class ReapStaleOperations extends Command
{
    protected $signature = "control:reap {--force : Release every active operation regardless of age}
        {--dry-run : Report what would be released without changing anything}";

    protected $description = "Release project locks left behind by dead operations";

    public function handle(StaleOperationReaper $reaper): int
    {
        $dryRun = (bool) $this->option("dry-run");
        if ($dryRun) {
            $this->info(
                "Threshold: " . $reaper->thresholdSeconds() . "s. Dry run: nothing is changed.",
            );
        }

        $released = $reaper->reap(null, (bool) $this->option("force"), null, $dryRun);
        if ($released === []) {
            $this->info("No stale operations found.");
            return 0;
        }
        foreach ($released as $line) {
            $this->warn($line);
        }
        return 0;
    }
}
