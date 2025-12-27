<?php

namespace App\Controllers;

use App\Services\EmailService;
use App\Services\FormProcessor;
use App\Services\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Email Form Controller
 * Handles email-only form submissions
 * Simple, clean, focused on email functionality only
 */
class EmailFormController extends BaseController
{
    public function __construct(
        private EmailService $emailService,
        private FormProcessor $formProcessor,
        private ?\App\Services\SpamProtectionService $spamProtectionService = null,
        array $config = []
    ) {
        parent::__construct($config);
    }

    /**
     * Submit form via email
     */
    public function submit(Request $request, Response $response): Response
    {
        try {
            $formData = $request->getParsedBody();
            $uploadedFiles = $request->getUploadedFiles();
            $configPath = $formData['config_path'] ?? null;

            if (!$configPath) {
                return $this->errorResponse($response, 'Missing config_path parameter');
            }

            // Get form configuration and create form logger
            $formConfig = $this->formProcessor->getFormConfig($request);
            $resolvedConfigPath = $this->formProcessor->resolveConfigPathFromReferer($request);
            $formIdentifier = 'form_' . basename($resolvedConfigPath, '.php');
            $logger = Logger::getLogger($formIdentifier, $formConfig);

            if (!$this->isEmailEnabled($formConfig)) {
                $logger->warning('Email submission attempted but email is not enabled', [
                    'config_path' => $resolvedConfigPath
                ]);
                return $this->errorResponse($response, 'Email is not enabled for this form');
            }

            // Verify spam protection if service is available and not already verified by middleware
            if ($this->spamProtectionService) {
                $spamResult = $this->verifySpamProtection($request, $formConfig);
                if (!$spamResult) {
                    // No middleware verification, do manual verification
                    $spamResult = $this->spamProtectionService->verify($request, $formConfig);
                }

                if ($spamResult->isFailure()) {
                    $logger->warning('Spam protection verification failed', [
                        'config_path' => $resolvedConfigPath,
                        'error' => $spamResult->getError(),
                        'error_code' => $spamResult->getErrorCode()
                    ]);
                    return $this->handleSpamProtectionFailure($response, $spamResult);
                }

                if ($spamResult->isSuccess() && !$spamResult->getMetadataValue('skipped')) {
                    $logger->info('Spam protection verification passed', [
                        'config_path' => $resolvedConfigPath,
                        'provider' => $spamResult->getMetadataValue('provider')
                    ]);
                }
            }

            // Process and validate form data
            $metadata = $this->formProcessor->extractMetadata($request);
            $cleanFormData = $this->formProcessor->prepareFormData($formData, $metadata, $formConfig);

            $logger->info('Processing email form submission', [
                'config_path' => $resolvedConfigPath,
                'fields' => array_keys($cleanFormData),
                'attachments_count' => count($uploadedFiles),
                'metadata' => $metadata
            ]);

            // Send email
            $emailResult = $this->sendEmail($cleanFormData, $formConfig, $resolvedConfigPath, $uploadedFiles, $logger);

            if ($emailResult['success']) {
                $logger->info('Email form submission completed successfully', [
                    'config_path' => $resolvedConfigPath,
                    'email_sent' => true
                ]);

                return $this->successResponse($response, [
                    'message' => 'Form submitted successfully!',
                    'email_sent' => true,
                    'timestamp' => date('c')
                ]);
            } else {
                $logger->error('Email form submission failed', [
                    'config_path' => $resolvedConfigPath,
                    'error' => $emailResult['message']
                ]);

                return $this->errorResponse($response, $emailResult['message']);
            }
        } catch (\Exception $e) {
            // Use form logger if we have it, otherwise fallback to global
            $logger = isset($logger) ? $logger : Logger::getInstance();
            $logger->error('Email form submission exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse($response, 'An error occurred while processing your request');
        }
    }

    /**
     * Get form status
     */
    public function status(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $configPath = $queryParams['config_path'] ?? null;

            if (!$configPath) {
                return $this->errorResponse($response, 'Missing config_path parameter');
            }

            $formConfig = $this->formProcessor->getFormConfig($request);
            $resolvedConfigPath = $this->formProcessor->resolveConfigPathFromReferer($request);
            $formIdentifier = 'form_' . basename($resolvedConfigPath, '.php');
            $logger = Logger::getLogger($formIdentifier, $formConfig);

            $emailEnabled = $this->isEmailEnabled($formConfig);
            $emailConfigured = !empty($formConfig['email']['smtp_host'] ?? $formConfig['email']['from_email']);
            $hasValidation = !empty($formConfig['validation']['rules']);

            $logger->debug('Form status requested', [
                'config_path' => $resolvedConfigPath,
                'email_enabled' => $emailEnabled,
                'email_configured' => $emailConfigured,
                'has_validation' => $hasValidation
            ]);

            return $this->successResponse($response, [
                'message' => 'Form status retrieved',
                'email_enabled' => $emailEnabled,
                'email_configured' => $emailConfigured,
                'has_validation' => $hasValidation,
                'timestamp' => date('c')
            ]);
        } catch (\Exception $e) {
            $logger = isset($logger) ? $logger : Logger::getInstance();
            $logger->error('Form status request failed', [
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($response, 'Could not retrieve form status: ' . $e->getMessage());
        }
    }

    /**
     * Get API information
     */
    public function info(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'success' => true,
            'name' => 'Email Form API',
            'version' => '1.0',
            'description' => 'Handles email-only form submissions',
            'endpoints' => [
                'POST /email' => 'Submit form via email',
                'GET /email/status' => 'Get email configuration status',
                'GET /email' => 'Get API information'
            ],
            'timestamp' => date('c')
        ]);
    }

