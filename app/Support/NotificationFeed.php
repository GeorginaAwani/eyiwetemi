<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Your notifications as the site shows them: newest first, under New (unread), This week, then by month.
 * Things that have since gone or been hidden are left out.
 */
class NotificationFeed
{
    public const PAGE = 20;

    public function __construct(private User $user) {}

    /**
     * One page, older than the notification `$before` (a page's last id), or the newest.
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function page(?string $before = null, int $size = self::PAGE): array
    {
        $query = $this->user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');

        if ($before !== null) {
            $cursor = $this->user->notifications()->find($before, ['id', 'created_at']);
            if ($cursor) {
                $query->where(fn ($q) => $q->where('created_at', '<', $cursor->created_at)
                    ->orWhere(fn ($q) => $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id)));
            }
        }

        $found = $query->limit($size + 1)->get(['id', 'data', 'read_at', 'created_at']);

        return [
            'rows' => $found->take($size)->map(fn (DatabaseNotification $n): ?array => $this->row($n))->filter()->values()->all(),
            'more' => $found->count() > $size,
        ];
    }

    /**
     * The bell's short list: the newest few.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $take = 4): array
    {
        return $this->page(null, $take)['rows'];
    }

    public function unread(): int
    {
        return $this->user->unreadNotifications()->count();
    }

    public function total(): int
    {
        return $this->user->notifications()->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(DatabaseNotification $notification): ?array
    {
        $shown = NotificationPresenter::present((array) $notification->data);
        if ($shown === null) {
            return null;
        }

        return [
            'id' => $notification->id,
            ...$shown,
            'unread' => $notification->read_at === null,
            'when' => $this->when($notification->created_at),
            'group' => $this->group($notification),
        ];
    }

    private function group(DatabaseNotification $notification): string
    {
        $zone = config('eyiwetemi.timezone');
        $at = $notification->created_at->copy()->setTimezone($zone);

        return match (true) {
            $notification->read_at === null => 'New',
            $at->gte(now($zone)->subDays(7)) => 'This week',
            $at->year === now($zone)->year => $at->format('F'),
            default => $at->format('F Y'),
        };
    }

    /**
     * "12 min", "2 h", "yesterday", then the day.
     */
    private function when(CarbonInterface $at): string
    {
        $zone = config('eyiwetemi.timezone');
        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 60 => max(1, $minutes).' min',
            $minutes < 24 * 60 => intdiv($minutes, 60).' h',
            $at->copy()->setTimezone($zone)->isSameDay(now($zone)->subDay()) => 'yesterday',
            default => (string) Reading::day($at),
        };
    }
}
