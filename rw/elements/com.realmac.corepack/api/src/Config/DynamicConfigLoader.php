<?php

namespace App\Config;

use App\Services\Logger;

/**
 * Dynamic Configuration Loader
 * Loads configuration from external files on the deployed server
 */
class DynamicConfigLoader
{
    private array $searchPaths;
    private array $loadedConfigs = [];
    private array $fallbackConfig;

    public function __construct(array $fallbackConfig = [], array $searchPaths = [])
    {
        $this->fallbackConfig = $fallbackConfig;
        $this->searchPaths = $this->initializeSearchPaths($searchPaths);
    }

    /**
     * Load configuration dynamically
     */
    public function loadConfig(string $configName = 'config'): array
    {
        $cacheKey = $configName;

        // Return cached config if already loaded
        if (isset($this->loadedConfigs[$cacheKey])) {
            return $this->loadedConfigs[$cacheKey];
        }

        $config = $this->fallbackConfig;

        // Try to load from each search path
        foreach ($this->searchPaths as $path) {
            $configFile = $this->findConfigFile($path, $configName);
            if ($configFile && is_readable($configFile)) {
                try {
                    $loadedConfig = $this->loadConfigFile($configFile);
                    if (is_array($loadedConfig)) {
                        $config = $this->mergeConfigs($config, $loadedConfig);
                        break; // Use first found config
                    }
                } catch (\Exception $e) {
                    Logger::getInstance()->error("Failed to load config from {$configFile}: " . $e->getMessage(), [
                        'config_file' => $configFile,
                        'exception' => $e
                    ]);
                    continue;
                }
            }
        }

        // Cache the loaded config
        $this->loadedConfigs[$cacheKey] = $config;

        return $config;
    }

    /**
     * Load form-specific configuration
     */
    public function loadFormConfig(string $formId): array
    {
        // Form-specific configs are no longer supported without explicit config path
        throw new \InvalidArgumentException('Form configuration loading requires explicit config_path parameter. No default forms are available.');
    }

    /**
     * Reload configuration (clears cache)
     */
    public function reloadConfig(string $configName = 'config'): array
    {
        unset($this->loadedConfigs[$configName]);
        return $this->loadConfig($configName);
    }

    /**
     * Get all loaded configurations
     */
    public function getLoadedConfigs(): array
    {
        return $this->loadedConfigs;
    }

    /**
     * Add a search path
     */
    public function addSearchPath(string $path): void
    {
        if (!in_array($path, $this->searchPaths)) {
            $this->searchPaths[] = $path;
        }
    }

    /**
     * Get current search paths
     */
    public function getSearchPaths(): array
    {
        return $this->searchPaths;
    }

    /**
     * Initialize search paths with common locations
     */
    private function initializeSearchPaths(array $customPaths = []): array
    {
        $paths = [];

        // Add custom paths first (highest priority)
        foreach ($customPaths as $path) {
            if (is_dir($path)) {
                $paths[] = rtrim($path, '/');
            }
        }

        // Environment-based paths
        $envConfigPath = $_ENV['CONFIG_PATH'] ?? $_SERVER['CONFIG_PATH'] ?? null;
        if ($envConfigPath && is_dir($envConfigPath)) {
            $paths[] = rtrim($envConfigPath, '/');
        }

        // Common deployment locations
        $commonPaths = [
            '/etc/webapp/config',           // System config directory
            '/var/www/config',              // Web server config
            '/app/config',                  // Docker/container config
            '/home/config',                 // User config
            dirname(__DIR__, 3) . '/config', // Relative to API
            dirname(__DIR__, 4) . '/config', // Parent directory
            dirname(__DIR__, 5) . '/config', // Grandparent directory
            __DIR__ . '/../../config',       // Alternative relative path
            __DIR__ . '/../../examples/config', // Examples config directory
        ];

        foreach ($commonPaths as $path) {
            if (is_dir($path) && !in_array($path, $paths)) {
                $paths[] = $path;
            }
        }

        // Fallback to current directory
        if (empty($paths)) {
            $paths[] = __DIR__ . '/../..';
        }

        return $paths;
    }

    /**
     * Find config file in a directory
     */
    private function findConfigFile(string $directory, string $configName): ?string
    {
        $extensions = ['php', 'json'];

        foreach ($extensions as $ext) {
            $file = $directory . '/' . $configName . '.' . $ext;
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Load configuration file based on extension
     */
    private function loadConfigFile(string $file): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'php':
                return $this->loadPhpConfig($file);
            case 'json':
                return $this->loadJsonConfig($file);
            default:
                throw new \InvalidArgumentException("Unsupported config file format: {$extension}");
        }
    }

    /**
     * Load PHP configuration file
     */
    private function loadPhpConfig(string $file): array
    {
        $config = include $file;

        if (!is_array($config)) {
            throw new \InvalidArgumentException("PHP config file must return an array: {$file}");
        }

        return $config;
    }

    /**
     * Load JSON configuration file
     */
    private function loadJsonConfig(string $file): array
    {
        $content = file_get_contents($file);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON in config file: {$file} - " . json_last_error_msg());
        }

