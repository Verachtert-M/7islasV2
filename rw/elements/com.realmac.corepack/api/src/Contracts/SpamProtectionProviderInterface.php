<?php

namespace App\Contracts;

use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Interface for spam protection providers (reCAPTCHA, hCAPTCHA, Turnstile, etc.)
 */
interface SpamProtectionProviderInterface
{
    /**
     * Verify the captcha token from the request
     *
     * @param Request $request The HTTP request containing the token
     * @param array $config Provider-specific configuration
     * @return SpamProtectionResult The verification result
     */
    public function verify(Request $request, array $config): SpamProtectionResult;

    /**
     * Get the name of this provider
     *
     * @return string Provider name (e.g., 'recaptcha', 'hcaptcha', 'turnstile')
     */
    public function getName(): string;

    /**
     * Get the expected token field names for this provider
     *
     * @return array Array of field names that might contain the token
     */
    public function getTokenFields(): array;

    /**
     * Check if the provider is properly configured
     *
     * @param array $config Provider configuration
     * @return bool True if properly configured
     */
    public function isConfigured(array $config): bool;
}
