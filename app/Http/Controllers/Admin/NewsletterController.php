<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\Newsletter;
use App\Mail\NewsletterWelcome;
use App\Models\NewsletterStop;
use App\Models\Subscriber;
use App\Models\Work;
use App\Support\NewsletterAdmin;
use App\Support\NewsletterEmail;
use App\Support\NewsletterItem;
use App\Support\NewsletterPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The newsletter: the weekly slot, the upcoming send (its time, its hold, its words and what is in it), the subscribers
 * and the stops, the welcome letter, and the previews and test sends of the emails.
 */
class NewsletterController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Admin/Newsletter', NewsletterAdmin::props((string) $request->string('tab')));
    }

    /**
     * The weekly slot she sends at. The upcoming send moves to it, unless she has moved that send by hand.
     */
    public function weekly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day' => ['required', 'integer', 'between:0,6'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
        ], ['time.regex' => 'Pick a time on the half hour.']);

        NewsletterPlan::setWeekly((int) $data['day'], $data['time']);

        return response()->json([
            'weekly' => NewsletterPlan::weekly(),
            'next' => NewsletterAdmin::next(NewsletterPlan::next()),
        ]);
    }

    /**
     * The upcoming send: its words, its time (or back to the weekly slot), and whether it is held.
     */
    public function updateNext(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'at' => ['sometimes', 'nullable', 'array'],
            'at.day' => ['required_with:at', 'date_format:Y-m-d'],
            'at.time' => ['required_with:at', 'date_format:H:i'],
            'held' => ['sometimes', 'boolean'],
        ]);

        $send = NewsletterPlan::next();
        $at = null;

        if (($data['at'] ?? null) !== null) {
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['at']['day'].' '.$data['at']['time'], (string) config('eyiwetemi.timezone'));

            if ($at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['day' => 'Pick a time that hasn’t passed.']);
            }
        }

        if (array_key_exists('at', $data)) {
            NewsletterPlan::move($send, $at?->utc());
        }

        if (array_key_exists('lines', $data)) {
            $send->forceFill(['intro_lines' => $data['lines']])->save();
        }

        if (array_key_exists('held', $data)) {
            NewsletterPlan::hold($send, $request->boolean('held'));
        }

        return response()->json(['next' => NewsletterAdmin::next(NewsletterPlan::next())]);
    }

    /**
     * Leaves an item out of the upcoming send, or puts it back in.
     */
    public function updateItem(Request $request, string $key): JsonResponse
    {
        $request->validate(['out' => ['required', 'boolean']]);

        $row = NewsletterPlan::next()->items()->whereKey((int) Str::after($key, 'i'))->first();
        abort_if($row === null, 404);

        $row->forceFill(['left_out' => $request->boolean('out')])->save();

        return response()->json(['next' => NewsletterAdmin::next(NewsletterPlan::next())]);
    }

    /**
     * One page of the subscribers, or the one address searched for in full.
     */
    public function subscribers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(NewsletterItem::KIND_LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(NewsletterAdmin::subscriberPage(
            $data['kind'] ?? null,
            (int) ($data['page'] ?? 1),
            (string) ($data['q'] ?? ''),
            $request->boolean('unconfirmed'),
        ));
    }

    /**
     * Removes an address from the list. That counts as a stop.
     */
    public function removeSubscriber(Subscriber $subscriber): Response
    {
        NewsletterStop::record();
        $subscriber->delete();

        return response()->noContent();
    }

    /**
     * The welcome letter: her words, and up to three public works to start with.
     */
    public function welcome(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'works' => ['sometimes', 'array', 'max:'.NewsletterPlan::WELCOME_WORKS],
            'works.*' => ['string', 'distinct', Rule::in(Work::query()->listed()->pluck('slug')->all())],
        ]);

        NewsletterPlan::saveWelcome(
            array_key_exists('lines', $data) ? (string) ($data['lines'] ?? '') : null,
            array_key_exists('works', $data) ? array_values($data['works']) : null,
        );

        return response()->json(['welcome' => NewsletterAdmin::welcome()]);
    }

    /**
     * The real email HTML, for the upcoming send (for one kind of reader, when asked) or for the welcome letter.
     */
    public function preview(Request $request): Response
    {
        $data = $request->validate([
            'what' => ['required', Rule::in(['send', 'welcome'])],
            'kind' => ['nullable', Rule::in(array_keys(NewsletterItem::KIND_LABELS))],
        ]);
        $dark = $request->boolean('dark');

        if ($data['what'] === 'welcome') {
            return response((new NewsletterWelcome(NewsletterEmail::welcome(null, $dark)))->render());
        }

        $email = NewsletterEmail::send(NewsletterPlan::next(), null, $data['kind'] ?? null, $dark);

        if ($email === null) {
            return response()->view('mail.newsletter-none', ['message' => $this->nothingFor($data['kind'] ?? null)]);
        }

        return response((new Newsletter($email))->render());
    }

    /**
     * Sends the upcoming send, or the welcome letter, to the author's own address as a test.
     */
    public function test(Request $request): Response
    {
        $data = $request->validate(['what' => ['required', Rule::in(['send', 'welcome'])]]);
        $to = $request->user()->email;

        if ($data['what'] === 'welcome') {
            Mail::to($to)->send(new NewsletterWelcome(NewsletterEmail::welcome()));

            return response()->noContent();
        }

        $email = NewsletterEmail::send(NewsletterPlan::next(), null);

        if ($email === null) {
            throw ValidationException::withMessages(['what' => 'There is nothing in this send to test yet.']);
        }

        Mail::to($to)->send(new Newsletter($email));

        return response()->noContent();
    }

    /**
     * What a preview says when no item is left for the readers it is for.
     */
    private function nothingFor(?string $kind): string
    {
        if ($kind === null) {
            return 'Nothing for anyone this week. They get no email.';
        }

        return 'Nothing for '.Str::lower(NewsletterItem::KIND_LABELS[$kind]).' readers this week. They get no email.';
    }
}
