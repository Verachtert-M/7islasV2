<?php

// Check PHP version before loading any dependencies
$minPhpVersion = '8.1.0';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    http_response_code(500);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $errorResponse = [
        'error' => 'PHP Version Not Supported',
        'message' => "Elements API requires PHP {$minPhpVersion} or higher. Current version: " . PHP_VERSION,
        'current_version' => PHP_VERSION,
        'required_version' => $minPhpVersion,
        'timestamp' => date('c')
    ];

    echo json_encode($errorResponse, JSON_PRETTY_PRINT);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\ApiController;
use App\Controllers\CMSController;
use App\Controllers\EmailFormController;
use App\Controllers\WebhookController;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

// Load configuration
$config = require __DIR__ . '/config.php';

// Create Slim app
$app = AppFactory::create();

// Dynamically determine base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptName);
if ($basePath !== '/') {
    $app->setBasePath($basePath);
}

// Add JSON body parsing middleware
$app->addBodyParsingMiddleware();

// Initialize controllers
$controller = new ApiController($config);
$cmsController = new CMSController($config);

// Initialize form controller with dependencies
$container = new \App\Container\Container();
\App\Container\ServiceProvider::registerAll($container, $config);

// Initialize email form controller with dependencies
$emailFormController = $container->make(EmailFormController::class);

// Initialize webhook controller with dependencies
$webhookController = $container->make(WebhookController::class);

// Initialize spam protection middleware factory
$spamProtectionMiddlewareFactory = $container->make(\App\Middleware\SpamProtectionMiddlewareFactory::class);

// Add CORS middleware
$app->add(function (Request $request, RequestHandler $handler) {
    // Handle preflight OPTIONS requests
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Content-Type', 'application/json');
    }

    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
});

// Helper function to check if path ends with specific pattern
function pathEndsWith($path, $pattern)
{
    return preg_match($pattern, $path);
}

// Load and configure routes
$routes = require __DIR__ . '/routes.php';
$routes($app, $controller, $cmsController, $emailFormController, $webhookController, $spamProtectionMiddlewareFactory);

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Custom 404 handler
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function (Request $request, Response $response) use ($controller) {
        return $controller->notFound($request, $response);
    }
);

// Run the Slim application
$app->run();
