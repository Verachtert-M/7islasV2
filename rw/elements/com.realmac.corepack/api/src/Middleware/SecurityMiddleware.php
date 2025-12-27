<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security Middleware - Validates same-server requests
 * Apply this to routes that need protection
 */
class SecurityMiddleware implements MiddlewareInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Get the request path
        $path = $request->getUri()->getPath();

        // Exclude test-suite directory from security checks
        if ($this->isTestSuiteRequest($path)) {
            return $handler->handle($request);
        }

        // Check if request is from same server
        if (!$this->isValidOrigin($request)) {
            // Log security violation for debugging
            $debugInfo = $this->getDebugInfo($request);
            error_log('SecurityMiddleware: Blocked external request - ' . json_encode($debugInfo));

            return $this->createErrorResponse('API access restricted to same-server requests only', 403);
        }

        // Continue to next middleware/handler
        return $handler->handle($request);
    }

    private function isValidOrigin(Request $request): bool
    {
        $referer = $request->getHeaderLine('referer');
        $origin = $request->getHeaderLine('origin');
        $host = $request->getHeaderLine('host');

        // For security, we require at least one valid header (referer or origin)
        // External API calls typically don't send these headers

        // Check referer if present
        if ($referer && $this->hostMatches(parse_url($referer, PHP_URL_HOST), $host)) {
            return true;
        }

        // Check origin if present
        if ($origin && $this->hostMatches(parse_url($origin, PHP_URL_HOST), $host)) {
            return true;
        }

        // Special case: Allow localhost requests without headers (for development/testing)
        if ($this->isLocalhost($host)) {
            return true;
        }

        // Block all other requests (including external API calls)
        return false;
    }

    private function hostMatches(?string $host1, ?string $host2): bool
    {
        if (!$host1 || !$host2) {
            return false;
        }

        if ($host1 === $host2) {
            return true;
        }

        // Allow localhost variants for development
        return $this->isLocalhost($host1) && $this->isLocalhost($host2);
    }

    private function isLocalhost(string $host): bool
    {
        $localhostPatterns = [
            '/^localhost(:\d+)?$/',
            '/^127\.\d+\.\d+\.\d+(:\d+)?$/',
            '/^::1(:\d+)?$/',
            '/^0\.0\.0\.0(:\d+)?$/',
            '/\.local(:\d+)?$/'
        ];

        foreach ($localhostPatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    private function isTestSuiteRequest(string $path): bool
    {
        // Check if path contains test-suite directory
        return strpos($path, '/test-suite') !== false ||
            strpos($path, 'test-suite/') !== false ||
            basename(dirname($path)) === 'test-suite';
    }

    private function getDebugInfo(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'referer' => $request->getHeaderLine('referer') ?: 'none',
            'origin' => $request->getHeaderLine('origin') ?: 'none',
            'host' => $request->getHeaderLine('host') ?: 'none',
            'user_agent' => $request->getHeaderLine('user-agent') ?: 'none',
            'timestamp' => date('c')
        ];
    }

    private function createErrorResponse(string $message, int $status): Response
    {
        $data = [
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ];

        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
