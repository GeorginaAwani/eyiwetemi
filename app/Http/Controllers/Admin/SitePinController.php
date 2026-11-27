<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Work;
use App\Support\HomePage;
use App\Support\HomePin;
use App\Support\SitePages;
use App\Support\WorkPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The work that leads Home and until when. Each answer carries Home's preview as it now stands.
 */
class SitePinController extends Controller
{
    /**
     * Pins a work, readers can see, with something out, until a day and time in the future (West Africa Time).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'until' => ['required', 'array'],
            'until.day' => ['required', 'date_format:Y-m-d'],
            'until.time' => ['required', 'date_format:H:i'],
        ]);

        $work = $this->leadable($data['slug']);
        $until = SitePages::fromWat($data['until']['day'].' '.$data['until']['time']);

        if ($until->isPast()) {
            throw ValidationException::withMessages(['until' => 'Pick a time in the future.']);
        }

        HomePin::set($work->slug, $until);

        return response()->json(['home' => (new HomePage)->preview()]);
    }

    /**
     * Moves the pin's end to another day, keeping its time of day.
     */
    public function date(Request $request): JsonResponse
    {
        $data = $request->validate(['until' => ['required', 'string', 'date_format:Y-m-d']]);
        $pin = HomePin::current();

        if ($pin === null || $pin['until'] === null) {
            return response()->json(['message' => 'Nothing is pinned to a date right now.'], 409);
        }

        $time = $pin['until']->setTimezone((string) config('eyiwetemi.timezone'))->format('H:i');
        $until = SitePages::fromWat($data['until'].' '.$time);

        if ($until->isPast()) {
            throw ValidationException::withMessages(['until' => 'Pick a day in the future.']);
        }

        HomePin::set($pin['slug'], $until);

        return response()->json(['home' => (new HomePage)->preview()]);
    }

    /**
     * Takes the pin off: the newest work leads Home again.
     */
    public function destroy(): JsonResponse
    {
        HomePin::clear();

        return response()->json(['home' => (new HomePage)->preview()]);
    }

    /**
     * A work readers can see that has something out, so Home can lead with it.
     */
    private function leadable(string $slug): Work
    {
        $work = Work::query()->listed()->with(WorkPresenter::with())->where('slug', $slug)->first();

        if ($work === null) {
            throw ValidationException::withMessages(['slug' => 'Only a work readers can see can lead Home.']);
        }

        if (WorkPresenter::numbered($work)->isEmpty()) {
            throw ValidationException::withMessages(['slug' => 'Only a work with something out can lead Home.']);
        }

        return $work;
    }
}
