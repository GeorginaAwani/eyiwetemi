<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Usernames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live check beside the Username field: free, taken (with close ones that are free), or not allowed.
 */
class UsernameController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'u' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(Usernames::check($request->string('u')->toString(), $request->string('name')->toString(), $request->user()));
    }
}
