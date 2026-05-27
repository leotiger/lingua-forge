<?php

namespace LinguaForge\AI\CLI;

use LinguaForge\AI\Core\CacheStore;

defined('ABSPATH') || exit;

/**
 * `wp linguaforge cache_clear` — implementation.
 *
 * The user-facing docblock (## OPTIONS / ## EXAMPLES / @when) lives on the
 * matching method in \LinguaForge\AI\CLI\Commands so WP-CLI's command-help
 * introspection continues to render it. This class only holds the run-loop.
 *
 * Standalone — no translation pipeline involvement, so does not extend
 * AbstractTranslateCommand.
 */
class CacheClearCommand {

    public function execute( array $args, array $assoc_args ): void {

        $feature = isset( $assoc_args['feature'] ) ? sanitize_key( (string) $assoc_args['feature'] ) : '';
        $post_id = absint( $assoc_args['post-id'] ?? 0 );

        $criteria = [];
        if ( $feature !== '' ) {
            $criteria['feature_prefix'] = $feature;
        }
        if ( $post_id > 0 ) {
            $criteria['post_id'] = $post_id;
        }

        // Confirm before a whole-table truncate unless --yes is passed.
        if ( empty( $criteria ) ) {
            \WP_CLI::confirm( 'This will clear every AI-result cache entry. Proceed?', $assoc_args );
        }

        $count = CacheStore::clear( $criteria );

        $scope_desc = [];
        if ( $feature !== '' ) $scope_desc[] = sprintf( "feature '%s'", $feature );
        if ( $post_id > 0 )    $scope_desc[] = sprintf( 'post %d',    $post_id );

        if ( empty( $scope_desc ) ) {
            \WP_CLI::success( sprintf( 'Cleared %d cache entries (whole table).', $count ) );
        } else {
            \WP_CLI::success( sprintf(
                'Cleared %d cache entries scoped to %s.',
                $count,
                implode( ' / ', $scope_desc )
            ) );
        }
    }
}
