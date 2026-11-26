<?php

namespace Tests\Feature;

use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsletterEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_confirmation_link_confirms_once_and_opens_what_you_get(): void
    {
        $token = Str::random(48);
        $subscriber = Subscriber::factory()->create(['confirm_token_hash' => hash('sha256', $token), 'confirmed_at' => null]);

        $this->get('/emails/confirm/'.$token)->assertRedirect('/emails/'.$subscriber->manage_token);

        $this->assertNotNull($subscriber->fresh()->confirmed_at);
        $this->get('/emails/confirm/'.$token)->assertNotFound();
    }

    public function test_what_you_get_shows_and_saves_the_kinds(): void
    {
        $subscriber = Subscriber::factory()->confirmed()->create(['email' => 'ada@example.com', 'kinds' => ['novels']]);
        $page = '/emails/'.$subscriber->manage_token;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Emails')->where('email', 'ada@example.com')->where('kinds', ['novels']));

        $this->from($page)->put($page, ['kinds' => ['notes', 'poems']])->assertRedirect($page);
        $this->assertSame(['poems', 'notes'], $subscriber->fresh()->kinds);

        $this->from($page)->put($page, ['kinds' => []])->assertSessionHasErrors('kinds');
        $this->from($page)->put($page, ['kinds' => ['gossip']])->assertSessionHasErrors('kinds.0');
    }

    public function test_stopping_deletes_the_address_and_says_so_once(): void
    {
        $subscriber = Subscriber::factory()->confirmed()->create(['email' => 'ada@example.com']);

        $this->delete('/emails/'.$subscriber->manage_token)->assertRedirect('/emails/off');
        $this->assertModelMissing($subscriber);

        $this->withSession(['off' => 'ada@example.com'])->get('/emails/off')
            ->assertInertia(fn (Assert $p) => $p->component('Emails')->where('stopped', 'ada@example.com'));
        $this->get('/emails/off')->assertRedirect('/');
    }

    public function test_an_unknown_token_finds_nothing(): void
    {
        $this->get('/emails/'.Str::random(48))->assertNotFound();
        $this->delete('/emails/'.Str::random(48))->assertNotFound();
    }
}