        return $config ?? [];
    }

    /**
     * Merge configurations (deep merge)
     */
    private function mergeConfigs(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->mergeConfigs($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Create instance with environment-specific configuration
     */
    public static function createForEnvironment(string $environment = 'production', array $fallbackConfig = []): self
    {
        $searchPaths = [];

        // Environment-specific paths
        switch ($environment) {
            case 'production':
                $searchPaths = [
                    '/etc/webapp/config',
                    '/var/www/config',
                    '/app/config'
                ];
                break;
            case 'staging':
                $searchPaths = [
                    '/etc/webapp/staging/config',
                    '/var/www/staging/config',
                    '/app/staging/config'
                ];
                break;
            case 'development':
                $searchPaths = [
                    dirname(__DIR__, 3) . '/config',
                    __DIR__ . '/../../config',
                    __DIR__ . '/../../examples/config'
                ];
                break;
        }

        return new self($fallbackConfig, $searchPaths);
    }

    /**
     * Validate configuration structure
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Form configuration validation (simplified structure)
        if (isset($config['form'])) {
            $formConfig = $config['form'];
            if (!is_array($formConfig)) {
                $errors[] = "Form configuration must be an array";
            } else {
                // Validate email configuration for form
                if (isset($formConfig['email']['enabled']) && $formConfig['email']['enabled']) {
                    if (empty($formConfig['email']['to'])) {
                        $errors[] = "Form has email enabled but missing 'to' address";
                    }
                }

                // Validate webhook configuration for form
                if (isset($formConfig['webhook']['enabled']) && $formConfig['webhook']['enabled']) {
                    if (empty($formConfig['webhook']['url'])) {
                        $errors[] = "Form has webhook enabled but missing 'url'";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate form-specific configuration structure
     */
    public function validateFormConfig(array $config): array
    {
        $errors = [];

        // Required sections for form configs
        if (!isset($config['form'])) {
            $errors[] = "Missing required configuration section: form";
            return $errors;
        }

        $formConfig = $config['form'];
        if (!is_array($formConfig)) {
            $errors[] = "Form configuration must be an array";
            return $errors;
        }

        // Validate email configuration for form
        if (isset($formConfig['email']['enabled']) && $formConfig['email']['enabled']) {
            if (empty($formConfig['email']['to'])) {
                $errors[] = "Form has email enabled but missing 'to' address";
            }
        }

        // Validate webhook configuration for form
        if (isset($formConfig['webhook']['enabled']) && $formConfig['webhook']['enabled']) {
            if (empty($formConfig['webhook']['url'])) {
                $errors[] = "Form has webhook enabled but missing 'url'";
            }
        }

        return $errors;
    }

    /**
     * Load configuration from a specific file path
     */
    public function loadConfigFromPath(string $configPath): array
    {
        if (!file_exists($configPath)) {
            throw new \InvalidArgumentException("Configuration file not found: {$configPath}");
        }

        if (!is_readable($configPath)) {
            throw new \InvalidArgumentException("Configuration file not readable: {$configPath}");
        }

        try {
            $loadedConfig = $this->loadConfigFile($configPath);
            if (!is_array($loadedConfig)) {
                throw new \InvalidArgumentException("Configuration file must return an array: {$configPath}");
            }

            // Merge with fallback config
            $config = $this->mergeConfigs($this->fallbackConfig, $loadedConfig);

            // Cache with path as key
            $cacheKey = 'path:' . $configPath;
            $this->loadedConfigs[$cacheKey] = $config;

            Logger::getInstance()->info("Loaded config from specific path: {$configPath}");

            return $config;
        } catch (\Exception $e) {
            Logger::getInstance()->error("Failed to load config from path {$configPath}: " . $e->getMessage(), [
                'config_path' => $configPath,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Load form configuration from a specific config file path
     */
    public function loadFormConfigFromPath(string $configPath, string $formId): array
    {
        $config = $this->loadConfigFromPath($configPath);

        // Use the simplified 'form' configuration (singular) - no longer need formId lookup
        if (!isset($config['form'])) {
            throw new \InvalidArgumentException("Form configuration not found in config file: {$configPath}");
        }

        return $config['form'];
    }

    /**
     * Check if a config file path is valid and accessible
     */
    public function validateConfigPath(string $configPath): array
    {
        $result = [
            'valid' => false,
            'readable' => false,
            'exists' => false,
            'error' => null
        ];

        if (!file_exists($configPath)) {
            $result['error'] = "File does not exist: {$configPath}";
            return $result;
        }
        $result['exists'] = true;

        if (!is_readable($configPath)) {
            $result['error'] = "File is not readable: {$configPath}";
            return $result;
        }
        $result['readable'] = true;

        try {
            $config = $this->loadConfigFile($configPath);
            if (!is_array($config)) {
                $result['error'] = "Config file must return an array";
                return $result;
            }
            $result['valid'] = true;
        } catch (\Exception $e) {
            $result['error'] = "Failed to load config: " . $e->getMessage();
        }

        return $result;
    }
}
