<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Support\Announcer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('eyiwetemi:announce')]
#[Description('Tell readers about chapters, works and notes that just came out')]
class AnnounceReleases extends Command
{
    /**
     * The first run only starts the clock, so existing content never floods readers.
     * Later runs announce everything released since the last run.
     */
    public function handle(Announcer $announcer): int
    {
        $now = now();
        $until = SiteSetting::get('announce.until');

        if ($until === null) {
            SiteSetting::put('announce.until', $now->toIso8601String());

            return self::SUCCESS;
        }

        $announcer->announce(Carbon::parse($until), $now);
        SiteSetting::put('announce.until', $now->toIso8601String());

        return self::SUCCESS;
    }
}
