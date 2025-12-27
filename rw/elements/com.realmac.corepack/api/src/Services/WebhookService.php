<?php

namespace App\Services;

use App\Services\Logger;
use Psr\Log\LoggerInterface;

/**
 * Simple webhook service for posting form data to a single URL
 */
class WebhookService
{
    private LoggerInterface $logger;

    public function __construct(private int $timeout = 30)
    {
        $this->logger = Logger::getInstance();
    }

    /**
     * Check if form data contains any uploaded files
     */
    public function hasFiles(array $formData): bool
    {
        foreach ($formData as $value) {
            if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send form data to webhook URL
     */
    public function send(string $url, array $formData): WebhookResponse
    {
        $startTime = microtime(true);

        $this->logger->info('Starting webhook request', [
            'url' => $url,
            'timeout' => $this->timeout,
            'form_data_keys' => array_keys($formData),
            'form_data_count' => count($formData)
        ]);

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => $this->timeout,
                'http_errors' => false
            ]);

            // Always use multipart
            $multipart = $this->prepareMultipartData($formData);

            $this->logger->debug('Prepared multipart data', [
                'multipart_count' => count($multipart),
                'multipart_fields' => array_column($multipart, 'name')
            ]);

            $this->logger->info('Sending HTTP POST request', [
                'url' => $url,
                'method' => 'POST',
                'content_type' => 'multipart/form-data'
            ]);

            $response = $client->post($url, [
                'multipart' => $multipart
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $success = $statusCode >= 200 && $statusCode < 300;

            $this->logger->info('Webhook request completed', [
                'url' => $url,
                'status_code' => $statusCode,
                'success' => $success,
                'duration_ms' => $duration,
                'response_size' => strlen($responseBody)
            ]);

            if (!$success) {
                $this->logger->warning('Webhook request failed', [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response_body' => substr($responseBody, 0, 500), // First 500 chars
                    'duration_ms' => $duration
                ]);
            } else {
                $this->logger->debug('Webhook response body', [
                    'url' => $url,
                    'response_body' => substr($responseBody, 0, 200) // First 200 chars for debug
                ]);
            }

            return new WebhookResponse($statusCode, $responseBody, $success);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('Webhook request exception', [
                'url' => $url,
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'duration_ms' => $duration,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Return error response
            return new WebhookResponse(0, $e->getMessage(), false);
        }
    }

    /**
     * Prepare multipart data (exactly like WebhookClient)
     */
    private function prepareMultipartData(array $formData): array
    {
        $multipart = [];
        $fileCount = 0;
        $fieldCount = 0;

        $this->logger->debug('Processing form data for multipart', [
            'total_fields' => count($formData)
        ]);

        // Process each field - handle both regular data and UploadedFileInterface
        foreach ($formData as $key => $value) {
            $this->logger->debug('Field type', [
                'key' => $key,
                'type' => gettype($value)
            ]);
            if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                $this->logger->debug('Processing uploaded file', [
                    'field_name' => $key,
                    'filename' => $value->getClientFilename(),
                    'size' => $value->getSize(),
                    'mime_type' => $value->getClientMediaType(),
                    'error' => $value->getError()
                ]);

                // Handle the single uploaded file (already filtered to first file in FormController)
                if ($value->getError() !== UPLOAD_ERR_OK) {
                    $this->logger->warning('Skipping file with upload error', [
                        'field_name' => $key,
                        'filename' => $value->getClientFilename(),
                        'error_code' => $value->getError(),
                        'error_message' => $this->getUploadErrorMessage($value->getError())
                    ]);
                    continue; // Skip files with errors
                }

                if (!$value->getSize()) {
                    $this->logger->warning('Skipping empty file', [
                        'field_name' => $key,
                        'filename' => $value->getClientFilename()
                    ]);
                    continue; // Skip empty files
                }

                $multipart[] = [
                    'name' => 'file',
                    'filename' => $value->getClientFilename(),
                    'contents' => $value->getStream(),
                    'headers' => ['Content-Type' => $value->getClientMediaType()]
                ];

                $fileCount++;
                $this->logger->info('Added file to multipart data', [
                    'field_name' => $key,
                    'filename' => $value->getClientFilename(),
                    'size' => $value->getSize(),
                    'mime_type' => $value->getClientMediaType()
                ]);
            } else {
                // Handle regular form fields
                $valueStr = is_array($value) ? json_encode($value) : (string)$value;

                $multipart[] = [
                    'name' => $key,
                    'contents' => $valueStr
                ];

                $fieldCount++;
                $this->logger->debug('Added field to multipart data', [
                    'field_name' => $key,
                    'value_type' => gettype($value),
                    'value_length' => strlen($valueStr),
                    'value_preview' => substr($valueStr, 0, 100) // First 100 chars
                ]);
            }
        }

        $this->logger->info('Completed multipart data preparation', [
            'total_multipart_fields' => count($multipart),
            'file_count' => $fileCount,
            'form_field_count' => $fieldCount
        ]);

        return $multipart;
    }

    /**
     * Get human-readable upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_OK => 'No error',
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => "Unknown error code: $errorCode"
        };
    }
}

/**
 * Simple webhook response
 */
class WebhookResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly bool $success
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
