<?php
/**
 * StubProvider — a minimal AIProviderInterface implementation for integration tests.
 *
 * Returns a pre-configured response string from chat() without making any
 * network call.  Inject it via the `linguaforge_ai_provider` filter:
 *
 *   add_filter( 'linguaforge_ai_provider', fn() => new StubProvider( $json ), 10, 3 );
 *
 * Call tracking: every chat() call appends the received messages to
 * $calls so tests can assert the payload that was sent to the provider.
 *
 * @package LinguaForge\Tests\Integration\Stubs
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\Stubs;

use LinguaForge\AI\Contracts\AIProviderInterface;

/**
 * Stub AI provider that returns configured responses without network access.
 *
 * Two modes:
 *
 *   Single response (default): every chat() call returns the same string.
 *     new StubProvider('{"title":"T","content":"<p>C</p>"}')
 *
 *   Response queue: chat() returns responses in order; the last entry is
 *   repeated if the queue is exhausted (so a two-call scenario only needs
 *   two entries even if teardown triggers extra calls).
 *     new StubProvider(['{"title":"T",...}', '{"output":"meta desc"}'])
 *
 * Every call is recorded in $calls for assertion.
 */
class StubProvider implements AIProviderInterface {

	/** @var list<array<int,array{role:string,content:string}>> */
	public array $calls = [];

	/** @var list<string|null> */
	private array $queue;

	private int $index = 0;

	/**
	 * @param string|null|list<string|null> $response  Single response or ordered queue.
	 */
	public function __construct( string|null|array $response = null ) {
		$this->queue = is_array( $response ) ? array_values( $response ) : [ $response ];
	}

	/**
	 * Return the next queued response and record the messages for assertion.
	 *
	 * @param array<int,array{role:string,content:string}> $messages
	 */
	public function chat( array $messages ): ?string {
		$this->calls[] = $messages;
		$response      = $this->queue[ $this->index ] ?? end( $this->queue );
		if ( $this->index < count( $this->queue ) - 1 ) {
			$this->index++;
		}
		return $response === false ? null : $response;
	}
}
