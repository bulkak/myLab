<?php

declare(strict_types=1);

namespace App\Service\Contract;

/**
 * Interface for prompt builders that generate optimized prompts
 * for different OCR models.
 *
 * Different models require different prompt formats and instructions.
 * For example:
 * - Qwen2-VL works well with detailed structured prompts
 * - Moondream needs shorter, more direct prompts
 * - LLaVA-Phi3 requires specific JSON formatting instructions
 */
interface PromptBuilderInterface
{
    /**
     * Build a prompt for medical document analysis.
     *
     * @param string|null $modelName Optional specific model name to optimize for
     * @return string The optimized prompt text
     */
    public function buildMedicalAnalysisPrompt(?string $modelName = null): string;

    /**
     * Get the list of supported models for this prompt builder.
     *
     * @return array<string> List of supported model names/patterns
     */
    public function getSupportedModels(): array;

    /**
     * Check if this builder supports the given model.
     *
     * @param string $modelName The model name to check
     * @return bool True if this builder can generate prompts for the model
     */
    public function supportsModel(string $modelName): bool;
}
