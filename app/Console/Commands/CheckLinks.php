<?php

namespace App\Console\Commands;

use App\Support\SitePages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eyiwetemi:check-links')]
#[Description('Re-check every store and pre-order link on the live In print page')]
class CheckLinks extends Command
{
    public function handle(): int
    {
        $addresses = SitePages::liveStoreAddresses();

        foreach ($addresses as $address) {
            SitePages::checkLink($address);
        }

        $this->info('Checked '.count($addresses).' links.');

        return self::SUCCESS;
    }
}
