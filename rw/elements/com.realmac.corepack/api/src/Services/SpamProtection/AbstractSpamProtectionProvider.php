<?php

namespace App\Services\SpamProtection;

use App\Contracts\SpamProtectionProviderInterface;
use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Abstract base class for spam protection providers
 * Provides common functionality for token extraction and HTTP requests
 */
abstract class AbstractSpamProtectionProvider implements SpamProtectionProviderInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
            'verify' => true, // Enable SSL certificate verification
            'http_errors' => false // Don't throw exceptions on HTTP error status codes
        ]);
    }

    /**
     * Extract token from request using provider-specific field names
     */
    protected function extractToken(Request $request): ?string
    {
        $parsedBody = $request->getParsedBody() ?? [];
        $queryParams = $request->getQueryParams();

        // Try each token field for this provider
        foreach ($this->getTokenFields() as $field) {
            // Check POST body first
            if (isset($parsedBody[$field]) && !empty($parsedBody[$field])) {
                return $parsedBody[$field];
            }

            // Check query parameters as fallback
            if (isset($queryParams[$field]) && !empty($queryParams[$field])) {
                return $queryParams[$field];
            }
        }

        return null;
    }

    /**
     * Get client IP address from request
     */
    protected function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        // Check for shared internet/proxy
        if (!empty($serverParams['HTTP_CLIENT_IP'])) {
            return $serverParams['HTTP_CLIENT_IP'];
        }

        // Check for forwarded IP
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }

        if (!empty($serverParams['HTTP_X_FORWARDED'])) {
            return $serverParams['HTTP_X_FORWARDED'];
        }

        if (!empty($serverParams['HTTP_FORWARDED_FOR'])) {
            return $serverParams['HTTP_FORWARDED_FOR'];
        }

        if (!empty($serverParams['HTTP_FORWARDED'])) {
            return $serverParams['HTTP_FORWARDED'];
        }

        // Return remote address
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Make HTTP POST request to verification endpoint using Guzzle
     */
    protected function makeHttpRequest(string $url, array $data, int $timeout = 5): array
    {
        try {
            $response = $this->httpClient->post($url, [
                'form_params' => $data,
                'timeout' => $timeout,
                'headers' => [
                    'User-Agent' => 'RapidWeaver-Elements-API/1.0'
                ]
            ]);

            $responseBody = $response->getBody()->getContents();

            if (empty($responseBody)) {
                throw new \Exception('Empty response from verification service');
            }

            $decodedResponse = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from verification service: ' . json_last_error_msg());
            }

            return $decodedResponse;
        } catch (GuzzleException $e) {
            throw new \Exception('Failed to connect to verification service: ' . $e->getMessage());
        }
    }

    /**
     * Validate required configuration keys
     */
    protected function validateRequiredConfig(array $config, array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key]) || empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required configuration: {$key}");
            }
        }
    }

    /**
     * Create a failure result with provider context
     */
    protected function createFailureResult(string $error, ?string $errorCode = null, array $metadata = []): SpamProtectionResult
    {
        $metadata['provider'] = $this->getName();
        return SpamProtectionResult::failure($error, $errorCode, $metadata);
    }

    /**
     * Create a success result with provider context
     */
    protected function createSuccessResult(array $metadata = []): SpamProtectionResult
    {
        $metadata['provider'] = $this->getName();
        return SpamProtectionResult::success($metadata);
    }
}
