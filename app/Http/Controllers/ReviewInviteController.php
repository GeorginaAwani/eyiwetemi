<?php

namespace App\Http\Controllers;

use App\Models\ReviewerInvite;
use App\Support\NewsletterAdmin;
use App\Support\ReviewerInvites;
use App\Support\WorkPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The link in a reviewer's invite email. Signed in with the invited address, the reader is made a reviewer on the spot;
 * a guest is asked to sign in first, and the invite is taken up when they do, with that address.
 */
class ReviewInviteController extends Controller
{
    public function join(Request $request, string $token): RedirectResponse
    {
        $invite = ReviewerInvite::query()->where('token_hash', hash('sha256', $token))->first();
        abort_if($invite === null || $invite->cancelled_at !== null, 404);

        $work = ReviewerInvites::firstWork($invite);
        abort_if($work === null, 404);

        $href = WorkPresenter::href($work);
        $user = $request->user();

        if ($invite->accepted_at !== null) {
            abort_unless($user !== null && (int) $invite->accepted_user_id === $user->id, 404);

            return redirect()->to($href);
        }

        abort_if($invite->isExpired(), 404);

        if ($user === null) {
            $request->session()->put('review.invite', $invite->id);
            $request->session()->put('url.intended', $href);

            return redirect()->route('home')->with('signin', ['view' => 'main', 'why' => 'Sign in to read '.$work->title]);
        }

        if ($user->isAuthor()) {
            return redirect()->to($href);
        }

        /* the invite is for one address: signed in as someone else, they're signed out and asked to sign in with it */
        if (! ReviewerInvites::isFor($invite, $user)) {
            Auth::guard('web')->logout();
            $request->session()->regenerateToken();
            $request->session()->put('review.invite', $invite->id);
            $request->session()->put('url.intended', $href);

            return redirect()->route('home')->with('signin', ['view' => 'main', 'why' => 'This invite is for '.NewsletterAdmin::mask($invite->email).'. Sign in with that email.']);
        }

        ReviewerInvites::accept($invite, $user);

        return redirect()->to($href);
    }
}
