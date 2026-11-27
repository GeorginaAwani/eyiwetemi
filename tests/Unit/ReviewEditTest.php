<?php

namespace Tests\Unit;

use App\Support\ReviewEdit;
use PHPUnit\Framework\TestCase;

class ReviewEditTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function doc(array $nodes, string $blockId = 'b1'): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => $blockId], 'content' => $nodes]]];
    }

    public function test_a_deleted_text_inside_one_text_node_is_replaced(): void
    {
        $doc = $this->doc([['type' => 'text', 'text' => 'She stood at the door and waited.']]);

        $edited = ReviewEdit::apply($doc, ['block_id' => 'b1', 'start' => 13, 'end' => 21, 'quote' => 'the door'], 'the door', 'that gate');

        $this->assertSame([['type' => 'text', 'text' => 'She stood at that gate and waited.']], $edited['content'][0]['content']);
    }

    public function test_a_deleted_text_across_a_bold_boundary_is_replaced_and_the_emptied_bold_node_goes(): void
    {
        $doc = $this->doc([
            ['type' => 'text', 'text' => 'He stood by the '],
            ['type' => 'text', 'text' => 'door', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' and waited.'],
        ]);

        $edited = ReviewEdit::apply($doc, ['block_id' => 'b1', 'start' => 12, 'end' => 20, 'quote' => 'the door'], 'the door', 'that gate');

        $this->assertSame([
            ['type' => 'text', 'text' => 'He stood by that gate'],
            ['type' => 'text', 'text' => ' and waited.'],
        ], $edited['content'][0]['content']);
    }

    public function test_the_inserted_text_takes_the_marks_of_the_node_the_deletion_starts_in(): void
    {
        $doc = $this->doc([
            ['type' => 'text', 'text' => 'A '],
            ['type' => 'text', 'text' => 'big door', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' open'],
        ]);

        $edited = ReviewEdit::apply($doc, ['block_id' => 'b1', 'start' => 6, 'end' => 15, 'quote' => 'door open'], 'door open', 'gate shut');

        $this->assertSame([
            ['type' => 'text', 'text' => 'A '],
            ['type' => 'text', 'text' => 'big gate shut', 'marks' => [['type' => 'bold']]],
        ], $edited['content'][0]['content']);
    }

    public function test_the_occurrence_at_the_anchor_is_the_one_replaced(): void
    {
        $doc = $this->doc([['type' => 'text', 'text' => 'the cat and the cat']]);

        $edited = ReviewEdit::apply($doc, ['block_id' => 'b1', 'start' => 12, 'end' => 19, 'quote' => 'the cat'], 'the cat', 'a dog');

        $this->assertSame([['type' => 'text', 'text' => 'the cat and a dog']], $edited['content'][0]['content']);
    }

    public function test_a_text_that_is_not_there_gives_null(): void
    {
        $doc = $this->doc([['type' => 'text', 'text' => 'She stood by the window.']]);

        $this->assertNull(ReviewEdit::apply($doc, ['block_id' => 'b1', 'start' => 0, 'end' => 8, 'quote' => 'from the door'], 'from the door', 'from the doorway'));
    }

    public function test_a_block_that_is_not_there_gives_null(): void
    {
        $doc = $this->doc([['type' => 'text', 'text' => 'She stood at the door.']]);

        $this->assertNull(ReviewEdit::apply($doc, ['block_id' => 'b9', 'start' => 0, 'end' => 3, 'quote' => 'She'], 'She', 'He'));
    }
}
