<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribeRequest;
use App\Mail\NewsletterWelcome;
use App\Models\NewsletterStop;
use App\Models\Subscriber;
use App\Support\NewsletterEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * New work by email, from the reader's side: confirming the address, choosing what comes, stopping it.
 * Every link carries the subscriber's own token; there is no account behind it.
 */
class EmailsController extends Controller
{
    /**
     * The link in the confirmation email: the address is on the list from now on, and gets the welcome letter once.
     */
    public function confirm(string $token): RedirectResponse
    {
        $subscriber = Subscriber::query()->firstWhere('confirm_token_hash', hash('sha256', $token));

        abort_if($subscriber === null, 404);

        $firstConfirmation = $subscriber->confirmed_at === null;
        $subscriber->update(['confirmed_at' => now(), 'confirm_token_hash' => null]);

        if ($firstConfirmation) {
            Mail::to($subscriber->email)->queue(new NewsletterWelcome(NewsletterEmail::welcome($subscriber)));
        }

        return redirect()->route('emails.show', $subscriber->manage_token);
    }

    /**
     * What you get: the kinds this address receives.
     */
    public function show(string $token): Response
    {
        $subscriber = $this->subscriber($token);

        return Inertia::render('Emails', ['email' => $subscriber->email, 'kinds' => $subscriber->kinds, 'token' => $token]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(SubscribeRequest::KINDS)],
        ]);

        $this->subscriber($token)->update(['kinds' => array_values(array_intersect(SubscribeRequest::KINDS, $data['kinds']))]);

        return back();
    }

    /**
     * Stop all emails: the address is deleted, not just switched off.
     */
    public function destroy(string $token): RedirectResponse
    {
        $subscriber = $this->subscriber($token);
        $email = $subscriber->email;
        NewsletterStop::record();
        $subscriber->delete();

        return redirect()->route('emails.off')->with('off', $email);
    }

    /**
     * You're off the list (straight after stopping; there is nothing to show later).
     */
    public function off(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('off');

        if (! is_string($email)) {
            return redirect()->route('home');
        }

        return Inertia::render('Emails', ['stopped' => $email]);
    }

    private function subscriber(string $token): Subscriber
    {
        return Subscriber::query()->where('manage_token', $token)->firstOrFail();
    }
}
