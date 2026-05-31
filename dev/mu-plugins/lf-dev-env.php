<?php
/**
 * Dev-only mu-plugin — forces DE and CA into the router language list.
 * Written by seed-dev-env.sh; never ships to production.
 */
add_filter( "lf_languages_list", function ( array $langs ): array {
    return array_values( array_unique( array_merge( $langs, [ "de", "ca" ] ) ) );
} );
