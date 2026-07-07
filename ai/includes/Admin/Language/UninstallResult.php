<?php
/**
 * Value object returned by LanguageUninstaller::uninstall().
 *
 * Carries counts and path lists so the RouterTab handler can build a
 * descriptive redirect notice without coupling to the deletion logic.
 *
 * @package LinguaForge\AI\Admin\Language
 * @since   2.1.8
 */

namespace LinguaForge\AI\Admin\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of a language uninstall operation.
 *
 * All properties are public readonly (PHP 8.1+) — set once at construction,
 * never mutated.
 */
class UninstallResult {

	/**
	 * @param int      $posts_deleted
	 * @param string[] $files_deleted
	 * @param string[] $files_skipped
	 * @param bool     $mods_allowed
	 * @param int      $patterns_deleted  Number of CPT Block Pattern translations
	 *                                    removed from the `linguaforge_pattern_translations`
	 *                                    option (patterns are stored there, not as
	 *                                    posts, so they're not part of $posts_deleted).
	 */
	public function __construct(
		public readonly int   $posts_deleted,
		public readonly array $files_deleted,
		public readonly array $files_skipped,
		public readonly bool  $mods_allowed,
		public readonly int   $patterns_deleted = 0,
	) {}
}
