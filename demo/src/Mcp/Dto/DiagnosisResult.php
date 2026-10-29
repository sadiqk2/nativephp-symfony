<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * Strongly-typed DTO for AI analysis results (inspired by Prism PHP / Instructor-PHP).
 */
final class DiagnosisResult
{
    /**
     * @param string[] $suggestedCommands
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $summary,
        public readonly string $rootCause,
        public readonly string $proposedFix,
        public readonly array $suggestedCommands = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'summary' => $this->summary,
            'root_cause' => $this->rootCause,
            'proposed_fix' => $this->proposedFix,
            'suggested_commands' => $this->suggestedCommands,
        ];
    }
}
