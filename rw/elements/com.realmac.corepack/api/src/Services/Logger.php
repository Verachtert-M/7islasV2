<?php

namespace App\Services;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Logger Service
 * Provides a configured Monolog logger instance with form-specific configuration support
 */
class Logger
{
    private static ?LoggerInterface $instance = null;
    private static array $contextLoggers = [];
    private static array $loggingConfig = [];
    private static ?string $currentContext = null;
    private static array $contextConfig = [];

    /**
     * Get logger instance (singleton)
     * Automatically routes to context-specific logger if context is set
     */
    public static function getInstance(): LoggerInterface
    {
        // If we have an active context, return the context logger instead
        if (self::$currentContext !== null) {
            return self::getContextLogger(self::$currentContext, self::$contextConfig);
        }

        if (self::$instance === null) {
            self::$instance = self::createLogger();
        }

        return self::$instance;
    }

    /**
     * Get or create a context-specific logger
     * 
     * @param string $id The unique identifier for this logger (e.g. 'form_contact', 'api_payments', 'webhook_stripe')
     * @param array $config Optional configuration
     */
    public static function getLogger(string $id, array $config = []): LoggerInterface
    {
        return self::getContextLogger($id, $config);
    }

    /**
     * Set the current logging context
     * This makes subsequent Logger::getInstance() calls use the context logger
     * 
     * @param string $id The unique identifier for this context
     * @param array $config Optional configuration
     */
    public static function setContext(string $id, array $config = []): void
    {
        self::$currentContext = $id;
        self::$contextConfig = $config;
    }

    /**
     * Clear the current logging context
     * Subsequent Logger::getInstance() calls will use the global logger
     */
    public static function clearContext(): void
    {
        self::$currentContext = null;
        self::$contextConfig = [];
    }

    /**
     * Execute a callable with a specific logging context
     * 
     * @param string $id The unique identifier for this context
     * @param array $config Optional configuration
     * @param callable $callback The function to execute with this context
     */
    public static function withContext(string $id, array $config, callable $callback)
    {
        $previousContext = self::$currentContext;
        $previousConfig = self::$contextConfig;

        try {
            self::setContext($id, $config);
            return $callback();
        } finally {
            self::$currentContext = $previousContext;
            self::$contextConfig = $previousConfig;
        }
    }

    /**
     * Get or create a context-specific logger (internal method)
     */
    private static function getContextLogger(string $contextId, array $config = []): LoggerInterface
    {
        $cacheKey = $contextId;

        if (isset(self::$contextLoggers[$cacheKey])) {
            return self::$contextLoggers[$cacheKey];
        }

        // Check if logging is disabled for this context
        if (isset($config['logging']['enabled']) && !$config['logging']['enabled']) {
            self::$contextLoggers[$cacheKey] = new NullLogger();
            return self::$contextLoggers[$cacheKey];
        }

        // Create context-specific logger
        $logger = self::createContextLogger($contextId, $config);
        self::$contextLoggers[$cacheKey] = $logger;

        return $logger;
    }

    /**
     * Set global logging configuration
     */
    public static function setLoggingConfig(array $config): void
    {
        self::$loggingConfig = $config;
    }

    /**
     * Check if logging is enabled for a context
     */
    public static function isLoggingEnabled(array $config = []): bool
    {
        return ($config['logging']['enabled'] ?? self::$loggingConfig['logging']['enabled'] ?? true) === true;
    }

