<?php
/**
 * Unit tests for ExcerptGenerator::locale_to_lang_code().
 *
 * locale_to_lang_code() is a pure function: it normalises a WordPress locale
 * string to a two-letter lowercase language code.  No WordPress functions are
 * involved.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Features\ExcerptGenerator;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Features/Contracts/FeatureInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/ExcerptGenerator.php';

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Features\ExcerptGenerator::locale_to_lang_code
 */
class ExcerptGeneratorTest extends TestCase {

	public function test_full_locale_en_US_returns_en(): void {

		$this->assertSame( 'en', ExcerptGenerator::locale_to_lang_code( 'en_US' ) );
	}

	public function test_full_locale_it_IT_returns_it(): void {

		$this->assertSame( 'it', ExcerptGenerator::locale_to_lang_code( 'it_IT' ) );
	}

	public function test_short_code_passthrough(): void {

		$this->assertSame( 'de', ExcerptGenerator::locale_to_lang_code( 'de' ) );
	}

	public function test_multi_char_locale_zh_TW_returns_zh(): void {

		$this->assertSame( 'zh', ExcerptGenerator::locale_to_lang_code( 'zh_TW' ) );
	}
}
