<?php

namespace App\Http\Middleware;

use App\Support\Preferences;
use App\Support\SiteChrome;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    /**
     * The admin host has its own page shell (its own styles and theme).
     */
    public function rootView(Request $request): string
    {
        return self::isAdmin($request) ? 'admin' : $this->rootView;
    }

    public static function isAdmin(Request $request): bool
    {
        return $request->getHost() === config('eyiwetemi.admin_domain');
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if (self::isAdmin($request)) {
            return [
                ...parent::share($request),
                'auth' => [
                    'user' => fn (): ?array => $request->session()->get('admin.verified') ? $request->user()?->only(['name', 'email']) : null,
                ],
                'siteUrl' => rtrim((string) config('app.url'), '/').'/',
            ];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn (): ?array => $request->user() ? [
                    ...$request->user()->only(['id', 'name', 'username', 'email', 'avatar_seed']),
                    'reading' => Preferences::readingFor($request->user()),
                    'unread' => $request->user()->unreadNotifications()->count(),
                ] : null,
            ],
            'signin' => fn (): ?array => $request->session()->get('signin'),
            'site' => fn (): array => SiteChrome::site(),
            'notice' => fn (): ?array => SiteChrome::notice(),
        ];
    }
}
