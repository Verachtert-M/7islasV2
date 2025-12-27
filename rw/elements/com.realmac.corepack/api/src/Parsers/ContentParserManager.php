<?php

namespace App\Parsers;

use App\Contracts\ContentParserInterface;
use App\ValueObjects\ParsedContent;
use InvalidArgumentException;

/**
 * Content parser manager that handles multiple parsers
 */
class ContentParserManager
{
    private array $parsers = [];
    private array $parsersByExtension = [];
    private array $parsersByName = [];

    /**
     * Add a parser to the manager
     */
    public function addParser(ContentParserInterface $parser): void
    {
        $this->parsers[] = $parser;
        $this->parsersByName[$parser->getName()] = $parser;

        // Map extensions to parser
        foreach ($parser->getSupportedExtensions() as $extension) {
            $this->parsersByExtension[$extension] = $parser;
        }
    }

    /**
     * Parse content using the appropriate parser
     */
    public function parse(string $content): ParsedContent
    {
        $parser = $this->findParser($content);

        if (!$parser) {
            throw new InvalidArgumentException('No suitable parser found for content');
        }

        return $parser->parse($content);
    }

    /**
     * Parse content using a specific parser by name
     */
    public function parseWith(string $parserName, string $content): ParsedContent
    {
        if (!isset($this->parsersByName[$parserName])) {
            throw new InvalidArgumentException("Parser '{$parserName}' not found");
        }

        return $this->parsersByName[$parserName]->parse($content);
    }

    /**
     * Parse content based on file extension
     */
    public function parseByExtension(string $extension, string $content): ParsedContent
    {
        $extension = strtolower(ltrim($extension, '.'));

        if (!isset($this->parsersByExtension[$extension])) {
            throw new InvalidArgumentException("No parser found for extension '{$extension}'");
        }

        return $this->parsersByExtension[$extension]->parse($content);
    }

    /**
     * Find the appropriate parser for content
     */
    public function findParser(string $content): ?ContentParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->canParse($content)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Get all registered parsers
     */
    public function getParsers(): array
    {
        return $this->parsers;
    }

    /**
     * Get supported extensions
     */
    public function getSupportedExtensions(): array
    {
        return array_keys($this->parsersByExtension);
    }

    /**
     * Check if content can be parsed
     */
    public function canParse(string $content): bool
    {
        return $this->findParser($content) !== null;
    }

    /**
     * Check if extension is supported
     */
    public function supportsExtension(string $extension): bool
    {
        $extension = strtolower(ltrim($extension, '.'));
        return isset($this->parsersByExtension[$extension]);
    }
}
