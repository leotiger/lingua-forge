<?php
/**
 * Class LinguaForge\Router\Search\Index
 *
 * Builds and stores a plain-text search index for each post
 * (the _search_content post-meta) by recursively extracting text from
 * block content at save time.
 */

namespace LinguaForge\Router\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

class Index {

	// =========================================================
	// SEARCH INDEX BUILD
	// =========================================================

	public function build_search_content( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) return;

		$blocks = parse_blocks( $post->post_content );
		$text   = '';

		foreach ( $blocks as $block ) {
			$text .= $this->extract_block_text( $block ) . ' ';
		}

		update_post_meta( $post_id, '_search_content', trim( $text ) );
	}

	public function extract_block_text( array $block ): string {
		$name    = $block['blockName'] ?? '';
		$inner   = $block['innerBlocks'] ?? [];
		$content = '';

		if ( $name === 'core/details' ) {
			if ( ! empty( $block['attrs']['summary'] ) ) {
				$content .= ' ' . $block['attrs']['summary'];
			}
			foreach ( $inner as $child ) {
				$text = $this->extract_block_text( $child );
				if ( ! empty( trim( $text ) ) ) {
					$content .= ' ' . $text;
					if ( strlen( $content ) > 1000 ) break;
				}
			}
			return trim( $content );
		}

		if ( in_array( $name, [ 'core/paragraph', 'core/heading', 'core/list' ], true ) ) {
			return wp_strip_all_tags( implode( ' ', $block['innerContent'] ) );
		}

		if ( in_array( $name, [ 'core/gallery', 'core/image', 'core/cover', 'core/columns', 'core/group', 'core/spacer' ], true ) ) {
			return '';
		}

		foreach ( $inner as $child ) {
			$content .= $this->extract_block_text( $child ) . ' ';
		}

		return $content;
	}
}
