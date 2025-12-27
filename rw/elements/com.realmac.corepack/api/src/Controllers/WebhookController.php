<?php

namespace App\Controllers;

use App\Services\WebhookService;
use App\Services\FormProcessor;
use App\Services\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Webhook Controller
 * Handles webhook submissions for forms with clean, maintainable architecture
 */
class WebhookController extends BaseController
{
    public function __construct(
        private WebhookService $webhookService,
        private FormProcessor $formProcessor,
        private ?\App\Services\SpamProtectionService $spamProtectionService = null,
        array $config = []
    ) {
        parent::__construct($config);
    }

    /**
     * Submit form via webhook
     */
    public function submit(Request $request, Response $response): Response
    {
        try {
            $configPath = $this->getConfigPath($request);
            $formConfig = $this->formProcessor->getFormConfig($request);
            $resolvedConfigPath = $this->formProcessor->resolveConfigPathFromReferer($request);
            $logger = $this->createLogger($resolvedConfigPath, $formConfig);

            $this->validateWebhookEnabled($formConfig, $logger, $configPath);

            // Verify spam protection if service is available and not already verified by middleware
            if ($this->spamProtectionService) {
                $spamResult = $this->verifySpamProtection($request, $formConfig);
                if (!$spamResult) {
                    // No middleware verification, do manual verification
                    $spamResult = $this->spamProtectionService->verify($request, $formConfig);
                }

                if ($spamResult->isFailure()) {
                    $logger->warning('Spam protection verification failed', [
                        'config_path' => $configPath,
                        'error' => $spamResult->getError(),
                        'error_code' => $spamResult->getErrorCode()
                    ]);
                    throw new \InvalidArgumentException($spamResult->getError());
                }

                if ($spamResult->isSuccess() && !$spamResult->getMetadataValue('skipped')) {
                    $logger->info('Spam protection verification passed', [
                        'config_path' => $configPath,
                        'provider' => $spamResult->getMetadataValue('provider')
                    ]);
                }
            }

            $formData = $this->prepareFormData($request, $formConfig, $logger, $configPath);
            $result = $this->processWebhook($formData, $formConfig, $configPath, $logger);

            return $this->handleWebhookResult($result, $response, $logger, $configPath);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, $response, $logger ?? null);
        }
    }

    /**
     * Get form status
     */
    public function status(Request $request, Response $response): Response
    {
        try {
            $configPath = $this->getConfigPath($request, 'query');
            $formConfig = $this->formProcessor->getFormConfig($request);
            $resolvedConfigPath = $this->formProcessor->resolveConfigPathFromReferer($request);
            $logger = $this->createLogger($resolvedConfigPath, $formConfig);

            $status = $this->buildStatusResponse($formConfig, $configPath, $logger);
            return $this->successResponse($response, $status);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, $response, $logger ?? null);
        }
    }

    /**
     * Test webhook connection
     */
    public function test(Request $request, Response $response): Response
    {
        try {
            $webhookUrl = $this->getWebhookUrl($request);
            $testData = $this->buildTestData();

            $webhookResponse = $this->webhookService->send($webhookUrl, $testData);
            $success = $webhookResponse->isSuccess();

            return $this->jsonResponse($response, [
                'success' => $success,
                'message' => $success ? 'Webhook test successful' : 'Webhook test failed',
                'url' => $webhookUrl,
                'timestamp' => date('c')
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get API information
     */
    public function info(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'success' => true,
            'name' => 'Webhook Form API',
            'version' => '1.0',
            'description' => 'Handles webhook form submissions with file attachment support',
            'endpoints' => [
                'POST /webhook' => 'Submit form via webhook',
                'GET /webhook/status' => 'Get webhook configuration status',
                'POST /webhook/test' => 'Test webhook connection',
                'GET /webhook' => 'Get API information'
            ],
            'timestamp' => date('c')
        ]);
    }

    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================

    /**
     * Extract config path from request
     */
    private function getConfigPath(Request $request, string $source = 'body'): string
    {
        $data = $source === 'query' ? $request->getQueryParams() : $request->getParsedBody();
        $configPath = $data['config_path'] ?? null;

        if (!$configPath) {
            throw new \InvalidArgumentException('Missing config_path parameter');
        }

        return $configPath;
    }

    /**
     * Create form-specific logger
     */
    private function createLogger(string $configPath, array $formConfig): \Psr\Log\LoggerInterface
    {
        $formIdentifier = 'webhook_' . basename($configPath, '.php');
        return Logger::getLogger($formIdentifier, $formConfig);
    }

    /**
     * Validate webhook is enabled
     */
    private function validateWebhookEnabled(array $formConfig, \Psr\Log\LoggerInterface $logger, string $configPath): void
    {
        if (!$this->isWebhookEnabled($formConfig)) {
            $logger->warning('Webhook submission attempted but webhook is not enabled', [
                'config_path' => $configPath
            ]);
            throw new \InvalidArgumentException('Webhook is not enabled for this form');
        }
    }

    /**
     * Prepare form data with file attachments and metadata
     */
    private function prepareFormData(Request $request, array $formConfig, \Psr\Log\LoggerInterface $logger, string $configPath): array
    {
        $formData = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        // Add first uploaded file if present (preserving your robust logic)
        $this->attachFirstUploadedFile($formData, $files);

        // Add metadata
        $metadata = $this->formProcessor->extractMetadata($request);
        $cleanFormData = $this->formProcessor->prepareFormData($formData, $metadata, $formConfig);

        $logger->info('Processing webhook form submission', [
            'config_path' => $configPath,
            'fields' => array_keys($cleanFormData),
            'uploaded_files_count' => count($files),
            'metadata' => $metadata,
            'webhook_url' => $formConfig['webhook']['url']
        ]);

        return $cleanFormData;
    }

    /**
     * Attach first uploaded file to form data (your robust implementation)
     */
    private function attachFirstUploadedFile(array &$formData, array $files): void
    {
        if (!empty($files) && is_array($files)) {
            $firstFileKey = array_key_first($files);
            $firstFile = is_array($files[$firstFileKey]) ? $files[$firstFileKey][0] : $files[$firstFileKey];

            if ($firstFile instanceof \Psr\Http\Message\UploadedFileInterface) {
                $formData[$firstFileKey] = $firstFile;
            }
        }
    }

    /**
     * Process webhook submission
     */
    private function processWebhook(array $formData, array $formConfig, string $configPath, \Psr\Log\LoggerInterface $logger): array
    {
        try {
            $webhookData = $this->prepareWebhookData($formData, $formConfig, $configPath);

            $logger->debug('Preparing to send webhook', [
                'config_path' => $configPath,
                'url' => $formConfig['webhook']['url'],
                'data_fields' => array_keys($webhookData)
            ]);

            $webhookResponse = $this->webhookService->send($formConfig['webhook']['url'], $webhookData);

            if ($webhookResponse->isSuccess()) {
                $logger->info('Webhook sent successfully', [
                    'config_path' => $configPath,
                    'url' => $formConfig['webhook']['url'],
                    'status_code' => $webhookResponse->getStatusCode()
                ]);

                return ['success' => true, 'message' => 'Form submitted successfully!'];
            } else {
                $logger->error('Webhook sending failed', [
                    'config_path' => $configPath,
                    'url' => $formConfig['webhook']['url'],
                    'status_code' => $webhookResponse->getStatusCode(),
                    'response_body' => substr($webhookResponse->getBody(), 0, 200)
                ]);

                return ['success' => false, 'message' => 'Failed to send webhook'];
            }
        } catch (\Exception $e) {
            $logger->error('Webhook processing exception', [
                'config_path' => $configPath,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => 'Webhook processing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Handle webhook processing result
     */
    private function handleWebhookResult(array $result, Response $response, \Psr\Log\LoggerInterface $logger, string $configPath): Response
    {
        if ($result['success']) {
            $logger->info('Webhook form submission completed successfully', [
                'config_path' => $configPath,
                'webhook_sent' => true
            ]);

            return $this->successResponse($response, [
                'message' => $result['message'],
                'webhook_sent' => true,
                'timestamp' => date('c')
            ]);
        } else {
            $logger->error('Webhook form submission failed', [
                'config_path' => $configPath,
                'error' => $result['message']
            ]);

            return $this->errorResponse($response, $result['message'], 400);
        }
    }

    /**
     * Build status response data
     */
    private function buildStatusResponse(array $formConfig, string $configPath, \Psr\Log\LoggerInterface $logger): array
    {
        $webhookEnabled = $this->isWebhookEnabled($formConfig);
        $webhookConfigured = !empty($formConfig['webhook']['url'] ?? '');
        $hasValidation = !empty($formConfig['validation']['rules']);

        $logger->debug('Webhook status requested', [
            'config_path' => $configPath,
            'webhook_enabled' => $webhookEnabled,
            'webhook_configured' => $webhookConfigured,
            'has_validation' => $hasValidation
        ]);

        return [
            'message' => 'Webhook status retrieved',
            'webhook_enabled' => $webhookEnabled,
            'webhook_configured' => $webhookConfigured,
            'has_validation' => $hasValidation,
            'timestamp' => date('c')
        ];
    }

    /**
     * Extract webhook URL from request
     */
    private function getWebhookUrl(Request $request): string
    {
        $body = $request->getParsedBody();
        $webhookUrl = $body['webhook_url'] ?? '';

        if (empty($webhookUrl)) {
            throw new \InvalidArgumentException('webhook_url parameter is required');
        }

        return $webhookUrl;
    }

    /**
     * Build test data for webhook testing
     */
    private function buildTestData(): array
    {
        return [
            'test' => true,
            'message' => 'Test webhook from RWElementPacks API',
            'timestamp' => date('c'),
            'source' => 'WebhookController'
        ];
    }

    /**
     * Handle exceptions consistently
     */
    private function handleException(\Exception $e, Response $response, ?\Psr\Log\LoggerInterface $logger): Response
    {
        $logger = $logger ?? Logger::getInstance();
        $logger->error('Webhook controller exception', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return $this->errorResponse($response, 'An error occurred while processing your request', 500);
    }

    /**
     * Check if webhook is properly enabled for this form
     */
    private function isWebhookEnabled(array $formConfig): bool
    {
        return isset($formConfig['webhook']) &&
            is_array($formConfig['webhook']) &&
            ($formConfig['webhook']['enabled'] ?? false) === true &&
            !empty($formConfig['webhook']['url']);
    }

    /**
     * Prepare webhook data from form submission
     */
    private function prepareWebhookData(array $formData, array $formConfig, string $configPath): array
    {
        // Use FormProcessor to filter out metadata fields and prepare clean webhook data
        $webhookData = $this->formProcessor->prepareWebhookData($formData, $formConfig);

        // Add additional metadata
        $webhookData['source'] = 'RWElementPacks Form API';
        $webhookData['config_path'] = $configPath;

        // Add any additional webhook-specific data from config
        if (!empty($formConfig['webhook']['additional_data'])) {
            $webhookData = array_merge($webhookData, $formConfig['webhook']['additional_data']);
        }

        return $webhookData;
    }
}
