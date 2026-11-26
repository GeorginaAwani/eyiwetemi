<?php

namespace Database\Seeders;

use App\Models\Like;
use App\Models\Note;
use App\Models\SiteSetting;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The desk as in the admin prototype. When each draft was last touched, as in its "Writing now": The Ember Gate's
 * chapter 8 ("The tide house", a working title) two hours ago, Starlings yesterday, Under the Plum Rain four days ago.
 * Every other draft was last touched over a week ago, and published pieces have no edits waiting. Her last look at
 * notes' hearts leaves the prototype's ten new ones.
 */
class AdminDeskSeeder extends Seeder
{
    /** @var array<string, array{position: int, hours: int, title?: string}> */
    private const WRITING_NOW = [
        'the-ember-gate' => ['position' => 8, 'hours' => 2, 'title' => 'The tide house'],
        'starlings-go-south-in-september' => ['position' => 2, 'hours' => 26],
        'under-the-plum-rain' => ['position' => 4, 'hours' => 98],
    ];

    public function run(): void
    {
        WorkItem::query()->whereNotNull('draft_saved_at')->where('status', 'draft')->update(['draft_saved_at' => now()->subDays(9)]);
        WorkItem::query()->whereNotNull('draft_saved_at')->where('status', 'published')
            ->update(['draft_saved_at' => DB::raw('coalesce(live_updated_at, published_at)')]);

        foreach (self::WRITING_NOW as $slug => $draft) {
            $work = Work::query()->where('slug', $slug)->first();
            $item = $work?->items()->where('position', $draft['position'])->first();

            if (! $item) {
                continue;
            }

            $item->forceFill([
                'draft_saved_at' => now()->subHours($draft['hours']),
                'title' => $item->title ?? ($draft['title'] ?? null),
            ])->save();
        }

        $tenthNewestHeart = Like::query()->where('likeable_type', (new Note)->getMorphClass())->latest()->skip(9)->value('created_at');

        if ($tenthNewestHeart) {
            SiteSetting::put('desk.hearts_seen_at', $tenthNewestHeart->copy()->subSecond()->toIso8601String());
        }
    }
}
