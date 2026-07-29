<?php
/**
 * Class LinguaForge\Router\Comments\CommentMirror
 *
 * Generic WP-comment translation — data model, mirror-row engine, and status
 * cascade. See lingua-forge-audit/PROPOSAL-comment-translation-2026-07-29.md
 * for the full design rationale (mirrored real comment rows, not a shared
 * pool; a comment-scoped group key, deliberately NOT `_lf_trid`; WooCommerce
 * product reviews excluded — `ProductReviewRouter` already owns those via a
 * different, shared-pool model).
 *
 * This class owns the data model and the mirror/cascade mechanics; it has no
 * AI dependency (mirrors the Sync/TrashCascade split from Translation/
 * TranslationQueue). The actual "detect language, call the AI translator"
 * orchestration lives in `LinguaForge\AI\Features\CommentTranslation` /
 * `CommentTranslationQueue`, which call back into this class's
 * `create_or_update_mirror()` once translated text is in hand.
 *
 * Terminology: a "canonical" comment is the original, visitor-submitted (or
 * otherwise organically created) comment — its own group-id meta equals its
 * own comment ID. A "mirror" is a real, already-approved WP_Comment row this
 * class creates on a sibling post, holding translated content, whose
 * group-id meta points back at the canonical comment's ID. Every row in a
 * group (canonical + all mirrors) shares that same group-id value.
 *
 * @package LinguaForge\Router\Comments
 * @since   2.7.0
 */

namespace LinguaForge\Router\Comments;

use LinguaForge\Router\Router;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) exit;

class CommentMirror {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// META KEYS
	// =========================================================

	/** This comment's own detected/known written language (comment meta). */
	public const META_LANG = '_lf_comment_lang';

	/**
	 * Shared by a canonical comment and every mirror of it. Set once, at
	 * insertion time, to the canonical comment's own ID — a row whose value
	 * differs from its own comment ID is itself already a mirror (comment
	 * meta). Deliberately NOT `_lf_trid`: that's a postmeta UUID scoped to a
	 * whole translation family of POSTS, and a single post family hosts many
	 * independent comments/replies, each needing its own group — see the
	 * proposal doc's "Design decisions already made" section.
	 */
	public const META_GROUP_ID = '_lf_comment_group_id';

	/**
	 * Per-target-language failure state, JSON-array shaped like
	 * `_lf_translation_failures` (post-level, TranslationBackfill), but
	 * scoped to the comment itself rather than a source post (comment meta).
	 */
	public const META_FAILURES = '_lf_comment_translation_failures';

	// =========================================================
	// SCOPE / ELIGIBILITY
	// =========================================================

	/**
	 * Post types this feature never touches, regardless of the
	 * `linguaforge_comment_translation_excluded_types` filter's own output —
	 * a defensive hard-block, not just a default. WooCommerce product
	 * reviews already have a working, shipped, shared-pool model
	 * (`ProductReviewRouter`); mirroring them here as well would double-handle
	 * the exact same comment.
	 *
	 * @var string[]
	 */
	private const HARD_EXCLUDED_POST_TYPES = [ 'product', 'product_variation' ];

	/** Safety cap on how many candidates one backfill scan pass considers. */
	private const BACKFILL_SCAN_LIMIT = 200;

	public static function feature_enabled(): bool {
		return (bool) get_option( 'linguaforge_comment_translation_enabled', false );
	}

	/** 'manual' (default) or 'auto'. See Settings → Behavior → Comment Translation. */
	public static function mode(): string {
		$mode = (string) get_option( 'linguaforge_comment_translation_mode', 'manual' );
		return 'auto' === $mode ? 'auto' : 'manual';
	}

	/** Original comment = level 0. Default 2 → levels 0–2 backfill by default. */
	public static function max_backfill_depth(): int {
		return max( 0, (int) get_option( 'linguaforge_comment_translation_max_backfill_depth', 2 ) );
	}

