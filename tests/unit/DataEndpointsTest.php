<?php
/**
 * Unit tests for LinguaForge\Router\REST\DataEndpoints handlers.
 *
 * Verifies response shape for the /languages and /post/{id}/translations
 * routes, and tests the permission callback's visibility gating logic.
 *
 * Does NOT boot WordPress or register actual REST routes. Tests call the
 * static handler methods directly with stubbed WP_REST_Request objects.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\REST\DataEndpoints;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';
require_once __DIR__ . '/WooCommerce/WcPolyfills.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/rest/class-data-endpoints.php';

class DataEndpointsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\LfWcMocks::reset();
		$GLOBALS['lf_api_languages']        = [];
		$GLOBALS['lf_api_language_labels']  = [];
		$GLOBALS['lf_api_permalinks']       = [];
		$GLOBALS['lf_api_current_user_can'] = true;
	}

	// =========================================================================
	// /languages
	// =========================================================================

	public function test_handle_languages_returns_empty_array_when_no_languages(): void {
		$data = DataEndpoints::handle_languages()->get_data();

		$this->assertIsArray( $data );
		$this->assertEmpty( $data );
	}

	public function test_handle_languages_returns_code_and_label_for_each_language(): void {
		$GLOBALS['lf_api_languages']       = [ 'ca', 'es', 'en' ];
		$GLOBALS['lf_api_language_labels'] = [
			'ca' => 'Català',
			'es' => 'Español',
			'en' => 'English',
		];

		$data = DataEndpoints::handle_languages()->get_data();

		$this->assertCount( 3, $data );
		$this->assertSame( [ 'code' => 'ca', 'label' => 'Català' ],  $data[0] );
		$this->assertSame( [ 'code' => 'es', 'label' => 'Español' ], $data[1] );
		$this->assertSame( [ 'code' => 'en', 'label' => 'English' ], $data[2] );
	}

	public function test_handle_languages_falls_back_to_code_when_label_missing(): void {
		$GLOBALS['lf_api_languages']       = [ 'de' ];
		$GLOBALS['lf_api_language_labels'] = [];

		$data = DataEndpoints::handle_languages()->get_data();

		$this->assertSame( 'de', $data[0]['label'], 'Should fall back to the language code.' );
	}

	// =========================================================================
	// /post/{id}/translations
	// =========================================================================

	public function test_handle_translations_returns_404_for_missing_post(): void {
		$request = new \WP_REST_Request( [ 'id' => 9999 ] );

		$result = DataEndpoints::handle_post_translations( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_handle_translations_returns_empty_object_when_no_translation_map(): void {
		$this->make_post( 100, 'publish' );
		$request = new \WP_REST_Request( [ 'id' => 100 ] );

		$map = (array) DataEndpoints::handle_post_translations( $request )->get_data();

		$this->assertEmpty( $map );
	}

	public function test_handle_translations_returns_map_of_lang_to_permalink(): void {
		$this->make_post( 200, 'publish' );
		$this->make_post( 201, 'publish' );
		$this->make_post( 202, 'publish' );

		\LfWcMocks::$translations[200] = [ 'ca' => 200, 'es' => 201, 'en' => 202 ];
		$GLOBALS['lf_api_permalinks']  = [
			200 => 'https://example.com/post/',
			201 => 'https://example.com/es/post/',
			202 => 'https://example.com/en/post/',
		];

		$map = (array) DataEndpoints::handle_post_translations( new \WP_REST_Request( [ 'id' => 200 ] ) )->get_data();

		$this->assertSame( 'https://example.com/post/',    $map['ca'] );
		$this->assertSame( 'https://example.com/es/post/', $map['es'] );
		$this->assertSame( 'https://example.com/en/post/', $map['en'] );
	}

	public function test_handle_translations_excludes_non_published_translations(): void {
		$this->make_post( 300, 'publish' );
		$this->make_post( 301, 'draft' );   // not published
		$this->make_post( 302, 'publish' );

		\LfWcMocks::$translations[300] = [ 'ca' => 300, 'es' => 301, 'en' => 302 ];
		$GLOBALS['lf_api_permalinks']  = [
			300 => 'https://example.com/post/',
			301 => 'https://example.com/es/post/',
			302 => 'https://example.com/en/post/',
		];
		$GLOBALS['lf_api_current_user_can'] = false;

		$map = (array) DataEndpoints::handle_post_translations( new \WP_REST_Request( [ 'id' => 300 ] ) )->get_data();

		$this->assertArrayHasKey( 'ca', $map );
		$this->assertArrayNotHasKey( 'es', $map, 'Draft translation must be excluded.' );
		$this->assertArrayHasKey( 'en', $map );
	}

	public function test_handle_translations_includes_private_posts_for_capable_user(): void {
		$this->make_post( 400, 'publish' );
		$this->make_post( 401, 'private' );

		\LfWcMocks::$translations[400] = [ 'ca' => 400, 'es' => 401 ];
		$GLOBALS['lf_api_permalinks']  = [
			400 => 'https://example.com/post/',
			401 => 'https://example.com/es/post/',
		];
		$GLOBALS['lf_api_current_user_can'] = true;

		$map = (array) DataEndpoints::handle_post_translations( new \WP_REST_Request( [ 'id' => 400 ] ) )->get_data();

		$this->assertArrayHasKey( 'es', $map, 'Private translation should be included for capable user.' );
	}

	// =========================================================================
	// check_post_read_permission
	// =========================================================================

	public function test_permission_allows_published_post_without_auth(): void {
		$this->make_post( 500, 'publish' );
		$GLOBALS['lf_api_current_user_can'] = false; // no elevated privileges

		$request = new \WP_REST_Request( [ 'id' => 500 ] );
		$result  = DataEndpoints::check_post_read_permission( $request );

		$this->assertTrue( $result );
	}

	public function test_permission_allows_missing_post_to_pass_through_to_handler(): void {
		// Missing post → permission returns true so the handler can return 404.
		$request = new \WP_REST_Request( [ 'id' => 9999 ] );
		$result  = DataEndpoints::check_post_read_permission( $request );

		$this->assertTrue( $result );
	}

	public function test_permission_returns_wp_error_for_private_post_without_capability(): void {
		$this->make_post( 600, 'draft' );
		$GLOBALS['lf_api_current_user_can'] = false;

		$request = new \WP_REST_Request( [ 'id' => 600 ] );
		$result  = DataEndpoints::check_post_read_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_permission_allows_private_post_when_user_has_capability(): void {
		$this->make_post( 700, 'private' );
		$GLOBALS['lf_api_current_user_can'] = true;

		$request = new \WP_REST_Request( [ 'id' => 700 ] );
		$result  = DataEndpoints::check_post_read_permission( $request );

		$this->assertTrue( $result );
	}

	// =========================================================================

	private function make_post( int $id, string $status ): \WP_Post {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_type   = 'post';
		\LfWcMocks::$posts[ $id ] = $post;
		return $post;
	}
}
