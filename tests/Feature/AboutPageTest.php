<?php

namespace Tests\Feature;

use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_shows_her_live_hello_sections_notes_and_books(): void
    {
        SitePage::factory()->about()->create(['live' => [
            'image' => null,
            'hello' => ['heading' => 'I’m Georgina. ', 'paragraphs' => ['I write.', ' ']],
            'sections' => [[
                'id' => 's1',
                'heading' => 'How I got here',
                'paragraphs' => [
                    ['text' => 'Songs first.', 'margin_note' => 'Some rhymed.', 'pull_quote' => false],
                    ['text' => '  ', 'margin_note' => 'Dropped', 'pull_quote' => false],
                    ['text' => 'I overthink.', 'margin_note' => null, 'pull_quote' => true],
                ],
            ]],
            'books' => 'Red Rising.',
            'home' => ['quote' => 'q', 'line' => 'l'],
        ]]);

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('About')
            ->where('portrait', null)
            ->where('hello', ['heading' => 'I’m Georgina.', 'paragraphs' => ['I write.']])
            ->where('sections.0.paragraphs', [
                ['text' => 'Songs first.', 'note' => 'Some rhymed.', 'quote' => false],
                ['text' => 'I overthink.', 'note' => null, 'quote' => true],
            ])
            ->where('books', 'Red Rising.')
        );
    }

    public function test_about_is_not_found_until_she_has_published_it(): void
    {
        SitePage::factory()->about()->create(['live' => null]);

        $this->get('/about')->assertNotFound();
    }
}
