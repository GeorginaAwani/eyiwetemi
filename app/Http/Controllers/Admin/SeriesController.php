<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Series;
use App\Models\SlugHistory;
use App\Support\WorksAdmin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Series, named on the spot from a novel's Settings or the New work dialog, and renamed there.
 */
class SeriesController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $name = $this->name($request, null);

        $series = Series::query()->create([
            'name' => $name,
            'name_key' => WorksAdmin::titleKey($name),
            'slug' => $this->slug($name, null),
        ]);

        return response()->json(['series' => ['id' => $series->id, 'name' => $series->name, 'books' => 0]], 201);
    }

    /**
     * Rename: the series page moves to the new address, and the old one still leads there.
     */
    public function update(Request $request, Series $series): JsonResponse
    {
        $name = $this->name($request, $series);
        $slug = $this->slug($name, $series);

        if ($slug !== $series->slug) {
            SlugHistory::query()->firstOrCreate(['sluggable_type' => $series->getMorphClass(), 'sluggable_id' => $series->id, 'slug' => $series->slug]);
            SlugHistory::query()->where('sluggable_type', $series->getMorphClass())->where('sluggable_id', $series->id)->where('slug', $slug)->delete();
        }

        $series->update(['name' => $name, 'name_key' => WorksAdmin::titleKey($name), 'slug' => $slug]);

        return response()->json(['series' => ['id' => $series->id, 'name' => $series->name, 'books' => $series->works()->count()]]);
    }

    private function name(Request $request, ?Series $except): string
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail) use ($except): void {
                $clash = Series::query()
                    ->where('name_key', WorksAdmin::titleKey((string) $value))
                    ->when($except, fn ($query) => $query->whereKeyNot($except->id))
                    ->first();

                if ($clash) {
                    $fail("You already have a series called {$clash->name}.");
                }
            }],
        ], ['name.required' => 'Give it a name.']);

        return WorksAdmin::cleanTitle((string) $request->input('name'));
    }

    private function slug(string $name, ?Series $except): string
    {
        $base = Str::slug(Str::ascii($name)) ?: 'series';
        $slug = $base;
        $n = 2;

        while (
            Series::query()->where('slug', $slug)->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists()
            || SlugHistory::query()->where('sluggable_type', (new Series)->getMorphClass())->where('slug', $slug)
                ->when($except, fn ($query) => $query->where('sluggable_id', '!=', $except->id))->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
