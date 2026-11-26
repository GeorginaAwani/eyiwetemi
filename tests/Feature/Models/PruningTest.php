<?php

namespace Tests\Feature\Models;

use App\Models\DailyVisitor;
use App\Models\LoginLink;
use App\Models\ReviewerInvite;
use App\Models\Subscriber;
use App\Models\TrustedDevice;
use App\Models\UsernameHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_prune_removes_only_stale_rows(): void
    {
        $expiredDevice = TrustedDevice::factory()->expired()->create();
        $activeDevice = TrustedDevice::factory()->create();

        $staleLoginLink = LoginLink::factory()->create(['expires_at' => now()->subDays(2)]);
        $staleUsedLink = LoginLink::factory()->create(['used_at' => now()->subDays(2)]);
        $freshLoginLink = LoginLink::factory()->create();
        $freshUsedLink = LoginLink::factory()->used()->create();

        $releasedHold = UsernameHold::factory()->released()->create();
        $activeHold = UsernameHold::factory()->create();

        $oldInvite = ReviewerInvite::factory()->expired()->create(['expires_at' => now()->subDays(91)]);
        $oldCancelledInvite = ReviewerInvite::factory()->cancelled()->create(['cancelled_at' => now()->subDays(91)]);
        $recentExpiredInvite = ReviewerInvite::factory()->expired()->create();
        $recentCancelledInvite = ReviewerInvite::factory()->cancelled()->create(['cancelled_at' => now()->subDays(10)]);
        $pendingInvite = ReviewerInvite::factory()->create();
        $acceptedInvite = ReviewerInvite::factory()->accepted()->create(['expires_at' => now()->subDays(120)]);

        $unconfirmedSubscriber = Subscriber::factory()->create(['created_at' => now()->subDays(31)]);
        $freshSubscriber = Subscriber::factory()->create(['created_at' => now()->subDays(2)]);
        $confirmedSubscriber = Subscriber::factory()->confirmed()->create(['created_at' => now()->subDays(60)]);

        $pastVisitor = DailyVisitor::factory()->create(['day' => today()->subDay()->toDateString()]);
        $todayVisitor = DailyVisitor::factory()->create();

        $this->artisan('model:prune', [
            '--model' => [
                TrustedDevice::class,
                LoginLink::class,
                UsernameHold::class,
                ReviewerInvite::class,
                Subscriber::class,
                DailyVisitor::class,
            ],
        ])->assertSuccessful();

        $this->assertDatabaseMissing('trusted_devices', ['id' => $expiredDevice->id]);
        $this->assertDatabaseHas('trusted_devices', ['id' => $activeDevice->id]);

        $this->assertDatabaseMissing('login_links', ['id' => $staleLoginLink->id]);
        $this->assertDatabaseMissing('login_links', ['id' => $staleUsedLink->id]);
        $this->assertDatabaseHas('login_links', ['id' => $freshLoginLink->id]);
        $this->assertDatabaseHas('login_links', ['id' => $freshUsedLink->id]);

        $this->assertDatabaseMissing('username_holds', ['id' => $releasedHold->id]);
        $this->assertDatabaseHas('username_holds', ['id' => $activeHold->id]);

        $this->assertDatabaseMissing('reviewer_invites', ['id' => $oldInvite->id]);
        $this->assertDatabaseMissing('reviewer_invites', ['id' => $oldCancelledInvite->id]);
        $this->assertDatabaseHas('reviewer_invites', ['id' => $recentExpiredInvite->id]);
        $this->assertDatabaseHas('reviewer_invites', ['id' => $recentCancelledInvite->id]);
        $this->assertDatabaseHas('reviewer_invites', ['id' => $pendingInvite->id]);
        $this->assertDatabaseHas('reviewer_invites', ['id' => $acceptedInvite->id]);

        $this->assertDatabaseMissing('subscribers', ['id' => $unconfirmedSubscriber->id]);
        $this->assertDatabaseHas('subscribers', ['id' => $freshSubscriber->id]);
        $this->assertDatabaseHas('subscribers', ['id' => $confirmedSubscriber->id]);

        $this->assertDatabaseMissing('daily_visitors', ['id' => $pastVisitor->id]);
        $this->assertDatabaseHas('daily_visitors', ['id' => $todayVisitor->id]);
    }
}
