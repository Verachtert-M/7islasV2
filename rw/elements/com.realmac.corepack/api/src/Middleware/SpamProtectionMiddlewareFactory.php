<?php

namespace App\Middleware;

use App\Services\SpamProtectionService;
use App\Services\FormProcessor;

/**
 * Factory class for creating spam protection middleware with different configurations
 */
class SpamProtectionMiddlewareFactory
{
    private SpamProtectionService $spamProtectionService;
    private FormProcessor $formProcessor;

    public function __construct(
        SpamProtectionService $spamProtectionService,
        FormProcessor $formProcessor
    ) {
        $this->spamProtectionService = $spamProtectionService;
        $this->formProcessor = $formProcessor;
    }

    /**
     * Create middleware with specific form configuration
     */
    public function create(array $formConfig = []): SpamProtectionMiddleware
    {
        return new SpamProtectionMiddleware(
            $this->spamProtectionService,
            $this->formProcessor,
            $formConfig
        );
    }

    /**
     * Create middleware for email forms
     */
    public function forEmailForms(array $additionalConfig = []): SpamProtectionMiddleware
    {
        $defaultConfig = [
            'spam_protection' => [
                'enabled' => true,
                'provider' => 'recaptcha'
            ]
        ];

        $config = array_merge_recursive($defaultConfig, $additionalConfig);
        return $this->create($config);
    }

    /**
     * Create middleware for webhook forms
     */
    public function forWebhookForms(array $additionalConfig = []): SpamProtectionMiddleware
    {
        $defaultConfig = [
            'spam_protection' => [
                'enabled' => true,
                'provider' => 'recaptcha'
            ]
        ];

        $config = array_merge_recursive($defaultConfig, $additionalConfig);
        return $this->create($config);
    }

    /**
     * Create middleware with specific provider
     */
    public function withProvider(string $provider, array $additionalConfig = []): SpamProtectionMiddleware
    {
        $config = array_merge_recursive([
            'spam_protection' => [
                'enabled' => true,
                'provider' => $provider
            ]
        ], $additionalConfig);

        return $this->create($config);
    }
}
