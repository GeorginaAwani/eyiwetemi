<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ContactAdmin;
use App\Support\SocialNetworks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Her address, the footer's line and her handles on the networks she is on.
 */
class SiteContactController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        if (is_array($request->input('links'))) {
            $request->merge(['links' => array_map(
                fn (mixed $link): mixed => is_array($link) ? [...$link, 'handle' => trim(ltrim(trim((string) ($link['handle'] ?? '')), '@'))] : $link,
                $request->input('links'),
            )]);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:120'],
            'line' => ['nullable', 'string', 'max:120'],
            'links' => ['nullable', 'array', 'max:12'],
            'links.*.network' => ['required', 'string', Rule::in(SocialNetworks::names())],
            'links.*.handle' => ['nullable', 'string', 'max:120'],
        ]);

        ContactAdmin::save($data['email'], (string) ($data['line'] ?? ''), $data['links'] ?? []);

        return response()->json(['saved' => true]);
    }
}
