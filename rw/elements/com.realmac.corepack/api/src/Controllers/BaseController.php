<?php

namespace App\Controllers;

use App\Services\PathResolverService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Abstract Base Controller
 * Provides consistent security validation and common helper methods for all controllers
 */
abstract class BaseController
{
    protected array $config;
    protected PathResolverService $pathResolver;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->pathResolver = new PathResolverService($config);
    }

    // ========================================
    // SECURITY VALIDATION METHODS
    // ========================================

    /**
     * Handle request with consistent error handling and path resolution
     * Security is now handled by middleware, so controllers focus on business logic
     */
    protected function handleRequest(
        Request $request,
        Response $response,
        callable $handler,
        ?string $relativePath = null,
        string $contentType = 'application/json'
    ): Response {
        try {
            // Resolve content path if needed
            $contentPath = null;
            if ($relativePath !== null) {
                $contentPath = $this->resolveContentPath($request, $relativePath);
                if (!$contentPath) {
                    return $this->errorResponse($response, 'Could not determine content path', 400);
                }
            } else {
                // Try to resolve from request for CMS endpoints
                $contentPath = $this->resolveContentPath($request);
                if (!$contentPath) {
                    return $this->errorResponse($response, 'Could not determine content path', 400);
                }
            }

            // Execute handler with content path
            $result = $handler($contentPath);

            // Format response based on content type
            if ($contentType === 'application/json') {
                return $this->jsonResponse($response, $result);
            } else {
                // For XML/RSS responses, result is raw content
                $response->getBody()->write($result);
                return $response->withHeader('Content-Type', $contentType);
            }
        } catch (\Exception $e) {
            $status = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600
                ? $e->getCode()
                : 500;
            return $this->errorResponse($response, $e->getMessage(), $status);
        }
    }



    /**
     * Resolve content path using the internal path resolution system
     */
    protected function resolveContentPath(Request $request, ?string $relativePath = null): ?string
    {
        if ($relativePath) {
            return $this->pathResolver->resolveContentPath($relativePath, $request);
        } else {
            return $this->pathResolver->getContentPathFromRequest($request);
        }
    }

    /**
     * Resolve a path using depth-based navigation from the referer
     * This avoids sending "../" patterns in requests which trigger WAF rules
     * 
     * @param Request $request The HTTP request containing the referer header
     * @param string $path The clean path without "../" (e.g., "cms/content/blog")
     * @param int $depth Number of directory levels to go up from the referer
     * @return string|null The resolved absolute filesystem path, or null on failure
     */
    protected function resolvePathWithDepth(Request $request, string $path, int $depth): ?string
    {
        return $this->pathResolver->resolveWithDepth($path, $depth, $request);
    }

    // ========================================
    // COMMON HELPER METHODS
    // ========================================

    /**
     * Build filters from query parameters
     */
    protected function buildFilters(array $queryParams): array
    {
        $filters = [];

        // Status filtering
        if (isset($queryParams['status'])) {
            $filters['status'] = $queryParams['status'];
        }

        // Featured filtering
        if (isset($queryParams['featured'])) {
            $filters['featured'] = $queryParams['featured'] === 'true' || $queryParams['featured'] === true;
        }

        // Category filtering
        if (isset($queryParams['category'])) {
            $filters['category'] = $queryParams['category'];
        }

        // Tag filtering - support both string and array formats
        if (isset($queryParams['tags'])) {
            if (is_array($queryParams['tags'])) {
                $filters['tags'] = implode(',', $queryParams['tags']);
            } else {
                $filters['tags'] = $queryParams['tags'];
            }
        }

        // Author filtering - support both string and array formats
        if (isset($queryParams['author'])) {
            if (is_array($queryParams['author'])) {
                $filters['author'] = implode(',', $queryParams['author']);
            } else {
                $filters['author'] = $queryParams['author'];
            }
        }

        // Date filtering
        if (isset($queryParams['date_before'])) {
            $filters['date_before'] = $queryParams['date_before'];
        }
        if (isset($queryParams['date_after'])) {
            $filters['date_after'] = $queryParams['date_after'];
        }

        return $filters;
    }

    /**
     * Build options from query parameters
     */
    protected function buildOptions(array $queryParams): array
    {
        $options = [];

        if (isset($queryParams['case_insensitive']) && $queryParams['case_insensitive'] === 'true') {
            $options['case_insensitive'] = true;
        }

        return $options;
    }

    /**
     * Extract client IP address from request
     */
    protected function getClientIp(Request $request): string
    {
        // Check for shared internet/proxy
        if (!empty($request->getHeaderLine('HTTP_CLIENT_IP'))) {
            return $request->getHeaderLine('HTTP_CLIENT_IP');
        }
        // Check for remote address
        elseif (!empty($request->getHeaderLine('HTTP_X_FORWARDED_FOR'))) {
            return explode(',', $request->getHeaderLine('HTTP_X_FORWARDED_FOR'))[0];
        } elseif (!empty($request->getHeaderLine('HTTP_X_FORWARDED'))) {
            return $request->getHeaderLine('HTTP_X_FORWARDED');
        } elseif (!empty($request->getHeaderLine('HTTP_FORWARDED_FOR'))) {
            return $request->getHeaderLine('HTTP_FORWARDED_FOR');
        } elseif (!empty($request->getHeaderLine('HTTP_FORWARDED'))) {
            return $request->getHeaderLine('HTTP_FORWARDED');
        } else {
            // Return remote address
            $serverParams = $request->getServerParams();
            return $serverParams['REMOTE_ADDR'] ?? 'unknown';
        }
    }

    /**
     * Extract user agent from request
     */
    protected function getUserAgent(Request $request): string
    {
        return $request->getHeaderLine('user-agent') ?: 'unknown';
    }

    // ========================================
    // RESPONSE HELPER METHODS
    // ========================================

    /**
     * Generate JSON response
     */
    protected function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * Generate error response
     */
    protected function errorResponse(Response $response, string $message, int $status = 400): Response
    {
        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ], $status);
    }

    /**
     * Generate success response
     */
    protected function successResponse(Response $response, array $data = [], int $status = 200): Response
    {
        $responseData = array_merge([
            'success' => true,
            'timestamp' => date('c')
        ], $data);

        return $this->jsonResponse($response, $responseData, $status);
    }

    // ========================================
    // VALIDATION HELPER METHODS
    // ========================================

    /**
     * Validate required parameters
     */
    protected function validateRequired(array $data, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Sanitize string input
     */
    protected function sanitizeString(?string $input): string
    {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Convert value to boolean
     */
    protected function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    /**
     * Verify spam protection for a request
     */
    protected function verifySpamProtection(Request $request, array $formConfig = []): ?\App\ValueObjects\SpamProtectionResult
    {
        // Check if spam protection result is already in request attributes (from middleware)
        $result = $request->getAttribute('spam_protection_result');
        if ($result instanceof \App\ValueObjects\SpamProtectionResult) {
            return $result;
        }

        // If no middleware result, we can optionally verify manually here
        // For now, return null to indicate no verification was performed
        return null;
    }

    /**
     * Handle spam protection failure
     */
    protected function handleSpamProtectionFailure(Response $response, \App\ValueObjects\SpamProtectionResult $result): Response
    {
        return $this->errorResponse($response, $result->getError(), 400);
    }
}
