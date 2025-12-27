<?php

namespace App\Services;

use App\Contracts\SpamProtectionProviderInterface;
use App\Services\SpamProtection\RecaptchaProvider;
use App\Services\SpamProtection\HcaptchaProvider;
use App\Services\SpamProtection\TurnstileProvider;
use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Main spam protection service that orchestrates different providers
 */
class SpamProtectionService
{
    private array $providers = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->registerDefaultProviders();
    }

    /**
     * Verify spam protection for a request with form-specific configuration
     */
    public function verify(Request $request, array $formConfig = []): SpamProtectionResult
    {
        try {
            // Get spam protection config for this form
            $spamConfig = $this->getSpamProtectionConfig($formConfig);

            // Check if spam protection is enabled for this form
            if (!$this->isSpamProtectionEnabled($spamConfig)) {
                return SpamProtectionResult::success(['skipped' => true, 'reason' => 'disabled']);
            }

            // Get the provider to use
            $providerName = $spamConfig['provider'] ?? $this->getDefaultProvider();
            $provider = $this->getProvider($providerName);

            if (!$provider) {
                return SpamProtectionResult::failure(
                    "Spam protection provider '{$providerName}' not found",
                    'provider_not_found'
                );
            }

            // Get provider-specific configuration
            $providerConfig = $this->getProviderConfig($providerName, $spamConfig);

            // Check if provider is properly configured
            if (!$provider->isConfigured($providerConfig)) {
                return SpamProtectionResult::failure(
                    "Spam protection provider '{$providerName}' is not properly configured",
                    'provider_not_configured'
                );
            }

            // Verify with the provider
            return $provider->verify($request, $providerConfig);
        } catch (\Exception $e) {
            return SpamProtectionResult::failure(
                'Spam protection verification failed: ' . $e->getMessage(),
                'verification_error'
            );
        }
    }

    /**
     * Check if spam protection is enabled for a form
     */
    public function isSpamProtectionEnabled(array $spamConfig): bool
    {
        return ($spamConfig['enabled'] ?? false) === true;
    }

    /**
     * Get available providers
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get provider status information
     */
    public function getProviderStatus(string $providerName, array $formConfig = []): array
    {
        $provider = $this->getProvider($providerName);

        if (!$provider) {
            return [
                'exists' => false,
                'configured' => false,
                'name' => $providerName
            ];
        }

        $spamConfig = $this->getSpamProtectionConfig($formConfig);
        $providerConfig = $this->getProviderConfig($providerName, $spamConfig);

        return [
            'exists' => true,
            'configured' => $provider->isConfigured($providerConfig),
            'name' => $provider->getName(),
            'token_fields' => $provider->getTokenFields()
        ];
    }

    /**
     * Register a spam protection provider
     */
    public function registerProvider(string $name, SpamProtectionProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * Get spam protection configuration for a form
     */
    private function getSpamProtectionConfig(array $formConfig): array
    {
        // Form-specific spam protection config takes precedence
        $formSpamConfig = $formConfig['spam_protection'] ?? [];

        // Merge with global defaults
        $globalSpamConfig = $this->config['spam_protection'] ?? [];

        return array_merge($globalSpamConfig, $formSpamConfig);
    }

    /**
     * Get provider-specific configuration
     */
    private function getProviderConfig(string $providerName, array $spamConfig): array
    {
        // Check for provider-specific config in form config
        $providerConfig = $spamConfig['providers'][$providerName] ?? [];

        // Merge with global provider config
        $globalProviderConfig = $this->config['spam_protection']['providers'][$providerName] ?? [];

        return array_merge($globalProviderConfig, $providerConfig);
    }

    /**
     * Get the default provider name
     */
    private function getDefaultProvider(): string
    {
        return $this->config['spam_protection']['default_provider'] ?? 'recaptcha';
    }

    /**
     * Get a provider by name
     */
    private function getProvider(string $name): ?SpamProtectionProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Register default providers
     */
    private function registerDefaultProviders(): void
    {
        $this->registerProvider('recaptcha', new RecaptchaProvider());
        $this->registerProvider('hcaptcha', new HcaptchaProvider());
        $this->registerProvider('turnstile', new TurnstileProvider());
    }
}
