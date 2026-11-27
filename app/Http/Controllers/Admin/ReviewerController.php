<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Mail\ReviewerInvited;
use App\Models\ReviewerInvite;
use App\Models\User;
use App\Models\Work;
use App\Support\ReviewersPage;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Reviewers page: inviting people to read drafts, sending an invite again or cancelling it, giving reviewers and
 * invites works (a Private work opens to reviewers when it is given), and removing a reviewer's access. Their notes stay.
 */
class ReviewerController extends Controller
{
    /** The Reviewers page, opened on a person when `p` names one. */
    public function index(Request $request): Response
    {
        $open = $request->query('p');

        return Inertia::render('Admin/Reviewers', [
            ...ReviewersPage::props(),
            'open' => is_string($open) && $open !== '' ? $open : null,
        ]);
    }

    /**
     * Every work a reviewer can be given, as the Reviewers page lists them.
     */
    public function works(): JsonResponse
    {
        return response()->json(['works' => ReviewersPage::works()]);
    }

    /**
     * Invites someone to read works, and mails the link to them.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:254'],
            'works' => ['required', 'array', 'min:1'],
            'works.*' => ['required', 'string', Rule::exists('works', 'slug')],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $name = trim((string) preg_replace('/\s+/u', ' ', $data['name']));
        $email = Str::lower(trim($data['email']));
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $works = Work::query()->whereIn('slug', $data['works'])->orderBy('id')->get();

        $this->assertInvitable($email);

        $token = Str::random(64);

        $invite = DB::transaction(function () use ($request, $name, $email, $note, $works, $token): ReviewerInvite {
            $invite = ReviewerInvite::query()->create([
                'email' => $email,
                'name' => $name,
                'note' => $note,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(ReviewersPage::INVITE_DAYS),
            ]);

            $invite->works()->attach($works->pluck('id')->all());
            $this->openToReviewers($works);

            return $invite;
        });

        $this->sendInvite($invite, $works, $token);

        return response()->json(['person' => ReviewersPage::personFor($invite)], 201);
    }

    /**
     * Sends the invite again with a new link that works for another fourteen days.
     */
    public function resend(ReviewerInvite $invite): JsonResponse
    {
        abort_unless($invite->accepted_at === null && $invite->cancelled_at === null, 404);

        $works = $invite->works()->orderBy('works.id')->get();

        if ($works->isEmpty()) {
            throw ValidationException::withMessages(['works' => 'Give them a work before sending the invite again.']);
        }

        $token = Str::random(64);

        $invite->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(ReviewersPage::INVITE_DAYS),
        ])->save();

        $this->sendInvite($invite, $works, $token);

        return response()->json(['person' => ReviewersPage::personFor($invite)]);
    }

    /**
     * Cancels an invite that has not been accepted; its link stops working.
     */
    public function cancel(ReviewerInvite $invite): JsonResponse
    {
        abort_unless($invite->accepted_at === null, 404);

        $invite->forceFill(['cancelled_at' => $invite->cancelled_at ?? now()])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Gives an invite a work, or takes one away.
     */
    public function inviteWorks(Request $request, ReviewerInvite $invite): JsonResponse
    {
        abort_unless($invite->accepted_at === null && $invite->cancelled_at === null, 404);

        $this->changeWork($request, $invite->works());

        return response()->json(['person' => ReviewersPage::personFor($invite)]);
    }

    /**
     * Gives a reviewer a work, or takes one away.
     */
    public function userWorks(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === UserRole::Reviewer, 404);

        $this->changeWork($request, $user->reviewableWorks());

        return response()->json(['person' => ReviewersPage::personFor($user)]);
    }

    /**
     * Removes a reviewer's access to every work. Their notes stay, and they keep the reviewer role.
     */
    public function remove(User $user): JsonResponse
    {
        abort_unless($user->role === UserRole::Reviewer, 404);

        $user->reviewableWorks()->detach();

        return response()->json(['ok' => true]);
    }

    /**
     * Gives or takes one work from a set of works. A Private work given to anyone opens to reviewers.
     */
    private function changeWork(Request $request, BelongsToMany $works): void
    {
        $data = $request->validate([
            'work' => ['required', 'string', Rule::exists('works', 'slug')],
            'on' => ['required', 'boolean'],
        ]);

        $work = Work::query()->where('slug', $data['work'])->firstOrFail();

        if ($request->boolean('on')) {
            $works->syncWithoutDetaching([$work->id]);

            if ($work->visibility === Visibility::Private) {
                $work->forceFill(['visibility' => Visibility::Reviewers])->save();
            }

            return;
        }

        $works->detach($work->id);
    }

    /**
     * Refuses an email that is the writer's own, that already reviews, or that has an invite waiting.
     */
    private function assertInvitable(string $email): void
    {
        if (User::query()->where('role', UserRole::Author)->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'That’s your own email.']);
        }

        $reviewer = User::query()
            ->where('role', UserRole::Reviewer)
            ->whereRaw('lower(email) = ?', [$email])
            ->whereHas('reviewableWorks')
            ->first(['id', 'name']);

        if ($reviewer !== null) {
            throw ValidationException::withMessages(['email' => $reviewer->name.' already reviews. Open their row to change their works.']);
        }

        $pending = ReviewerInvite::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->first(['name']);

        if ($pending !== null) {
            throw ValidationException::withMessages(['email' => $pending->name.' is already invited.']);
        }
    }

    /**
     * Makes a Private work the invite is for open to reviewers, as giving a reviewer a work does.
     *
     * @param  Collection<int, Work>  $works
     */
    private function openToReviewers(Collection $works): void
    {
        foreach ($works as $work) {
            if ($work->visibility === Visibility::Private) {
                $work->forceFill(['visibility' => Visibility::Reviewers])->save();
            }
        }
    }

    /**
     * Mails the invite its link: the first work's title in the subject, the works and the note in the body.
     *
     * @param  Collection<int, Work>  $works
     */
    private function sendInvite(ReviewerInvite $invite, Collection $works, string $token): void
    {
        $titles = $works->pluck('title')->all();

        Mail::to($invite->email)->send(new ReviewerInvited(
            firstName: Str::before($invite->name, ' '),
            firstTitle: (string) $titles[0],
            worksList: self::listed($titles),
            note: $invite->note,
            startUrl: rtrim((string) config('app.url'), '/').'/review/'.$token,
        ));
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $titles
     */
    private static function listed(array $titles): string
    {
        $last = (string) array_pop($titles);

        return $titles === [] ? $last : implode(', ', $titles).' and '.$last;
    }
}
