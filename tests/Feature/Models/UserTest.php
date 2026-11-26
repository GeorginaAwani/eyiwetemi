<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_username_is_derived_from_the_name(): void
    {
        $user = User::factory()->create(['name' => 'Ifẹ́olúwa Bánjọ', 'username' => null]);

        $this->assertStringStartsWith('ifeoluwa', $user->username);
        $this->assertMatchesRegularExpression('/^[a-z0-9._]{3,20}$/', $user->username);
    }

    public function test_derived_usernames_stay_unique_for_the_same_name(): void
    {
        $first = User::factory()->create(['name' => 'Ifẹ́olúwa Bánjọ', 'username' => '']);
        $second = User::factory()->create(['name' => 'Ifẹ́olúwa Bánjọ', 'username' => '']);

        $this->assertNotSame($first->username, $second->username);
        $this->assertMatchesRegularExpression('/^[a-z0-9._]{3,20}$/', $second->username);
    }

    public function test_missing_avatar_seed_gets_a_sixteen_character_seed(): void
    {
        $user = User::factory()->create(['avatar_seed' => '']);

        $this->assertSame(16, strlen($user->avatar_seed));
    }

    public function test_given_username_and_avatar_seed_are_kept(): void
    {
        $user = User::factory()->create(['username' => 'kept.name', 'avatar_seed' => 'seedseedseedseed']);

        $this->assertSame('kept.name', $user->fresh()->username);
        $this->assertSame('seedseedseedseed', $user->fresh()->avatar_seed);
    }

    public function test_author_and_reviewer_helpers_follow_the_role(): void
    {
        $author = User::factory()->author()->create();
        $reviewer = User::factory()->reviewer()->create();
        $reader = User::factory()->reader()->create();

        $this->assertTrue($author->isAuthor());
        $this->assertFalse($author->isReviewer());
        $this->assertTrue($reviewer->isReviewer());
        $this->assertFalse($reviewer->isAuthor());
        $this->assertFalse($reader->isAuthor());
        $this->assertFalse($reader->isReviewer());
    }

    public function test_google_only_accounts_have_no_password(): void
    {
        $user = User::factory()->googleOnly()->create();

        $this->assertNull($user->fresh()->password);
    }
}
