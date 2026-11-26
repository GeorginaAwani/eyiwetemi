<?php

namespace Tests\Feature\Database;

use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_accents_are_folded_when_indexing(): void
    {
        $folded = DB::selectOne('SELECT public.f_unaccent(?) AS value', ['Xú Wēi'])->value;

        $this->assertSame('Xu Wei', $folded);
    }

    public function test_work_item_text_is_found_without_accents(): void
    {
        $match = WorkItem::factory()->published()->create(['live_text' => 'Xú Wēi walked to the station.']);
        WorkItem::factory()->published()->create(['live_text' => 'Nothing to see here.']);

        $found = WorkItem::query()
            ->whereRaw("search @@ plainto_tsquery('simple', public.f_unaccent(?))", ['Xu Wei'])
            ->pluck('id')
            ->all();

        $this->assertSame([$match->id], $found);
    }

    public function test_work_title_is_found_without_accents(): void
    {
        $match = Work::factory()->create(['title' => 'Xú Wēi Chronicles']);
        Work::factory()->create(['title' => 'Plain Title Here']);

        $found = Work::query()
            ->whereRaw("search @@ plainto_tsquery('simple', public.f_unaccent(?))", ['Xu Wei'])
            ->pluck('id')
            ->all();

        $this->assertSame([$match->id], $found);
    }
}
