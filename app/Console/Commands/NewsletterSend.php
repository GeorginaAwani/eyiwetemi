<?php

namespace App\Console\Commands;

use App\Enums\SendStatus;
use App\Mail\Newsletter;
use App\Models\NewsletterSend as Send;
use App\Models\Subscriber;
use App\Support\NewsletterEmail;
use App\Support\NewsletterPlan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('eyiwetemi:newsletter-send')]
#[Description('Send the weekly newsletter to its confirmed readers when its time has come')]
class NewsletterSend extends Command
{
    /** A send this late is skipped rather than sent: a stale letter is not worth the reader's inbox. */
    private const LATE_HOURS = 24;

    /** Readers loaded at a time, so a long list is never held in memory at once. */
    private const CHUNK = 200;

    /**
     * Sends the oldest planned send that is due, one per run. A held send is never picked (it stays held, and its items roll
     * into the next send). Afterwards there is always an upcoming send planned.
     */
    public function handle(): int
    {
        $due = Send::query()
            ->where('status', SendStatus::Planned)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->first();

        if ($due !== null) {
            $this->deliver($due);
        }

        if (NewsletterPlan::upcoming() === null) {
            NewsletterPlan::next();
        }

        return self::SUCCESS;
    }

    /**
     * Sends one planned send, or skips it when it is stale or has nothing left in. The send is claimed (marked Sending) just
     * before anything is queued, so a second run cannot send it again.
     */
    private function deliver(Send $send): void
    {
        if ($send->scheduled_for->lt(now()->subHours(self::LATE_HOURS))) {
            $send->forceFill(['status' => SendStatus::Skipped])->save();

            return;
        }

        NewsletterPlan::sync($send);

        if (! $send->items()->where('left_out', false)->exists()) {
            $send->forceFill(['status' => SendStatus::Skipped])->save();

            return;
        }

        $claimed = Send::query()
            ->whereKey($send->id)
            ->where('status', SendStatus::Planned)
            ->update(['status' => SendStatus::Sending]);

        if ($claimed === 0) {
            return;
        }

        $queued = 0;

        foreach (Subscriber::query()->confirmed()->lazyById(self::CHUNK) as $subscriber) {
            $email = NewsletterEmail::send($send, $subscriber);

            if ($email === null) {
                continue;
            }

            Mail::to($subscriber->email)->queue(new Newsletter($email));
            $queued++;
        }

        $send->forceFill(['status' => SendStatus::Sent, 'sent_at' => now(), 'sent_count' => $queued])->save();
    }
}
