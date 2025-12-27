<?php

/**
 * Centralized Twig Setup for RapidWeaver Elements
 * 
 * This file provides a unified Twig environment with all necessary extensions
 * and custom filters. Include this file in any component that needs Twig.
 */

// Ensure Composer autoload is included for dependency management
if (!isset($GLOBALS['__composer_autoload_files'])) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Initialize or get the global Twig environment
 * 
 * @return \Twig\Environment
 */
function getTwigEnvironment(): \Twig\Environment
{
    // Check if environment already exists
    if (isset($GLOBALS['twig_environment']) && $GLOBALS['twig_environment']) {
        return $GLOBALS['twig_environment'];
    }

    // Create loader if not exists
    if (!isset($GLOBALS['twig_loader'])) {
        $GLOBALS['twig_loader'] = new \Twig\Loader\ArrayLoader();
    }

    // Create new environment
    $GLOBALS['twig_environment'] = new \Twig\Environment($GLOBALS['twig_loader'], [
        'cache' => false,
        'debug' => false,
        'strict_variables' => false,
        'autoescape' => false
    ]);

    // Add custom truncate filter IMMEDIATELY after creating environment
    $truncateFilter = new \Twig\TwigFilter('truncate', function ($string, $length = 100, $ellipsis = '…') {
        if (strlen($string) <= $length) {
            return $string;
        }
        return substr($string, 0, $length) . $ellipsis;
    });
    $GLOBALS['twig_environment']->addFilter($truncateFilter);

    // Load standard Twig extensions (only if not already loaded)
    $extensions = $GLOBALS['twig_environment']->getExtensions();
    $extensionNames = array_map(function ($ext) {
        return get_class($ext);
    }, $extensions);

    if (!in_array('Twig\Extension\CoreExtension', $extensionNames)) {
        $GLOBALS['twig_environment']->addExtension(new \Twig\Extension\CoreExtension());
    }
    if (!in_array('Twig\Extension\EscaperExtension', $extensionNames)) {
        $GLOBALS['twig_environment']->addExtension(new \Twig\Extension\EscaperExtension());
    }
    if (!in_array('Twig\Extension\OptimizerExtension', $extensionNames)) {
        $GLOBALS['twig_environment']->addExtension(new \Twig\Extension\OptimizerExtension());
    }
    // Only add IntlExtension if PHP intl extension is available
    if (!in_array('Twig\Extra\Intl\IntlExtension', $extensionNames) && extension_loaded('intl')) {
        $GLOBALS['twig_environment']->addExtension(new \Twig\Extra\Intl\IntlExtension());
    }

    return $GLOBALS['twig_environment'];
}

/**
 * Global renderTemplate function
 * 
 * @param string $template   The Twig template as a string
 * @param array  $variables  Associative array of variables for the template
 * @return string            Rendered HTML output
 */
function renderTemplate($template, $variables = []): string
{
    $twig = getTwigEnvironment();
    return $twig->createTemplate($template)->render($variables);
}

/**
 * Add additional custom filters to the Twig environment
 * 
 * @param string $name     Filter name
 * @param callable $filter Filter function
 * @param array $options   Filter options
 */
function addTwigFilter(string $name, callable $filter, array $options = []): void
{
    $twig = getTwigEnvironment();
    $twigFilter = new \Twig\TwigFilter($name, $filter, $options);
    $twig->addFilter($twigFilter);
}

/**
 * Add additional custom functions to the Twig environment
 * 
 * @param string $name     Function name
 * @param callable $function Function callable
 * @param array $options   Function options
 */
function addTwigFunction(string $name, callable $function, array $options = []): void
{
    $twig = getTwigEnvironment();
    $twigFunction = new \Twig\TwigFunction($name, $function, $options);
    $twig->addFunction($twigFunction);
}

/**
 * Get the current Twig environment instance
 * 
 * @return \Twig\Environment|null
 */
function getCurrentTwigEnvironment(): ?\Twig\Environment
{
    return $GLOBALS['twig_environment'] ?? null;
}

/**
 * Clear the Twig environment cache (useful for development)
 */
function clearTwigEnvironment(): void
{
    unset($GLOBALS['twig_environment']);
    unset($GLOBALS['twig_loader']);
}

// Initialize the environment when this file is included
getTwigEnvironment();
