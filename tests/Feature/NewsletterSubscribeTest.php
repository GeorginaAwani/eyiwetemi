<?php

namespace Tests\Feature;

use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterSubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_email_waits_unconfirmed_with_the_kinds_it_picked(): void
    {
        $this->from('/')->post('/newsletter', ['email' => ' Ada@Example.com ', 'kinds' => ['notes', 'novels']])
            ->assertRedirect('/');

        $subscriber = Subscriber::query()->sole();
        $this->assertSame('ada@example.com', $subscriber->email);
        $this->assertSame(['novels', 'notes'], $subscriber->kinds);
        $this->assertNull($subscriber->confirmed_at);
        $this->assertNotNull($subscriber->confirm_token_hash);
        $this->assertNotEmpty($subscriber->manage_token);
    }

    public function test_an_address_already_on_the_list_is_left_as_it_is(): void
    {
        $subscriber = Subscriber::factory()->confirmed()->create(['email' => 'ada@example.com', 'kinds' => ['poems']]);

        $this->from('/')->post('/newsletter', ['email' => 'ada@example.com', 'kinds' => ['novels']])
            ->assertRedirect('/');

        $this->assertSame(['poems'], $subscriber->fresh()->kinds);
        $this->assertSame(1, Subscriber::query()->count());
    }

    public function test_it_needs_a_real_email_and_at_least_one_known_kind(): void
    {
        $this->from('/')->post('/newsletter', ['email' => 'not an email', 'kinds' => []])
            ->assertSessionHasErrors(['email', 'kinds']);

        $this->from('/')->post('/newsletter', ['email' => 'ada@example.com', 'kinds' => ['gossip']])
            ->assertSessionHasErrors(['kinds.0']);

        $this->assertSame(0, Subscriber::query()->count());
    }
}
