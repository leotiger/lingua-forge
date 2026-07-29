<?php
/**
 * Class LinguaForge\AI\Features\CommentTranslation
 *
 * AI-facing orchestration for generic comment translation: resolves which
 * sibling languages a canonical comment is still missing a mirror for,
 * detects the comment's own written language via LanguageDetector when not
 * already known, translates the plain text, and hands the result to
 * `LinguaForge\Router\Comments\CommentMirror::create_or_update_mirror()` to
 * actually create/update the mirror row. This class never touches
 * wp_comments directly — CommentMirror owns the data model and mirroring
 * mechanics (same ai/ vs language-router/ split as Translation vs Sync).
 *
 * Deliberately simpler than the post-content Translation feature: comment
 * content is plain text, so none of BlockTextExtractor's block-markup
 * preservation, Translation Memory, or JSON-envelope machinery is needed —
 * a single plain-text chat() call per target language.
 *
 * @package LinguaForge\AI\Features
 * @since   2.7.0
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Log;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Router;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

class CommentTranslation {

	/** UsageRecorder feature key this call is tracked under in the AI Usage log. */
	private const USAGE_KEY = 'comment_translation';

	/** Consecutive failures a (comment, lang) pair gets before it's put in cooldown — same shape as TranslationBackfill. */
	private const MAX_ATTEMPTS = 5;

	/** How long a pair that hit MAX_ATTEMPTS is left alone before one more try. */
	private const COOLDOWN_SECONDS = DAY_IN_SECONDS;

	private static function mirror(): CommentMirror {
		return Router::get_instance()->comment_mirror;
	}

	/**
	 * Translates one canonical comment into every currently-missing sibling
	 * language, or into an explicit $target_langs list when given (used by
	 * the manual "Translate missing" bulk action, which already knows the
	 * missing set from CommentMirror::find_backfill_candidates()).
	 *
	 * @param int         $comment_id  The canonical comment's ID.
	 * @param string[]|null $target_langs Explicit target languages, or null
	 *                                    to auto-resolve every missing one.
	 * @return array{translated: string[], failed: array<string,string>}
	 */
	public static function translate_comment( int $comment_id, ?array $target_langs = null ): array {
		$result = [ 'translated' => [], 'failed' => [] ];

		$mirror  = self::mirror();
		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof WP_Comment ) {
			return $result;
		}
		if ( ! $mirror->is_eligible_comment( $comment ) ) {
			return $result;
		}

		$router       = Router::get_instance();
		$post_id      = (int) $comment->comment_post_ID;
		$own_lang     = $router->get_lang( $post_id );
		$translations = $router->get_translations( $post_id );
		$group_id     = $mirror->get_group_id( $comment_id );

		if ( null === $target_langs ) {
			$target_langs = [];
			foreach ( $translations as $lang => $sibling_id ) {
				if ( $lang === $own_lang ) {
					continue;
				}
				$sibling_id = (int) $sibling_id;
				if ( 0 === $sibling_id || $sibling_id === $post_id ) {
					continue;
				}
				if ( 0 === $mirror->sibling_row_id( $group_id, $sibling_id ) ) {
					$target_langs[] = $lang;
				}
			}
		}

		if ( empty( $target_langs ) ) {
			return $result;
		}

		// ── Source language: detect once, cache on the comment itself ────────
		// Unlike every other LF translation flow, a comment's own language
		// isn't known ahead of time regardless of which localized page it was
		// submitted from — see LanguageDetector's own docblock.
		$source_lang = $mirror->get_source_lang( $comment_id );
		if ( '' === $source_lang ) {
			$candidate_langs = array_values( array_unique( array_merge( [ $own_lang ], array_keys( $translations ) ) ) );
			$source_lang     = LanguageDetector::detect( $comment->comment_content, $candidate_langs );
			if ( '' === $source_lang ) {
				// Undetectable/inconclusive — assume the page's own language
				// rather than blocking translation entirely.
				$source_lang = $own_lang;
			}
			$mirror->set_source_lang( $comment_id, $source_lang );
		}

		foreach ( $target_langs as $lang ) {
			$sibling_id = (int) ( $translations[ $lang ] ?? 0 );
			if ( 0 === $sibling_id ) {
				continue;
			}

			if ( $lang === $source_lang ) {
				// Same language as the comment itself — mirror verbatim, no AI call.
				$mirror_id = $mirror->create_or_update_mirror( $comment, $lang, $sibling_id, $comment->comment_content );
				if ( $mirror_id > 0 ) {
					$result['translated'][] = $lang;
					self::clear_failure( $comment_id, $lang );
				}
				continue;
			}

			if ( self::in_cooldown( $comment_id, $lang ) ) {
				continue;
			}

			$translated_text = self::translate_text( $comment->comment_content, $lang );

			if ( '' === $translated_text ) {
				$result['failed'][ $lang ] = 'translation failed';
				self::record_failure( $comment_id, $lang, 'translation failed' );
				continue;
			}

			$mirror_id = $mirror->create_or_update_mirror( $comment, $lang, $sibling_id, $translated_text );

			if ( $mirror_id > 0 ) {
				$result['translated'][] = $lang;
				self::clear_failure( $comment_id, $lang );
			} else {
				// Nested reply with no parent mirror on this sibling yet —
				// not a real failure, just not ready. Left for a later pass
				// rather than recorded as a cooldown-triggering failure.
				$result['failed'][ $lang ] = 'parent has no mirror on this sibling yet';
			}
		}

		if ( ! empty( $result['translated'] ) || ! empty( $result['failed'] ) ) {
			/**
			 * Fires after a comment-translation attempt completes (whether or
			 * not every target language succeeded).
			 *
			 * @param int                  $comment_id The canonical comment's ID.
			 * @param array{translated: string[], failed: array<string,string>} $result
			 */
			do_action( 'linguaforge_comment_translation_complete', $comment_id, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
		}

		return $result;
	}

	// =========================================================
	// AI CALL
	// =========================================================

	private static function translate_text( string $text, string $target_lang ): string {
		$languages   = Translation::get_languages();
		$target_name = $languages[ $target_lang ] ?? $target_lang;

		$config = new WorkerConfig(
			model: Config::model( Config::translation_tier() ),
			max_tokens: 1024,
			temperature: 0.3,
		);

		$provider = ProviderFactory::make( $config );

		$system = sprintf(
			'Translate the visitor comment below into %s. Preserve tone, meaning, ' .
			'and formatting (line breaks). Output ONLY the translated text — no ' .
			'quotes, no preamble, no explanation.',
			$target_name
		);

		$output = UsageRecorder::tracked( self::USAGE_KEY, static fn () => $provider->chat( [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $text ],
		] ) );

		if ( null === $output ) {
			Log::debug( 'CommentTranslation: provider call failed — ' . $provider->get_last_error() );
			return '';
		}

		return trim( $output );
	}

	// =========================================================
	// FAILURE / RETRY BOOKKEEPING
	// Mirrors TranslationBackfill's post-level pattern, scoped to the
	// comment itself via CommentMirror::META_FAILURES.
	// =========================================================

	public static function record_failure( int $comment_id, string $target_lang, string $error_message ): void {
		$failures = self::get_failure_state( $comment_id );
		$prior    = $failures[ $target_lang ] ?? [ 'attempts' => 0 ];

		$failures[ $target_lang ] = [
			'attempts'     => (int) $prior['attempts'] + 1,
			'last_attempt' => time(),
			'last_error'   => $error_message,
		];

		update_comment_meta( $comment_id, CommentMirror::META_FAILURES, $failures );
	}

	public static function clear_failure( int $comment_id, string $target_lang ): void {
		$failures = self::get_failure_state( $comment_id );
		if ( ! isset( $failures[ $target_lang ] ) ) {
			return;
		}

		unset( $failures[ $target_lang ] );

		if ( empty( $failures ) ) {
			delete_comment_meta( $comment_id, CommentMirror::META_FAILURES );
		} else {
			update_comment_meta( $comment_id, CommentMirror::META_FAILURES, $failures );
		}
	}

	private static function in_cooldown( int $comment_id, string $target_lang ): bool {
		$failures = self::get_failure_state( $comment_id );
		$entry    = $failures[ $target_lang ] ?? null;

		if ( ! is_array( $entry ) ) {
			return false;
		}
		if ( (int) ( $entry['attempts'] ?? 0 ) < self::MAX_ATTEMPTS ) {
			return false;
		}

		return ( time() - (int) ( $entry['last_attempt'] ?? 0 ) ) < self::COOLDOWN_SECONDS;
	}

	/**
	 * @return array<string,array{attempts:int,last_attempt:int,last_error:string}>
	 */
	private static function get_failure_state( int $comment_id ): array {
		$failures = get_comment_meta( $comment_id, CommentMirror::META_FAILURES, true );
		return is_array( $failures ) ? $failures : [];
	}
}
