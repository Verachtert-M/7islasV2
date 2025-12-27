<?php

namespace App\Contracts;

use App\ValueObjects\ParsedContent;

interface ContentParserInterface
{
    /**
     * Check if this parser can handle the given content
     */
    public function canParse(string $content): bool;

    /**
     * Parse the content and return structured data
     */
    public function parse(string $content): ParsedContent;

    /**
     * Get the supported file extensions
     */
    public function getSupportedExtensions(): array;

    /**
     * Get the parser name/identifier
     */
    public function getName(): string;

    /**
     * Get parser configuration
     */
    public function getConfig(): array;

    /**
     * Set parser configuration
     */
    public function setConfig(array $config): void;
}
