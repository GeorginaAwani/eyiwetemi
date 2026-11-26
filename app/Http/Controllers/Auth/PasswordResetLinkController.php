<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The link in the reset email: Home opens with the card asking for a new password.
 * The token leaves the address bar straight away; it rides in the session for this one visit.
 */
class PasswordResetLinkController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        return redirect()->route('home')->with('signin', [
            'view' => 'reset',
            'reset' => ['token' => $token, 'email' => $request->string('email')->toString()],
        ]);
    }
}
