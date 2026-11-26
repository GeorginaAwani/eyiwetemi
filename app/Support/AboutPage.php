<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Models\Media;
use App\Models\SitePage;

/**
 * About me, from her live About page: the hello beside her portrait, then her sections
 * with their margin notes, and the books that made her.
 */
class AboutPage
{
    /**
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        $about = SitePage::query()->firstWhere('key', SitePageKey::About)?->live;

        if (! is_array($about) || ! isset($about['hello'])) {
            return null;
        }

        $media = isset($about['image']) ? Media::query()->select(['id', 'disk', 'path', 'alt'])->find($about['image']) : null;

        return [
            'portrait' => $media ? ['src' => WorkPresenter::mediaUrl($media->disk, $media->path), 'alt' => (string) ($media->alt ?? '')] : null,
            'hello' => [
                'heading' => trim((string) $about['hello']['heading']),
                'paragraphs' => array_values(array_filter(array_map('trim', $about['hello']['paragraphs'] ?? []), fn (string $p): bool => $p !== '')),
            ],
            'sections' => array_map(fn (array $section): array => [
                'heading' => (string) $section['heading'],
                'paragraphs' => array_values(array_map(fn (array $paragraph): array => [
                    'text' => trim((string) $paragraph['text']),
                    'note' => trim((string) ($paragraph['margin_note'] ?? '')) ?: null,
                    'quote' => (bool) ($paragraph['pull_quote'] ?? false),
                ], array_filter($section['paragraphs'] ?? [], fn (array $paragraph): bool => trim((string) ($paragraph['text'] ?? '')) !== ''))),
            ], $about['sections'] ?? []),
            'books' => trim((string) ($about['books'] ?? '')) ?: null,
        ];
    }
}
