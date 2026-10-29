<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use Twig\Environment;

/**
 * Inspired by Prism PHP's Blade prompt templates:
 * Allows writing reusable, expressive AI system prompts directly in Twig.
 */
final class TwigPromptRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $templateName, array $context = []): string
    {
        return \trim($this->twig->render(\sprintf('prompts/%s.twig', $templateName), $context));
    }
}
