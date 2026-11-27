<?php

namespace Tests\Feature;

use App\Enums\SendStatus;
use App\Mail\Newsletter;
use App\Mail\NewsletterWelcome;
use App\Models\NewsletterSend;
use App\Models\NewsletterSendItem;
use App\Models\Subscriber;
use App\Models\Work;
use App\Models\WorkItem;
use App\Support\NewsletterPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NewsletterSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'Africa/Lagos'));
    }

    private function novel(): Work
    {
        return Work::factory()->novel()->public()->published()->create(['title' => 'The Ember Gate']);
    }

    /** A chapter of the novel, published two days ago unless told otherwise. */
    private function chapter(Work $novel, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->chapter()->published()->for($novel)->create(array_merge([
            'published_at' => now()->subDays(2),
        ], $attributes));
    }

    /** A planned send that is due now (a minute ago), unless told otherwise. */
    private function dueSend(array $attributes = []): NewsletterSend
    {
        return NewsletterSend::factory()->create(array_merge([
            'scheduled_for' => now()->subMinute(),
            'status' => SendStatus::Planned,
        ], $attributes));
    }

    public function test_a_due_send_mails_each_confirmed_reader_with_something_for_them_and_plans_the_next(): void
    {
        Mail::fake();
        $this->chapter($this->novel());
        $novelReader = Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $bothReader = Subscriber::factory()->confirmed()->create(['kinds' => ['poems', 'novels']]);
        $poetryReader = Subscriber::factory()->confirmed()->create(['kinds' => ['poems']]);
        $unconfirmed = Subscriber::factory()->create(['kinds' => ['novels']]);
        $send = $this->dueSend();

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertQueued(Newsletter::class, 2);
        Mail::assertQueued(Newsletter::class, fn (Newsletter $mail): bool => $mail->hasTo($novelReader->email));
        Mail::assertQueued(Newsletter::class, fn (Newsletter $mail): bool => $mail->hasTo($bothReader->email));
        Mail::assertNotQueued(Newsletter::class, fn (Newsletter $mail): bool => $mail->hasTo($poetryReader->email)
            || $mail->hasTo($unconfirmed->email));

        $send->refresh();
        $this->assertSame(SendStatus::Sent, $send->status);
        $this->assertSame(2, $send->sent_count);
        $this->assertSame(now()->toIso8601String(), $send->sent_at->toIso8601String());

        $next = NewsletterSend::query()->where('status', SendStatus::Planned)->sole();
        $this->assertSame('2026-12-06 08:00:00', $next->scheduled_for->utc()->toDateTimeString());
    }

    public function test_a_send_with_everything_left_out_is_skipped_and_mails_nobody(): void
    {
        Mail::fake();
        $chapter = $this->chapter($this->novel());
        Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $send = $this->dueSend();
        NewsletterSendItem::factory()->leftOut()->create([
            'newsletter_send_id' => $send->id,
            'item_type' => $chapter->getMorphClass(),
            'item_id' => $chapter->id,
            'position' => 1,
        ]);

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(SendStatus::Skipped, $send->refresh()->status);

        /* what she left out stays out: it doesn't come back in the next week's send */
        $next = NewsletterSend::query()->where('status', SendStatus::Planned)->sole();
        $this->assertFalse($next->items()->where('item_id', $chapter->id)->exists());
    }

    public function test_a_held_send_stays_held_and_its_items_go_in_the_next_send(): void
    {
        Mail::fake();
        $chapter = $this->chapter($this->novel());
        Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $held = $this->dueSend(['status' => SendStatus::Held]);
        NewsletterPlan::sync($held);

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(SendStatus::Held, $held->refresh()->status);

        $next = NewsletterSend::query()->where('status', SendStatus::Planned)->sole();
        $this->assertTrue($next->items()->where('item_id', $chapter->id)->where('left_out', false)->exists());
    }

    public function test_running_twice_does_not_send_twice(): void
    {
        Mail::fake();
        $this->chapter($this->novel());
        Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $this->dueSend();

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();
        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertQueued(Newsletter::class, 1);
    }

    public function test_a_send_more_than_a_day_late_is_skipped_not_sent(): void
    {
        Mail::fake();
        $this->chapter($this->novel());
        Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $send = $this->dueSend(['scheduled_for' => now()->subHours(25)]);

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(SendStatus::Skipped, $send->refresh()->status);
    }

    public function test_confirming_queues_the_welcome_once_and_the_reused_link_finds_nothing(): void
    {
        Mail::fake();
        $token = Str::random(48);
        $subscriber = Subscriber::factory()->create([
            'email' => 'ada@example.com',
            'kinds' => ['novels'],
            'confirm_token_hash' => hash('sha256', $token),
            'confirmed_at' => null,
        ]);

        $this->get('/emails/confirm/'.$token)->assertRedirect('/emails/'.$subscriber->manage_token);
        $this->get('/emails/confirm/'.$token)->assertNotFound();

        Mail::assertQueued(NewsletterWelcome::class, 1);
        Mail::assertQueued(NewsletterWelcome::class, fn (NewsletterWelcome $mail): bool => $mail->hasTo('ada@example.com')
            && $mail->headers()->text === ['List-Unsubscribe' => '<'.rtrim(config('app.url'), '/').'/emails/'.$subscriber->manage_token.'>']);
    }

    public function test_the_newsletter_carries_a_list_unsubscribe_header_to_the_readers_manage_page(): void
    {
        Mail::fake();
        $this->chapter($this->novel());
        $reader = Subscriber::factory()->confirmed()->create(['kinds' => ['novels']]);
        $this->dueSend();

        $this->artisan('eyiwetemi:newsletter-send')->assertSuccessful();

        Mail::assertQueued(Newsletter::class, fn (Newsletter $mail): bool => $mail->hasTo($reader->email)
            && $mail->headers()->text === ['List-Unsubscribe' => '<'.rtrim(config('app.url'), '/').'/emails/'.$reader->manage_token.'>']);
    }
}
