<?php

namespace Database\Seeders;

use App\Enums\CommentAudience;
use App\Models\Comment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Announcer;
use Carbon\CarbonInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The notices readers would already have: what came out in the last 60 days and replies to their comments,
 * each dated when it arrived. Anything older than three days is read; the newer ones stay unread.
 */
class NotificationSeeder extends Seeder
{
    use WithoutModelEvents;

    /** How far back the seeded history goes, in days. */
    private const HISTORY_DAYS = 60;

    /** Notices older than this many days show as read. */
    private const READ_AFTER_DAYS = 3;

    /**
     * Writes the history straight into the notifications table, using the same recipients and wording as live notices.
     */
    public function run(): void
    {
        $now = now();
        $from = $now->copy()->subDays(self::HISTORY_DAYS);
        $announcer = app(Announcer::class);

        $deliveries = $announcer->releases($from, $now, false);

        $replies = Comment::query()
            ->whereNotNull('parent_id')
            ->where('audience', CommentAudience::Public)
            ->whereNull('hidden_at')
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $now)
            ->get();

        foreach ($replies as $reply) {
            $deliveries = $deliveries->merge($announcer->replyDeliveries($reply, false));
        }

        $rows = [];

        foreach ($deliveries as $delivery) {
            foreach ($delivery['users'] as $user) {
                $rows[] = $this->row($user, $delivery['notification'], $delivery['at'], $now);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        SiteSetting::put('announce.until', $now->toIso8601String());
    }

    /**
     * One notice row, dated when it arrived.
     *
     * @return array<string, mixed>
     */
    private function row(User $user, Notification $notification, CarbonInterface $at, CarbonInterface $now): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode($notification->toArray($user), JSON_THROW_ON_ERROR),
            'read_at' => $at->lessThan($now->copy()->subDays(self::READ_AFTER_DAYS)) ? $at->copy()->addHour() : null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
