<?php

namespace Database\Factories;

/**
 * Builds an editor body (ProseMirror/Tiptap doc) with its plain text and word count.
 */
class EditorDocument
{
    /**
     * @param  list<string>|null  $paragraphs
     * @return array{body: array<string, mixed>, text: string, words: int}
     */
    public static function make(?array $paragraphs = null): array
    {
        $paragraphs ??= fake()->paragraphs(fake()->numberBetween(2, 5), false);

        $content = [];

        foreach (array_values($paragraphs) as $index => $paragraph) {
            $content[] = [
                'type' => 'paragraph',
                'attrs' => ['id' => 'b'.($index + 1)],
                'content' => [['type' => 'text', 'text' => $paragraph]],
            ];
        }

        $text = implode("\n\n", $paragraphs);

        return [
            'body' => ['type' => 'doc', 'content' => $content],
            'text' => $text,
            'words' => count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []),
        ];
    }
}