    /**
     * Create a new logger instance
     */
    private static function createLogger(): LoggerInterface
    {
        $logger = new MonologLogger('app');

        // Use rotating file handler with size-based rotation
        $logFile = dirname(__DIR__, 2) . '/logs/app.log';
        $handler = self::createSizeBasedHandler($logFile, Level::Info, 5 * 1024 * 1024, 5); // 5MB, 5 files

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Create a context-specific logger (generic for any app component)
     */
    private static function createContextLogger(string $contextId, array $config): LoggerInterface
    {
        $loggerName = $contextId;
        $logger = new MonologLogger($loggerName);

        // Determine log level (default to Info to reduce log size)
        $level = Level::Info;
        if (isset($config['logging']['level'])) {
            $level = match (strtolower($config['logging']['level'])) {
                'debug' => Level::Debug,
                'info' => Level::Info,
                'warning', 'warn' => Level::Warning,
                'error' => Level::Error,
                'critical' => Level::Critical,
                default => Level::Info
            };
        }

        // Determine log file path
        $defaultLogFile = "{$contextId}.log";
        $logFile = dirname(__DIR__, 2) . "/logs/{$defaultLogFile}";

        if (isset($config['logging']['file'])) {
            $customFile = $config['logging']['file'];
            // If it's a relative path, make it relative to the logs directory
            if (!str_starts_with($customFile, '/')) {
                $logFile = dirname(__DIR__, 2) . "/logs/{$customFile}";
            } else {
                $logFile = $customFile;
            }
        }

        // Check if log rotation is enabled
        $loggingConfig = $config['logging'] ?? [];
        $useRotation = $loggingConfig['rotation']['enabled'] ?? true; // Default to enabled

        if ($useRotation) {
            // Use rotating file handler with size-based rotation
            $maxFiles = $loggingConfig['rotation']['max_files'] ?? 5;
            $maxSize = $loggingConfig['rotation']['max_size'] ?? '5MB'; // Reduced default from 10MB to 5MB

            // Always use size-based rotation for better control
            $parsedMaxSize = self::parseMaxSize($maxSize);
            if ($parsedMaxSize > 0) {
                // Use size-based rotation handler
                $handler = self::createSizeBasedHandler($logFile, $level, $parsedMaxSize, $maxFiles);
            } else {
                // Fallback to time-based rotation if size parsing fails
                $pathInfo = pathinfo($logFile);
                $baseLogFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'];

                if (empty($pathInfo['extension'])) {
                    $baseLogFile = $logFile;
                }

                if (!str_ends_with($baseLogFile, '.log')) {
                    $baseLogFile .= '.log';
                }

                $handler = new RotatingFileHandler($baseLogFile, $maxFiles, $level);
            }
        } else {
            // Use simple stream handler (no rotation)
            $handler = new StreamHandler($logFile, $level);
        }

        $logger->pushHandler($handler);

        // Automatically clean up old log files (10% chance to avoid performance impact)
        // This runs cleanup approximately once every 10 logger creations
        if (rand(1, 10) === 1) {
            self::cleanupOldLogs($contextId, 7); // Keep logs for 7 days by default
        }

        return $logger;
    }

    /**
     * Create a logger with custom name and log file
     */
    public static function create(string $name, ?string $logFile = null): LoggerInterface
    {
        $logger = new MonologLogger($name);

        $logPath = $logFile ?? dirname(__DIR__, 2) . "/logs/{$name}.log";

        // Use size-based rotation (5MB, 5 files) instead of unlimited growth
        $handler = self::createSizeBasedHandler($logPath, Level::Info, 5 * 1024 * 1024, 5);
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Parse max size string to bytes
     */
    private static function parseMaxSize(string $maxSize): int
    {
        $maxSize = trim(strtoupper($maxSize));

        // Extract number and unit
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?B?)$/', $maxSize, $matches)) {
            $number = (float) $matches[1];
            $unit = $matches[2] ?? 'B';

            return match ($unit) {
                'B', '' => (int) $number,
                'KB' => (int) ($number * 1024),
                'MB' => (int) ($number * 1024 * 1024),
                'GB' => (int) ($number * 1024 * 1024 * 1024),
                'TB' => (int) ($number * 1024 * 1024 * 1024 * 1024),
                default => (int) $number
            };
        }

        return 0; // Invalid format, disable size-based rotation
    }

