<?php

namespace App\Console\Commands;

use App\Jobs\Privacy\RetentionJobs;
use Illuminate\Console\Command;

/**
 * Runs the POPIA retention sweeps by hand (they normally run hourly via
 * Tick). Each run is bounded; re-run to clear a backlog.
 */
class PrivacyRetention extends Command
{
    protected $signature = 'privacy:retention {job? : Run only this job, e.g. privacy.purge_notifications} {--list : List job names}';

    protected $description = 'Run POPIA retention / anonymisation jobs';

    public function handle(): int
    {
        if ($this->option('list')) {
            foreach (RetentionJobs::JOBS as $name => $class) {
                $this->line($name.'  ('.class_basename($class).')');
            }

            return self::SUCCESS;
        }

        foreach (RetentionJobs::runAll($this->argument('job')) as $name => $result) {
            $this->line(str_pad($name, 42).' '.$result);
        }

        return self::SUCCESS;
    }
}