	/**
	 * Post types eligible for comment translation — every public post type
	 * except the hard-excluded WooCommerce ones above, minus anything the
	 * `linguaforge_comment_translation_excluded_types` filter also removes.
	 */
	public function is_post_type_excluded( string $post_type ): bool {
		if ( in_array( $post_type, self::HARD_EXCLUDED_POST_TYPES, true ) ) {
			return true;
		}

		/**
		 * Filters which post types are excluded from generic comment translation.
		 *
		 * @param string[] $excluded Post type slugs to exclude. Empty by default —
		 *                           the WooCommerce hard-exclusion above already
		 *                           covers the one known case; this is the
		 *                           extension point for anything else (e.g. an
		 *                           integration's own custom post type whose
		 *                           comments it already handles a different way).
		 */
		$excluded = (array) apply_filters( 'linguaforge_comment_translation_excluded_types', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return in_array( $post_type, $excluded, true );
	}

	/**
	 * Comment types eligible for translation — allow-list, not a block-list:
	 * only ordinary visitor comments by default. WooCommerce's `review` type
	 * is therefore excluded simply by never being in the default list, with
	 * no need for a parallel hard-block the way post types need one (a
	 * filter would have to actively opt 'review' in, which the guard below
	 * still refuses).
	 */
	public function is_comment_type_eligible( string $comment_type ): bool {
		if ( 'review' === $comment_type ) {
			return false; // Always routed through ProductReviewRouter instead — never here.
		}

		/**
		 * Filters which comment_type values are eligible for translation.
		 *
		 * @param string[] $types Eligible comment_type values. Default: ['comment'].
		 */
		$types = (array) apply_filters( 'linguaforge_comment_translation_eligible_types', [ 'comment' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return in_array( $comment_type, $types, true );
	}

	/**
	 * Whether $comment is a candidate for this feature at all — approved,
	 * an eligible comment_type, on an eligible post type, and not itself
	 * already a mirror row. Does NOT require the post to currently have any
	 * sibling translations — a comment can be tagged with a group ID long
	 * before any sibling post exists; mirrors are only ever created for
	 * siblings that actually exist at mirror-creation time (see
	 * create_or_update_mirror()).
	 */
	public function is_eligible_comment( WP_Comment $comment ): bool {
		if ( ! self::feature_enabled() ) {
			return false;
		}

		if ( '1' !== (string) $comment->comment_approved ) {
			return false;
		}

		if ( ! $this->is_comment_type_eligible( $comment->comment_type ) ) {
			return false;
		}

		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post || $this->is_post_type_excluded( $post->post_type ) ) {
			return false;
		}

		return $this->is_canonical( (int) $comment->comment_ID );
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Assign the group ID at insertion time, for every comment (cheap —
		// a single get_comment_meta/update_comment_meta pair — and gated
		// internally on eligibility so ineligible comments never get one).
		add_action( 'wp_insert_comment', [ $this, 'handle_new_comment' ], 10, 2 );

		// Cascade a status change (approve / unapprove / spam / trash) across
		// every other row sharing this comment's group — reentrancy-guarded
		// below so cascading itself doesn't recurse.
		add_action( 'transition_comment_status', [ $this, 'handle_status_transition' ], 10, 3 );
	}

	/**
	 * wp_insert_comment fires for every new comment row — including the
	 * mirror rows this class itself inserts via wp_insert_comment() in
	 * create_or_update_mirror(). is_eligible_comment()'s own is_canonical()
	 * check is what keeps this idempotent: a freshly-inserted mirror's group
	 * ID is set to the CANONICAL comment's ID (copied verbatim by
	 * create_or_update_mirror(), never left to default here), so it never
	 * equals the mirror's own comment ID and is therefore already
	 * "non-canonical" the moment this fires for it — ensure_group_id() below
	 * is a no-op for it.
	 */
	public function handle_new_comment( int $comment_id, $comment ): void {
		if ( ! $comment instanceof WP_Comment ) {
			$comment = get_comment( $comment_id );
		}
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		if ( ! self::feature_enabled() ) {
			return;
		}
		if ( ! $this->is_comment_type_eligible( $comment->comment_type ) ) {
			return;
		}
		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post || $this->is_post_type_excluded( $post->post_type ) ) {
			return;
		}

		$this->ensure_group_id( $comment_id );
	}

	// =========================================================
	// GROUP ID
	// =========================================================

	/** Assigns $comment_id as its own group ID if it doesn't already have one. Returns the (possibly pre-existing) group ID. */
	public function ensure_group_id( int $comment_id ): string {
		$existing = $this->get_group_id( $comment_id );
		if ( '' !== $existing ) {
			return $existing;
		}

		$group_id = (string) $comment_id;
		update_comment_meta( $comment_id, self::META_GROUP_ID, $group_id );
		return $group_id;
	}

	public function get_group_id( int $comment_id ): string {
		return (string) get_comment_meta( $comment_id, self::META_GROUP_ID, true );
	}

	/** A row whose group ID equals its own comment ID is canonical; anything else (including no group ID at all) is not. */
	public function is_canonical( int $comment_id ): bool {
		$group_id = $this->get_group_id( $comment_id );
		return '' !== $group_id && $group_id === (string) $comment_id;
	}

	/**
	 * Every comment row sharing $group_id — the canonical comment plus every
	 * mirror of it, across every sibling post it's been mirrored onto.
	 *
	 * @return int[]
	 */
	public function group_member_ids( string $group_id ): array {
		if ( '' === $group_id ) {
			return [];
		}

		$comments = get_comments( [
			'meta_key'   => self::META_GROUP_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, bounded result set (one reply thread's mirror group); no realistic alternative query shape.
			'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'status'     => 'any',
		] );

		if ( ! is_array( $comments ) ) {
			return [];
		}

		return array_map( static fn ( WP_Comment $c ): int => (int) $c->comment_ID, $comments );
	}

	/**
	 * Whether $group_id already has a representative row (canonical or
	 * mirror) living on $post_id — used both to dedupe mirror creation and
	 * to map a nested reply's parent onto the correct row on a given
	 * sibling.
	 *
	 * @return int Comment ID of the representative row, or 0 if none.
	 */
	public function sibling_row_id( string $group_id, int $post_id ): int {
		if ( '' === $group_id || $post_id <= 0 ) {
			return 0;
		}

		$comments = get_comments( [
			'post_id'    => $post_id,
			'meta_key'   => self::META_GROUP_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'status'     => 'any',
			'number'     => 1,
		] );

		if ( ! is_array( $comments ) || empty( $comments ) ) {
			return 0;
		}

		$first = reset( $comments );
		return $first instanceof WP_Comment ? (int) $first->comment_ID : 0;
	}

	// =========================================================
	// SOURCE LANGUAGE
	// =========================================================

	public function get_source_lang( int $comment_id ): string {
		return (string) get_comment_meta( $comment_id, self::META_LANG, true );
	}

	public function set_source_lang( int $comment_id, string $lang ): void {
		update_comment_meta( $comment_id, self::META_LANG, $lang );
	}

	/**
	 * `[ lang => comment_id ]` map for every row in $comment_id's mirror
	 * group — the comment-level analog of `TridGroup::get_translations()`.
	 * Public API behind `linguaforge_get_comment_translations()`. Works
	 * whether $comment_id is the canonical comment or one of its mirrors.
	 *
	 * @return array<string, int>
	 */
	public function get_comment_translations_map( int $comment_id ): array {
		$group_id = $this->get_group_id( $comment_id );
		if ( '' === $group_id ) {
			return [];
		}

		$map = [];
		foreach ( $this->group_member_ids( $group_id ) as $member_id ) {
			$lang = $this->get_source_lang( $member_id );

			if ( '' === $lang ) {
				$member = get_comment( $member_id );
				if ( $member instanceof WP_Comment ) {
					$lang = $this->router->get_lang( (int) $member->comment_post_ID );
				}
			}

			if ( '' !== $lang ) {
				$map[ $lang ] = $member_id;
			}
		}

		return $map;
	}

	// =========================================================
	// NESTED-REPLY DEPTH
	// =========================================================

	/** Original top-level comment = level 0; each comment_parent hop adds 1. */
	public function comment_depth( int $comment_id ): int {
		$depth   = 0;
		$current = get_comment( $comment_id );
		$seen    = [];

		while ( $current instanceof WP_Comment && (int) $current->comment_parent > 0 ) {
			$parent_id = (int) $current->comment_parent;

			// Cycle guard — a malformed parent chain must not spin forever.
			if ( isset( $seen[ $parent_id ] ) ) {
				break;
			}
			$seen[ $parent_id ] = true;

			$depth++;
			$current = get_comment( $parent_id );
		}

		return $depth;
	}

	// =========================================================
	// MIRROR CREATION
	// =========================================================

	/**
	 * Creates (or updates, if one already exists — idempotent) a translated
	 * mirror of $canonical on $sibling_post_id. Called by
	 * `CommentTranslation`/`CommentTranslationQueue` once translated text is
	 * in hand — this class never calls the AI translator itself.
	 *
	 * For a nested reply, maps comment_parent to whatever row already
	 * represents that parent's own group on $sibling_post_id
	 * (sibling_row_id()) — skips silently (returns 0) if the parent has no
	 * representative row there yet, exactly Agnosis's own
	 * find_mirror_on_sibling() precedent. For a top-level comment,
	 * comment_parent is simply 0 on every mirror too.
	 *
	 * Uses wp_insert_comment() directly (not wp_new_comment()) so mirror
	 * creation never fires moderation notification emails and — critically —
	 * never triggers wp_transition_comment_status(), which would otherwise
	 * make this class's own transition_comment_status handler see the fresh
	 * mirror as "just approved" and attempt to translate/mirror it too,
	 * recursing indefinitely. is_eligible_comment()'s is_canonical() check
	 * is the second, independent guard against that: a mirror's group ID is
	 * always the CANONICAL comment's ID, never its own, so even code that
	 * reaches a mirror some other way still sees it as ineligible.
	 *
	 * @param WP_Comment $canonical         The original comment being mirrored.
	 * @param string     $target_lang       Language code the mirror is being created for.
	 * @param int        $sibling_post_id   The translated post to attach the mirror to.
	 * @param string     $translated_content Already-translated comment_content.
	 * @return int  The mirror's comment ID (new or existing), or 0 if skipped
	 *              (no parent mirror yet on this sibling for a nested reply).
	 */
	public function create_or_update_mirror( WP_Comment $canonical, string $target_lang, int $sibling_post_id, string $translated_content ): int {
		$group_id = $this->ensure_group_id( (int) $canonical->comment_ID );

		$mirror_parent_id = 0;
		$parent_id        = (int) $canonical->comment_parent;
		if ( $parent_id > 0 ) {
			$mirror_parent_id = $this->sibling_row_id( $this->get_group_id( $parent_id ), $sibling_post_id );
			if ( 0 === $mirror_parent_id ) {
				return 0; // Parent has no representative row on this sibling yet — try again on a later backfill pass.
			}
		}

		$existing_id = $this->sibling_row_id( $group_id, $sibling_post_id );
		if ( $existing_id > 0 ) {
			wp_update_comment( [
				'comment_ID'      => $existing_id,
				'comment_content' => $translated_content,
			] );
			update_comment_meta( $existing_id, self::META_LANG, $target_lang );
			return $existing_id;
		}

		$mirror_id = wp_insert_comment( [
			'comment_post_ID'      => $sibling_post_id,
			'comment_author'       => $canonical->comment_author,
			'comment_author_email' => $canonical->comment_author_email,
			'comment_author_url'   => $canonical->comment_author_url,
			'comment_content'      => $translated_content,
			'comment_type'         => $canonical->comment_type,
			'comment_parent'       => $mirror_parent_id,
			'user_id'              => (int) $canonical->user_id,
			'comment_approved'     => 1,
			'comment_date'         => $canonical->comment_date,
			'comment_date_gmt'     => $canonical->comment_date_gmt,
			'comment_agent'        => 'lingua-forge-comment-mirror',
		] );

		if ( ! $mirror_id ) {
			return 0;
		}

		update_comment_meta( $mirror_id, self::META_GROUP_ID, $group_id );
		update_comment_meta( $mirror_id, self::META_LANG, $target_lang );

		return (int) $mirror_id;
	}

	// =========================================================
	// STATUS CASCADE
	// =========================================================

	/**
	 * Reentrancy guard, keyed by group ID — prevents cascade_status() from
	 * recursing into itself via the transition_comment_status events its own
	 * wp_set_comment_status() calls fire.
	 *
	 * @var array<string,true>
	 */
	private array $cascading = [];

	/**
	 * Cascades an approve/unapprove/spam/trash status change across every
	 * OTHER row sharing $comment's group. A no-op for a group of size 1
	 * (nothing to cascade to yet — the common case for a brand-new comment
	 * before any mirrors exist).
	 */
	public function handle_status_transition( string $new_status, string $old_status, WP_Comment $comment ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		$group_id = $this->get_group_id( (int) $comment->comment_ID );
		if ( '' === $group_id ) {
			return;
		}

		if ( isset( $this->cascading[ $group_id ] ) ) {
			return;
		}
		$this->cascading[ $group_id ] = true;

		$target_status = match ( $new_status ) {
			'approved'   => 'approve',
			'unapproved' => 'hold',
			'spam'       => 'spam',
			'trash'      => 'trash',
			default      => '',
		};

		if ( '' !== $target_status ) {
			foreach ( $this->group_member_ids( $group_id ) as $member_id ) {
				if ( $member_id === (int) $comment->comment_ID ) {
					continue;
				}
				wp_set_comment_status( $member_id, $target_status );
			}
		}

		unset( $this->cascading[ $group_id ] );
	}

	// =========================================================
	// BACKFILL DISCOVERY ("Translate missing")
	// =========================================================

	/**
	 * Finds canonical, eligible, approved comments that are missing a mirror
	 * in at least one language their post currently has a real sibling for —
	 * the engine behind the "Translate missing" bulk action / recurring scan.
	 * Bounded both by BACKFILL_SCAN_LIMIT (how many canonical comments one
	 * pass examines) and by max_backfill_depth() (how deep into a reply
	 * thread a candidate may sit and still be considered).
	 *
	 * Does not itself call the AI translator or create anything — purely a
	 * read. `CommentTranslation`/`CommentTranslationQueue` consume the
	 * result and call create_or_update_mirror() per missing (comment, lang)
	 * pair once translated.
	 *
	 * @return array<int, array{comment: WP_Comment, missing_langs: string[]}>
	 */
	public function find_backfill_candidates( int $limit = self::BACKFILL_SCAN_LIMIT ): array {
		if ( ! self::feature_enabled() ) {
			return [];
		}

		$max_depth = self::max_backfill_depth();

		$eligible_types = (array) apply_filters( 'linguaforge_comment_translation_eligible_types', [ 'comment' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
		$eligible_types = array_values( array_diff( $eligible_types, [ 'review' ] ) );

		$candidates = get_comments( [
			'status' => 'approve',
			'type'   => $eligible_types,
			'number' => max( $limit, self::BACKFILL_SCAN_LIMIT ) * 3, // over-fetch: depth/exclusion filtering below happens in PHP.
			'order'  => 'ASC',
		] );

		$results = [];

		if ( ! is_array( $candidates ) ) {
			return $results;
		}

		foreach ( $candidates as $comment ) {
			if ( count( $results ) >= $limit ) {
				break;
			}
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}
			if ( ! $this->is_canonical( (int) $comment->comment_ID ) ) {
				continue; // Already a mirror, or never tagged (feature was off when it arrived).
			}

			$post = get_post( (int) $comment->comment_post_ID );
			if ( ! $post || $this->is_post_type_excluded( $post->post_type ) ) {
				continue;
			}

			if ( $this->comment_depth( (int) $comment->comment_ID ) > $max_depth ) {
				continue;
			}

			$own_lang     = $this->router->get_lang( (int) $post->ID );
			$translations = $this->router->get_translations( (int) $post->ID );

			$missing = [];
			foreach ( $translations as $lang => $sibling_id ) {
				if ( $lang === $own_lang ) {
					continue;
				}
				$sibling_id = (int) $sibling_id;
				if ( 0 === $sibling_id || $sibling_id === (int) $post->ID ) {
					continue;
				}
				if ( 0 === $this->sibling_row_id( $this->get_group_id( (int) $comment->comment_ID ), $sibling_id ) ) {
					$missing[] = $lang;
				}
			}

			if ( ! empty( $missing ) ) {
				$results[] = [
					'comment'       => $comment,
					'missing_langs' => $missing,
				];
			}
		}

		return $results;
	}
}
