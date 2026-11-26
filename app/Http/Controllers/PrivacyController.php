<?php

namespace App\Http\Controllers;

use App\Enums\SitePageKey;
use App\Models\SitePage;
use App\Support\SafeHtml;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * Privacy: her sections with contents beside them.
     */
    public function __invoke(): Response
    {
        $privacy = SitePage::query()->firstWhere('key', SitePageKey::Privacy)?->live;

        abort_unless(is_array($privacy) && ! empty($privacy['sections']), 404);

        return Inertia::render('Privacy', [
            'updated' => isset($privacy['updated_on']) ? Carbon::parse($privacy['updated_on'])->format('j F Y') : null,
            'sections' => array_map(fn (array $section): array => [
                'id' => (string) $section['id'],
                'heading' => (string) $section['heading'],
                'html' => SafeHtml::clean($section['html'] ?? ''),
            ], $privacy['sections']),
        ]);
    }
}
