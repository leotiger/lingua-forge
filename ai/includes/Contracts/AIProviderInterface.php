<?php

namespace LinguaForge\AI\Contracts;

defined('ABSPATH') || exit;

interface AIProviderInterface {

    public function chat(array $messages): ?string;
}
