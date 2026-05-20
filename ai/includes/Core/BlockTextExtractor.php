<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Extracts translatable text from WordPress block comment attributes,
 * replacing each value with a __WPAI_N__ placeholder before translation
 * and reinserting the translated strings afterwards.
 *
 * Flow:
 *   1. extract()          — replace translatable block attr values with __WPAI_N__
 *   2. Translation API call (placeholders survive unchanged)
 *   3. reinsert()         — swap __WPAI_N__ placeholders for translated attr strings
 *   4. strip_interblock_br() — remove hallucinated <br> tags between block boundaries
 */
class BlockTextExtractor {

    /**
     * Attribute names known to carry user-visible, translatable text.
     * Checked at every depth of the block tree.
     *
     * @var string[]
     */
    private const TRANSLATABLE_ATTRS = [
        'summary',      // wp:details — accordion / disclosure summary
        'alt',          // wp:image, wp:media-text — image alternative text
        'caption',      // wp:image — caption stored in block attrs
        'label',        // wp:search, wp:navigation-link — input or nav label
        'placeholder',  // wp:search — input placeholder text
        'buttonText',   // wp:search, wp:file — button label
        'title',        // wp:rss and various plugin blocks — display title
        'description',  // various plugin blocks — visible description text
    ];

    // ── Block attribute extraction / reinsertion ──────────────────────────────

    /**
     * Walk the parsed block tree, replace every translatable attribute value
     * with a __WPAI_N__ placeholder, and return the re-serialised content
     * together with the extraction map.
     *
     * @param  string $content  Raw WordPress post_content string.
     * @return array{0: string, 1: array<string, string>}
     */
    public static function extract(string $content): array {

        if (!function_exists('parse_blocks')) {
            return [$content, []];
        }

        $map    = [];
        $index  = 0;
        $blocks = parse_blocks($content);

        self::walk($blocks, $map, $index);

        if (empty($map)) {
            return [$content, []];
        }

        return [serialize_blocks($blocks), $map];
    }

    /**
     * Replace __WPAI_N__ placeholders in content with their translated values.
     *
     * Values are JSON-escaped before substitution because the placeholders sit
     * inside JSON string fields within block comment attributes.
     *
     * @param  string               $content      Content containing placeholders.
     * @param  array<string,string> $translations Placeholder → translated string.
     * @return string
     */
    public static function reinsert(string $content, array $translations): string {

        if (empty($translations)) {
            return $content;
        }

        foreach ($translations as $placeholder => $translated) {

            $json_escaped = substr(
                (string) wp_json_encode((string) $translated, JSON_UNESCAPED_UNICODE),
                1,
                -1
            );

            $content = str_replace((string) $placeholder, $json_escaped, $content);
        }

        return $content;
    }

    // ── Inter-block <br> stripping ────────────────────────────────────────────

    /**
     * Remove <br> tags the AI model hallucinated between Gutenberg block
     * boundaries, while leaving legitimate <br> tags inside block HTML intact.
     *
     * @param  string $content  Raw Gutenberg post content.
     * @return string           Content with inter-block <br> tags removed.
     */
    public static function strip_interblock_br(string $content): string {

        $parts = preg_split(
            '/(<!--\s*\/?wp:[^>]*?>)/i',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return $content;
        }

        $strip = true;

        foreach ($parts as $i => &$part) {

            if ($i % 2 === 1) {
                $strip = (bool) preg_match('/<!--\s*\/wp:|\/-->/i', $part);
            } elseif ($strip) {
                $part = preg_replace('/<br[\s\/]*>/i', '', $part);
            }
        }
        unset($part);

        return implode('', $parts);
    }

    /**
     * Recursively walk a parsed block array, replacing translatable attribute
     * string values with __WPAI_N__ tokens.
     *
     * @param array[] $blocks  Parsed block array, passed by reference.
     * @param array   $map     Extraction map, passed by reference.
     * @param int     $index   Running placeholder index, passed by reference.
     */
    private static function walk(array &$blocks, array &$map, int &$index): void {

        foreach ($blocks as &$block) {

            foreach (self::TRANSLATABLE_ATTRS as $attr) {

                if (
                    isset($block['attrs'][$attr])
                    && is_string($block['attrs'][$attr])
                    && trim($block['attrs'][$attr]) !== ''
                ) {
                    $placeholder           = '__WPAI_' . $index . '__';
                    $map[$placeholder]     = $block['attrs'][$attr];
                    $block['attrs'][$attr] = $placeholder;
                    $index++;
                }
            }

            if (!empty($block['innerBlocks'])) {
                self::walk($block['innerBlocks'], $map, $index);
            }
        }
    }
}