    /**
     * Create a size-based rotating handler
     */
    private static function createSizeBasedHandler(string $logFile, Level $level, int $maxSize, int $maxFiles): StreamHandler
    {
        // Create a custom stream handler that checks file size before writing
        return new class($logFile, $level, $maxSize, $maxFiles) extends StreamHandler {
            private int $maxSize;
            private int $maxFiles;
            private string $baseLogFile;

            public function __construct(string $logFile, Level $level, int $maxSize, int $maxFiles)
            {
                $this->maxSize = $maxSize;
                $this->maxFiles = $maxFiles;
                $this->baseLogFile = $logFile;
                parent::__construct($logFile, $level);
            }

            protected function write(\Monolog\LogRecord $record): void
            {
                // Check if current log file exists and is too large
                if (file_exists($this->url) && filesize($this->url) >= $this->maxSize) {
                    $this->rotateFiles();
                }

                parent::write($record);
            }

            private function rotateFiles(): void
            {
                $logFile = $this->baseLogFile;
                $pathInfo = pathinfo($logFile);
                $baseName = $pathInfo['filename'];
                $extension = $pathInfo['extension'] ?? '';
                $directory = $pathInfo['dirname'];

                // Rotate existing files
                for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
                    $oldFile = $directory . '/' . $baseName . '.' . $i . ($extension ? '.' . $extension : '');
                    $newFile = $directory . '/' . $baseName . '.' . ($i + 1) . ($extension ? '.' . $extension : '');

                    if (file_exists($oldFile)) {
                        if ($i === $this->maxFiles - 1) {
                            // Delete the oldest file
                            unlink($oldFile);
                        } else {
                            // Rename to next number
                            rename($oldFile, $newFile);
                        }
                    }
                }

                // Move current log to .1
                if (file_exists($logFile)) {
                    $rotatedFile = $directory . '/' . $baseName . '.1' . ($extension ? '.' . $extension : '');
                    rename($logFile, $rotatedFile);
                }
            }
        };
    }

    /**
     * Get log file size in human readable format
     */
    public static function getLogFileSize(string $logFile): string
    {
        if (!file_exists($logFile)) {
            return '0 B';
        }

        $size = filesize($logFile);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Clean up old log files for a specific context (or all logs if no context specified)
     */
    public static function cleanupOldLogs(?string $contextId = null, int $daysToKeep = 30): int
    {
        $logsDir = dirname(__DIR__, 2) . '/logs';

        if ($contextId) {
            // Look for various log file patterns for specific context
            $patterns = [
                "{$contextId}*.log*",
                "form_{$contextId}*.log*",
                "*{$contextId}*.log*"
            ];
        } else {
            // Clean all log files
            $patterns = ["*.log*"];
        }

        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        $deletedCount = 0;

        foreach ($patterns as $pattern) {
            $files = glob($logsDir . '/' . $pattern);

            foreach ($files as $file) {
                if (file_exists($file) && filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Get log statistics for a specific context (or all logs if no context specified)
     */
    public static function getLogStats(?string $contextId = null): array
    {
        $logsDir = dirname(__DIR__, 2) . '/logs';

        if ($contextId) {
            // Look for various patterns for a specific context
            $patterns = [
                "{$contextId}.log",
                "{$contextId}-*.log",
                "{$contextId}.*.log",
                "form_{$contextId}.log",
                "form_{$contextId}-*.log",
                "form_{$contextId}.*.log",
                "*{$contextId}*.log*"
            ];
        } else {
            // Get all log files
            $patterns = ["*.log*"];
        }

        $files = [];
        foreach ($patterns as $pattern) {
            $matches = glob($logsDir . '/' . $pattern);
            if ($matches) {
                $files = array_merge($files, $matches);
            }
        }

        // Remove duplicates
        $files = array_unique($files);

        $stats = [
            'total_files' => count($files),
            'total_size' => 0,
            'total_size_formatted' => '0 B',
            'files' => []
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $size = filesize($file);
                $stats['total_size'] += $size;
                $stats['files'][] = [
                    'name' => basename($file),
                    'size' => $size,
                    'size_formatted' => self::getLogFileSize($file),
                    'modified' => filemtime($file),
                    'modified_formatted' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }

        $stats['total_size_formatted'] = self::formatBytes($stats['total_size']);

        // Sort files by modification time (newest first)
        usort($stats['files'], fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $stats;
    }

    /**
     * Format bytes to human readable format
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;

        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Reset all cached loggers (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$contextLoggers = [];
        self::$loggingConfig = [];
    }
}
