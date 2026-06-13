<?php

namespace LinguaForge\AI\Contracts;

defined('ABSPATH') || exit;

interface AIProviderInterface {

    public function chat(array $messages): ?string;

    /**
     * Return the human-readable failure reason from the last chat() call.
     *
     * Returns an empty string when the last call succeeded.  All concrete
     * providers must populate this at every early-return point inside chat()
     * so callers can surface a specific reason rather than a generic fallback.
     */
    public function get_last_error(): string;
}
