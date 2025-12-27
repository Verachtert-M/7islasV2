<?php

namespace App\Parsers;

use App\Contracts\ContentParserInterface;
use App\ValueObjects\ParsedContent;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

/**
 * Markdown parser with YAML front matter support
 */
class MarkdownParser implements ContentParserInterface
{
    private CommonMarkConverter $converter;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allow_unsafe_links' => false,
            'allow_unsafe_images' => false,
            'html_input' => 'strip',
        ], $config);

        $this->converter = new CommonMarkConverter($this->config);
    }

    public function canParse(string $content): bool
    {
        // Check if content has YAML front matter
        return preg_match('/^---\s*\n/', $content) === 1;
    }

    public function parse(string $content): ParsedContent
    {
        if (!$this->canParse($content)) {
            throw new InvalidArgumentException('Content does not contain valid YAML front matter');
        }

        // Extract YAML front matter and body
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            throw new InvalidArgumentException('Invalid YAML front matter format');
        }

        $yamlContent = trim($matches[1]);
        $markdownBody = trim($matches[2]);

        // Parse YAML front matter
        try {
            $frontMatter = Yaml::parse($yamlContent) ?? [];
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Invalid YAML in front matter: ' . $e->getMessage());
        }

        // Convert markdown to HTML
        $htmlBody = $this->converter->convert($markdownBody)->getContent();

        // Add parsing metadata
        $metadata = [
            'parser' => 'markdown',
            'parsed_at' => time(),
            'front_matter_length' => strlen($yamlContent),
            'body_length' => strlen($markdownBody),
            'html_length' => strlen($htmlBody),
        ];

        return new ParsedContent($frontMatter, $markdownBody, $htmlBody, $metadata);
    }

    public function getSupportedExtensions(): array
    {
        return ['md', 'markdown'];
    }

    public function getName(): string
    {
        return 'markdown';
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->converter = new CommonMarkConverter($this->config);
    }

    /**
     * Parse content without front matter (plain markdown)
     */
    public function parseMarkdownOnly(string $content): ParsedContent
    {
        $htmlBody = $this->converter->convert($content)->getContent();

        $metadata = [
            'parser' => 'markdown',
            'parsed_at' => time(),
            'front_matter_length' => 0,
            'body_length' => strlen($content),
            'html_length' => strlen($htmlBody),
        ];

        return new ParsedContent([], $content, $htmlBody, $metadata);
    }

    /**
     * Extract excerpt from content
     */
    public function extractExcerpt(string $content, int $words = 30): string
    {
        // Strip HTML tags and get plain text
        $plainText = strip_tags($content);

        // Split into words
        $wordsArray = preg_split('/\s+/', $plainText, -1, PREG_SPLIT_NO_EMPTY);

        if (count($wordsArray) <= $words) {
            return $plainText;
        }

        // Take first N words and add ellipsis
        $excerpt = implode(' ', array_slice($wordsArray, 0, $words));
        return $excerpt . '...';
    }
}
