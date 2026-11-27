<?php

namespace App\Console\Commands;

use App\Support\Publisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eyiwetemi:publish-due')]
#[Description('Publish the chapters, pieces and notes whose scheduled time has come')]
class PublishDue extends Command
{
    /**
     * They come out now (not at the scheduled minute), so the announcer, which runs next, tells their readers.
     */
    public function handle(): int
    {
        $count = Publisher::publishDue();

        if ($count > 0) {
            $this->info("Published {$count}.");
        }

        return self::SUCCESS;
    }
}
