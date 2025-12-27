<?php

namespace App\Controllers;

use App\Services\PathResolverService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API Controller - Basic endpoints and utilities
 * Extends BaseController for consistency
 */
class ApiController extends BaseController
{
    // Inherits $config and $pathResolver from BaseController

    /**
     * Handle status endpoint - matches any path ending with /status or /api/status
     */
    public function status(Request $request, Response $response): Response
    {
        $data = [
            'success' => true,
            'status' => 'ok',
            'message' => 'Elements API is working as expected.',
            'timestamp' => date('c'),
            'version' => $this->config['API_VERSION'] ?? '1.0.0',
        ];

        return $this->jsonResponse($response, $data);
    }

    /**
     * Handle root/default requests with debug information
     */
    public function root(Request $request, Response $response): Response
    {
        $data = [
            'success' => true,
            'message' => 'ElementsAPI is running',
            'timestamp' => date('c')
        ];

        return $this->jsonResponse($response, $data);
    }

    /**
     * Handle 404 errors
     */
    public function notFound(Request $request, Response $response): Response
    {
        $data = [
            'success' => false,
            'error' => 'Endpoint not found',
            'timestamp' => date('c')
        ];

        return $this->jsonResponse($response, $data, 404);
    }

    /**
     * Get content path from request (public endpoint - no security validation)
     */
    public function contentPath(Request $request, Response $response): Response
    {
        try {
            $contentPath = $this->pathResolver->getContentPathFromRequest($request);
            if (!$contentPath) {
                return $this->errorResponse($response, 'Could not determine content path', 500);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'content_path' => $contentPath,
                'timestamp' => date('c')
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Internal error', 500);
        }
    }
}
