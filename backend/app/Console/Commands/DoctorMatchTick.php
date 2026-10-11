<?php

namespace App\Console\Commands;

use App\Jobs\Matching\ExpireDoctorMatches;
use Illuminate\Console\Command;

class DoctorMatchTick extends Command
{
    protected $signature = 'doctor-match:tick';

    protected $description = 'Progress on-demand doctor requests (offer timeouts, re-offers, expiries, waitlist).';

    public function handle(): int
    {
        $n = (new ExpireDoctorMatches)();
        $this->info("Examined {$n} open request(s).");

        return self::SUCCESS;
    }
}
