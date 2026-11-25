<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'avatar_seed', 'preferences', 'pending_email', 'username_changed_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'preferences' => 'array',
        ];
    }

    /**
     * Fill in a username and avatar seed when sign-up does not provide them.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank($user->username)) {
                $user->username = static::uniqueUsernameFor($user->name);
            }

            if (blank($user->avatar_seed)) {
                $user->avatar_seed = Str::random(16);
            }
        });
    }

    /**
     * Derive a 3-20 character username from a display name, with a numeric suffix if it is taken.
     */
    protected static function uniqueUsernameFor(string $name): string
    {
        $base = Str::lower(Str::ascii($name));
        $base = preg_replace('/\s+/', '.', trim($base));
        $base = preg_replace('/[^a-z0-9._]/', '', $base);
        $base = trim(preg_replace('/\.{2,}/', '.', $base), '._');

        if (strlen($base) < 3) {
            $base = 'user'.$base;
        }

        $base = trim(substr($base, 0, 20), '._');
        $candidate = $base;
        $suffix = 2;

        while (static::query()->where('username', $candidate)->exists()) {
            $candidate = rtrim(substr($base, 0, 20 - strlen((string) $suffix)), '._').$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<TrustedDevice, $this> */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /** @return HasMany<LoginLink, $this> */
    public function loginLinks(): HasMany
    {
        return $this->hasMany(LoginLink::class);
    }

    /** @return HasMany<UsernameHold, $this> */
    public function usernameHolds(): HasMany
    {
        return $this->hasMany(UsernameHold::class);
    }

    /** @return BelongsToMany<Work, $this> */
    public function reviewableWorks(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_reviewer')->withTimestamps();
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Like, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    /** @return HasMany<Follow, $this> */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    /** @return HasMany<ReadingProgress, $this> */
    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    public function isAuthor(): bool
    {
        return $this->role === UserRole::Author;
    }

    public function isReviewer(): bool
    {
        return $this->role === UserRole::Reviewer;
    }
}
