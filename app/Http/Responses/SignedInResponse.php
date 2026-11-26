<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

/**
 * After signing in or creating an account: the page a reader was sent away from (a private page), or null
 * to stay where they are, so the Like or Follow they started carries on.
 */
class SignedInResponse implements LoginResponse, RegisterResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $intended]);
        }

        return redirect()->to($intended ?? url()->previous('/'));
    }
}