    // ========================================
    // PRIVATE METHODS
    // ========================================

    /**
     * Check if email is properly enabled for this form
     */
    private function isEmailEnabled(array $formConfig): bool
    {
        return isset($formConfig['email']) &&
            is_array($formConfig['email']) &&
            ($formConfig['email']['enabled'] ?? false) === true &&
            !empty($formConfig['email']['to']);
    }

    /**
     * Send form data via email
     */
    private function sendEmail(array $formData, array $formConfig, string $configPath, array $uploadedFiles = [], $logger = null): array
    {
        try {
            $emailConfig = $formConfig['email'];
            $emailData = $this->prepareEmailData($formData, $emailConfig, $configPath, $uploadedFiles);

            $logger->debug('Preparing to send email', [
                'config_path' => $configPath,
                'to' => $emailData['to'],
                'subject' => $emailData['subject'],
                'attachments_count' => count($emailData['attachments'] ?? [])
            ]);

            // Filter form data to remove metadata fields before sending email
            $cleanFormDataForEmail = $this->formProcessor->prepareSubmissionData($formData);

            // Send via EmailService
            $success = $this->emailService->sendFormSubmission($cleanFormDataForEmail, $emailData, $emailConfig, $logger);

            if ($success) {
                $logger->info('Email sent successfully', [
                    'config_path' => $configPath,
                    'to' => $emailData['to'],
                    'subject' => $emailData['subject'],
                    'attachments_count' => count($emailData['attachments'] ?? [])
                ]);
            } else {
                $logger->error('EmailService returned false', [
                    'config_path' => $configPath,
                    'to' => $emailData['to']
                ]);
            }

            // Log result
            $this->logEmailResult($configPath, $emailConfig, $success, $formData);

            return [
                'success' => $success,
                'message' => $success ? 'Email sent successfully' : 'Failed to send email'
            ];
        } catch (\Exception $e) {
            $logger->error('Email sending failed', [
                'config_path' => $configPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Email sending failed: ' . $e->getMessage(),
                'errors' => ['email' => $e->getMessage()]
            ];
        }
    }

    /**
     * Prepare email data from form data and config
     */
    private function prepareEmailData(array $formData, array $emailConfig, string $configPath, array $uploadedFiles = []): array
    {
        $emailData = [
            'to' => $emailConfig['to'],
            'subject' => $this->buildSubject($emailConfig, $configPath),
            'template' => $emailConfig['template'] ?? 'default',
            'from_email' => $emailConfig['from_email'] ?? null,
            'from_name' => $emailConfig['from_name'] ?? null,
            'email_title' => $emailConfig['email_title'] ?? 'New Form Submission',
            'email_footer' => $emailConfig['email_footer'] ?? ''
        ];

        // Process uploaded files for email attachments
        if (!empty($uploadedFiles)) {
            $emailData['attachments'] = $this->processUploadedFiles($uploadedFiles);
        }

        return $emailData;
    }

    /**
     * Process uploaded files for email attachments
     */
    private function processUploadedFiles(array $uploadedFiles): array
    {
        $attachments = [];

        foreach ($uploadedFiles as $fieldName => $file) {
            if (is_array($file)) {
                // Handle multiple files
                foreach ($file as $multiFile) {
                    if ($multiFile->getError() === UPLOAD_ERR_OK && $multiFile->getSize() > 0) {
                        $attachments[] = $multiFile;
                    }
                }
            } else {
                // Handle single file
                if ($file->getError() === UPLOAD_ERR_OK && $file->getSize() > 0) {
                    $attachments[] = $file;
                }
            }
        }

        return $attachments;
    }

    /**
     * Build email subject with placeholder replacement
     */
    private function buildSubject(array $emailConfig, string $configPath): string
    {
        $formName = basename($configPath, '.php');
        $subject = $emailConfig['subject'] ?? "Form Submission: {$formName}";

        // Replace placeholders
        $placeholders = [
            '{form_name}' => $formName,
            '{date}' => date('Y-m-d'),
            '{time}' => date('H:i:s'),
            '{datetime}' => date('Y-m-d H:i:s')
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $subject);
    }

    /**
     * Log email sending result
     */
    private function logEmailResult(string $configPath, array $emailConfig, bool $success, array $formData): void
    {
        $logger = Logger::getLogger('form_' . basename($configPath, '.php'), ['email' => $emailConfig]);
        $logger->info('Email sending attempt', [
            'config_path' => $configPath,
            'success' => $success,
            'to' => $emailConfig['to'] ?? 'unknown',
            'subject' => $emailConfig['subject'] ?? 'unknown',
            'form_data_fields' => array_keys($formData)
        ]);
    }
}
