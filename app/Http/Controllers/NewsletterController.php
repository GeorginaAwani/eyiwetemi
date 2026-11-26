<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscribeRequest;
use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class NewsletterController extends Controller
{
    /**
     * Sign an email up for new work. The address waits unconfirmed until its owner opens the
     * confirmation link, and an address already on the list is left as it is, so the reply never
     * tells anyone whether an email is subscribed.
     */
    public function store(SubscribeRequest $request): RedirectResponse
    {
        $email = Str::lower(trim($request->validated('email')));
        $kinds = array_values(array_intersect(SubscribeRequest::KINDS, $request->validated('kinds')));

        $subscriber = Subscriber::query()->firstWhere('email', $email);

        if ($subscriber === null || $subscriber->confirmed_at === null) {
            Subscriber::query()->updateOrCreate(['email' => $email], [
                'kinds' => $kinds,
                'manage_token' => $subscriber->manage_token ?? Str::random(48),
                'confirm_token_hash' => hash('sha256', Str::random(48)),
            ]);
        }

        return back();
    }
}
