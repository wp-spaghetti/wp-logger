<?php

declare(strict_types=1);

/*
 * This file is part of the Wp Logger package.
 *
 * (ɔ) Frugan <dev@frugan.it>
 *
 * This source file is subject to the GNU GPLv3 or later license that is bundled
 * with this source code in the file LICENSE.
 */

namespace WpSpaghetti\WpLogger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WpSpaghetti\WpEnv\Environment;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive WordPress logger with Wonolog integration, secure fallback, and environment-based configuration.
 *
 * Features:
 * - PSR-3 compatible logging interface
 * - Environment variables configuration via WP Env
 * - Automatic Wonolog detection and integration
 * - Secure file-based fallback logging
 * - Multi-server protection (Apache, Nginx, IIS, etc.)
 * - Environment-aware log levels and retention
 * - Configurable log retention and cleanup
 * - Hook-based customization system
 */
class Logger implements LoggerInterface
{
    /**
     * Default log retention days.
     */
    private const DEFAULT_LOG_RETENTION_DAYS = 30;

    /**
     * Cleanup probability (1 in N chance).
     */
    private const CLEANUP_PROBABILITY = 100;

    /**
     * Log file hash length.
     */
    private const LOG_FILE_HASH_LENGTH = 8;

    /**
     * Default configuration values.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_CONFIG = [
        'plugin_name' => null, // Required - can come from environment
        'log_retention_days' => self::DEFAULT_LOG_RETENTION_DAYS,
        'wonolog_namespace' => 'Inpsyde\Wonolog',
        'min_log_level' => LogLevel::DEBUG,
        'disable_logging_constant' => null, // Will be auto-generated if not provided
        'log_retention_constant' => null,   // Will be auto-generated if not provided
    ];

    /**
     * Log level priority mapping for minimum level filtering.
     *
     * @var array<string, int>
     */
    private const LOG_LEVEL_PRIORITIES = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * Configuration array.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Cache for Wonolog availability check.
     */
    private ?bool $wonologActiveCache = null;

    /**
     * Cache for hook-filtered values.
     */
    private ?string $wonologNamespace = null;

    /**
     * Cache for log directory path.
     */
    private ?string $logDirectoryCache = null;

    /**
     * Initialize logger with configuration and environment integration.
     *
     * @param array<string, mixed> $config Configuration array with the following options:
     *                                     - plugin_name: string - Unique identifier (can come from LOGGER_PLUGIN_NAME env var)
     *                                     - log_retention_days: int (default: 30) - Days to keep log files
     *                                     - wonolog_namespace: string (default: 'Inpsyde\Wonolog') - Wonolog namespace
     *                                     - min_log_level: string (default: 'debug') - Minimum log level to record
     *                                     - disable_logging_constant: string - WordPress constant name to disable logging
     *                                     - log_retention_constant: string - WordPress constant name for retention days
     *
     * @throws \InvalidArgumentException When plugin_name is missing from both config and environment
     */
    public function __construct(array $config = [])
    {
        // Get plugin name from config or environment
        $pluginName = $config['plugin_name'] ?? Environment::get('LOGGER_PLUGIN_NAME');

        if (!$pluginName || !\is_string($pluginName) || '' === trim($pluginName)) {
            throw new \InvalidArgumentException('plugin_name is required in configuration or LOGGER_PLUGIN_NAME environment variable');
        }

        // Build configuration with priority: Plugin-specific env > Global env > Constants > Config array > Defaults
        $this->config = $this->buildConfiguration($pluginName, $config);
    }

    /**
     * Get current configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * System is unusable.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Critical conditions.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Interesting events.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed $message
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Logs with an arbitrary level with environment-aware behavior.
     *
     * NOTE: Type hints removed for PSR Log ^1.0 compatibility.
     * TODO: Add back `string|\Stringable $message` type hint when PSR Log ^1.0 support is dropped.
     *
     * @param mixed                $level
     * @param mixed                $message
     * @param array<string, mixed> $context
     *
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, $message, array $context = []): void
    {
        // Check if this log level should be recorded
        if (!$this->shouldLog($level)) {
            return;
        }

        // Allow hook to override entire logging behavior
        if (\function_exists('apply_filters')) {
            $overrideResult = apply_filters('wp_logger_override_log', null, $level, $message, $context, $this->config);
            if (null !== $overrideResult) {
                return; // Custom logging handler took over
            }
        }

        if ($this->isWonologActive()) {
            $this->logViaWonolog($level, $message, $context);
        } else {
            $this->logViaFallback($level, $message, $context);
        }

        // Allow third-party plugins to hook into all logging
        if (\function_exists('do_action')) {
            do_action('wp_logger_logged', $level, $message, $context, $this->config['plugin_name']);
        }
    }

    /**
     * Get Wonolog logger instance if available.
     */
    public function getWonologLogger(): ?LoggerInterface
    {
        $wonologNamespace = $this->getWonologNamespace();
        $makeLoggerFunction = $wonologNamespace.'\makeLogger';

        if ($this->isWonologActive() && \function_exists($makeLoggerFunction)) {
            try {
                return $makeLoggerFunction();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Force refresh the Wonolog availability cache.
     */
    public function refreshWonologCache(): void
    {
        $this->wonologActiveCache = null;
        $this->wonologNamespace = null;
    }

    /**
     * Get comprehensive debug information including environment details.
     *
     * @return array<string, mixed>
     */
    public function getDebugInfo(): array
    {
        $retentionDays = $this->getLogRetentionDays();

        return [
            // Basic configuration
            'plugin_name' => $this->config['plugin_name'],
            'min_log_level' => $this->config['min_log_level'],
            'log_retention_days' => $retentionDays,
            'disable_constant' => $this->config['disable_logging_constant'],
            'retention_constant' => $this->config['log_retention_constant'],

            // Environment information
            'environment_type' => Environment::getEnvironment(),
            'is_debug' => Environment::isDebug(),
            'is_development' => Environment::isDevelopment(),
            'is_staging' => Environment::isStaging(),
            'is_production' => Environment::isProduction(),
            'is_container' => Environment::isContainer(),
            'server_software' => Environment::getServerSoftware(),
            'php_sapi' => Environment::getPhpSapi(),
            'is_cli' => Environment::isCli(),
            'is_web' => Environment::isWeb(),

            // Logging state
            'wonolog_active' => $this->isWonologActive(),
            'wonolog_namespace' => $this->getWonologNamespace(),
            'logging_disabled' => $this->isLoggingDisabled(),
            'log_directory' => $this->getLogDirectory(),

            // WordPress integration
            'wp_debug' => Environment::getBool('WP_DEBUG', false),
            'wp_multisite' => Environment::isMultisite(),

            // Constants status
            'constants_defined' => [
                $this->config['disable_logging_constant'] => \defined($this->config['disable_logging_constant']),
                $this->config['log_retention_constant'] => \defined($this->config['log_retention_constant']),
            ],
        ];
    }

    /**
     * Build final configuration with correct priority order.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function buildConfiguration(string $pluginName, array $config): array
    {
        // Start with defaults
        $finalConfig = self::DEFAULT_CONFIG;
        $finalConfig['plugin_name'] = $pluginName;

        // Merge configuration array
        $finalConfig = array_merge($finalConfig, $config);

        // Apply environment-based overrides
        $this->applyEnvironmentOverrides($finalConfig, $pluginName);

        // Auto-generate constant names if not provided
        $this->generateMissingConstants($finalConfig, $pluginName);

        return $finalConfig;
    }

    /**
     * Apply environment variable overrides with correct priority.
     *
     * @param array<string, mixed> $config
     */
    private function applyEnvironmentOverrides(array &$config, string $pluginName): void
    {
        // WordPress constants
        $retentionConstant = $this->generateConstantName($pluginName, 'LOG_RETENTION_DAYS');
        if (\defined($retentionConstant)) {
            $days = \constant($retentionConstant);
            if (\is_int($days) && $days > 0) {
                $config['log_retention_days'] = $days;
            }
        }

        // Global environment variables (higher priority)
        $globalRetention = Environment::getInt('LOGGER_RETENTION_DAYS');
        if ($globalRetention > 0) {
            $config['log_retention_days'] = $globalRetention;
        }

        $globalMinLevel = Environment::get('LOGGER_MIN_LEVEL');
        if ($globalMinLevel) {
            $config['min_log_level'] = $globalMinLevel;
        }

        $globalWonolog = Environment::get('LOGGER_WONOLOG_NAMESPACE');
        if ($globalWonolog) {
            $config['wonolog_namespace'] = $globalWonolog;
        }

        // Plugin-specific environment variables (highest priority)
        $pluginRetention = Environment::getInt($this->generateEnvKey($pluginName, 'LOG_RETENTION_DAYS'));
        if ($pluginRetention > 0) {
            $config['log_retention_days'] = $pluginRetention;
        }

        $pluginMinLevel = Environment::get($this->generateEnvKey($pluginName, 'MIN_LEVEL'));
        if ($pluginMinLevel) {
            $config['min_log_level'] = $pluginMinLevel;
        }
    }

    /**
     * Generate missing constant names.
     *
     * @param array<string, mixed> $config
     */
    private function generateMissingConstants(array &$config, string $pluginName): void
    {
        if (!$config['disable_logging_constant']) {
            $config['disable_logging_constant'] = $this->generateConstantName($pluginName, 'DISABLE_LOGGING');
        }

        if (!$config['log_retention_constant']) {
            $config['log_retention_constant'] = $this->generateConstantName($pluginName, 'LOG_RETENTION_DAYS');
        }
    }

    /**
     * Check if message should be logged based on minimum level configuration.
     */
    private function shouldLog(string $level): bool
    {
        $minPriority = self::LOG_LEVEL_PRIORITIES[$this->config['min_log_level']] ?? 0;
        $currentPriority = self::LOG_LEVEL_PRIORITIES[$level] ?? 0;

        return $currentPriority >= $minPriority;
    }

    /**
     * Generate plugin-specific environment variable key.
     */
    private function generateEnvKey(string $pluginName, string $key): string
    {
        $pluginPrefix = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $pluginName) ?? $pluginName);

        return \sprintf('%s_%s', $pluginPrefix, $key);
    }

    /**
     * Generate properly formatted constant name from plugin name.
     */
    private function generateConstantName(string $pluginName, string $suffix): string
    {
        // Convert plugin name to uppercase and replace non-alphanumeric chars with underscores
        $constantBase = preg_replace('/[^a-zA-Z0-9]/', '_', $pluginName);
        if (null === $constantBase) {
            $constantBase = $pluginName;
        }

        return strtoupper($constantBase).'_'.$suffix;
    }

    /**
     * Get Wonolog namespace with hook support.
     */
    private function getWonologNamespace(): string
    {
        if (null === $this->wonologNamespace) {
            if (\function_exists('apply_filters')) {
                $result = apply_filters('wp_logger_wonolog_namespace', $this->config['wonolog_namespace']);
                $this->wonologNamespace = \is_string($result) && '' !== $result ? $result : $this->config['wonolog_namespace'];
            } else {
                $this->wonologNamespace = $this->config['wonolog_namespace'];
            }
        }

        // Ensure we always return a string
        return $this->wonologNamespace ?? $this->config['wonolog_namespace'];
    }

    /**
     * Log via Wonolog with hook support.
     *
     * @param array<string, mixed> $context
     */
    private function logViaWonolog(string $level, string|\Stringable $message, array $context): void
    {
        $wonologNamespace = $this->getWonologNamespace();
        $logConstant = $wonologNamespace.'\LOG';

        if (!\defined($logConstant)) {
            return;
        }

        if (\function_exists('apply_filters')) {
            $wonologPrefix = apply_filters(
                'wp_logger_wonolog_prefix',
                \constant($logConstant),
                $level,
                $message,
                $context,
                $this->config
            );

            $actionName = apply_filters(
                'wp_logger_wonolog_action',
                $wonologPrefix.'.'.$level,
                $level,
                $message,
                $context,
                $this->config
            );
        } else {
            $actionName = \constant($logConstant).'.'.$level;
        }

        if (\function_exists('do_action')) {
            do_action($actionName, $message, $context);
        }
    }

    /**
     * Fallback logging when Wonolog is not available with environment-aware behavior.
     *
     * @param array<string, mixed> $context
     */
    private function logViaFallback(string $level, string|\Stringable $message, array $context): void
    {
        // Allow third-party plugins to handle logging when Wonolog is not available
        if (\function_exists('do_action')) {
            do_action('wp_logger_fallback', $level, $message, $context, $this->config['plugin_name']);
            do_action('wp_logger_fallback_'.$level, $message, $context, $this->config['plugin_name']);
        }

        // If debug is enabled, always use error_log (developer environment)
        if (Environment::isDebug() || Environment::isDevelopment()) {
            $this->logToErrorLog($level, $message, $context);

            return;
        }

        // Check if logging is explicitly disabled (production optimization)
        if ($this->isLoggingDisabled()) {
            return;
        }

        // Log to protected file in uploads directory (production environment)
        $this->logToFile($level, $message, $context);
    }

    /**
     * Check if logging is disabled via environment variables or WordPress constants.
     */
    private function isLoggingDisabled(): bool
    {
        // Check global environment variable first
        if (Environment::getBool('LOGGER_DISABLED')) {
            return true;
        }

        // Check plugin-specific environment variable
        $pluginEnvKey = $this->generateEnvKey($this->config['plugin_name'], 'DISABLED');
        if (Environment::getBool($pluginEnvKey)) {
            return true;
        }

        // Fallback to WordPress constant
        $constantName = $this->config['disable_logging_constant'];

        return \defined($constantName) && \constant($constantName);
    }

    /**
     * Get log retention days from configuration (already processed with correct priority).
     */
    private function getLogRetentionDays(): int
    {
        return $this->config['log_retention_days'];
    }

    /**
     * Check if Wonolog is active and configured.
     */
    private function isWonologActive(): bool
    {
        if (null !== $this->wonologActiveCache) {
            return $this->wonologActiveCache;
        }

        // Check if required WordPress functions exist
        if (!\function_exists('did_action')) {
            $this->wonologActiveCache = false;

            return false;
        }

        $wonologNamespace = $this->getWonologNamespace();
        $configuratorClass = $wonologNamespace.'\Configurator';

        // Check if Wonolog classes exist
        if (!class_exists($configuratorClass)) {
            $this->wonologActiveCache = false;

            return false;
        }

        $actionSetupConstant = $configuratorClass.'::ACTION_SETUP';

        // Check if the setup constant is defined
        if (!\defined($actionSetupConstant)) {
            $this->wonologActiveCache = false;

            return false;
        }

        // Check if Wonolog setup action was triggered
        $actionName = \constant($actionSetupConstant);
        $actionTriggered = did_action($actionName) > 0;

        $this->wonologActiveCache = $actionTriggered; // @phpstan-ignore-line

        return $this->wonologActiveCache;
    }

    /**
     * Get log directory path with caching.
     */
    private function getLogDirectory(): string
    {
        if (null === $this->logDirectoryCache) {
            if (!\function_exists('wp_upload_dir')) {
                $this->logDirectoryCache = '';
            } else {
                $uploadDir = wp_upload_dir();
                $this->logDirectoryCache = $uploadDir['basedir'].'/'.$this->config['plugin_name'].'/logs';
            }
        }

        return $this->logDirectoryCache;
    }

    /**
     * Log to protected file in WordPress uploads directory.
     *
     * @param array<string, mixed> $context
     */
    private function logToFile(string $level, string|\Stringable $message, array $context): void
    {
        if (!\function_exists('wp_upload_dir') || !\function_exists('wp_mkdir_p')) {
            return;
        }

        $logDir = $this->getLogDirectory();

        // Create directory structure if needed
        if (!file_exists($logDir)) {
            $this->createLogDirectoryStructure($logDir);
        }

        // Generate and write log entry
        $logEntry = $this->formatLogEntry($level, $message, $context);
        $this->writeLogEntry($logDir, $logEntry);

        // Probabilistic cleanup
        $this->maybeCleanupLogs($logDir);
    }

    /**
     * Create log directory structure with protection files.
     */
    private function createLogDirectoryStructure(string $logDir): void
    {
        $pluginDir = \dirname($logDir);

        if (!wp_mkdir_p($logDir)) {
            return;
        }

        $this->createProtectionFiles($pluginDir, $logDir);
    }

    /**
     * Format log entry for consistent output.
     *
     * @param array<string, mixed> $context
     */
    private function formatLogEntry(string $level, string|\Stringable $message, array $context): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $environmentInfo = Environment::isProduction() ? '' : ' ['.Environment::getEnvironment().']';

        $logEntry = \sprintf(
            "[%s]%s %s: %s\n",
            $timestamp,
            $environmentInfo,
            strtoupper($level),
            $this->formatMessage($message)
        );

        if (!empty($context)) {
            $contextJson = \function_exists('wp_json_encode')
                ? wp_json_encode($context)
                : json_encode($context);
            $logEntry .= 'Context: '.$contextJson."\n";
        }

        return $logEntry."---\n";
    }

    /**
     * Write log entry to file atomically.
     */
    private function writeLogEntry(string $logDir, string $logEntry): void
    {
        $logFile = $this->getLogFilePath($logDir);

        // Use file_put_contents with LOCK_EX for atomic writes
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get log file path with date and hash.
     */
    private function getLogFilePath(string $logDir): string
    {
        $datePrefix = gmdate('Y-m-d');
        $hash = substr(md5($this->config['plugin_name']), 0, self::LOG_FILE_HASH_LENGTH);

        return $logDir.'/'.$datePrefix.'_'.$hash.'.dat';
    }

    /**
     * Maybe cleanup old log files (probabilistic).
     */
    private function maybeCleanupLogs(string $logDir): void
    {
        if (\function_exists('wp_rand') && 1 === wp_rand(1, self::CLEANUP_PROBABILITY)) {
            $this->cleanupOldLogFiles($logDir);
        }
    }

    /**
     * Traditional error_log (used in development/debug mode).
     *
     * @param array<string, mixed> $context
     */
    private function logToErrorLog(string $level, string|\Stringable $message, array $context): void
    {
        $environmentInfo = '['.Environment::getEnvironment().']';

        $logEntry = \sprintf(
            '%s [%s] [%s] %s',
            $environmentInfo,
            $this->config['plugin_name'],
            strtoupper($level),
            $this->formatMessage($message)
        );

        if (!empty($context)) {
            $contextJson = \function_exists('wp_json_encode')
                ? wp_json_encode($context)
                : json_encode($context);
            $logEntry .= ' | Context: '.$contextJson;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in debug mode
        error_log($logEntry);
    }

    /**
     * Format message for consistent logging output.
     */
    private function formatMessage(string|\Stringable $message): string
    {
        // Handle Throwable objects
        if ($message instanceof \Throwable) {
            return \sprintf(
                '%s in %s:%d',
                $message->getMessage(),
                basename($message->getFile()),
                $message->getLine()
            );
        }

        // Handle WP_Error objects
        if (\function_exists('is_wp_error')
            && is_wp_error($message)
            && \is_object($message)
            && method_exists($message, 'get_error_message')) {
            return $message->get_error_message();
        }

        // Handle objects (encode as JSON)
        if (\is_object($message)) {
            $json = \function_exists('wp_json_encode')
                ? wp_json_encode($message)
                : json_encode($message);

            return 'Data: '.$json;
        }

        // Handle stringable or string
        return $message;
    }

    /**
     * Create protection files for multiple web servers.
     */
    private function createProtectionFiles(string $pluginDir, string $logDir): void
    {
        $pluginFolder = basename($pluginDir);

        // Apache protection (.htaccess)
        $htaccessContent = "# WordPress.org compliant log protection\n";
        $htaccessContent .= "Order deny,allow\n";
        $htaccessContent .= "Deny from all\n";
        $htaccessContent .= "<Files ~ \"\\.(dat|log)$\">\n";
        $htaccessContent .= "    deny from all\n";
        $htaccessContent .= "</Files>\n";
        file_put_contents($logDir.'/.htaccess', $htaccessContent);

        // Universal protection: index.php
        $indexContent = "<?php\n";
        $indexContent .= "// WordPress.org compliant log protection\n";
        $indexContent .= "// Prevents directory browsing and direct file access\n";
        $indexContent .= "http_response_code(403);\n";
        $indexContent .= "exit('Access denied');\n";
        file_put_contents($pluginDir.'/index.php', $indexContent);
        file_put_contents($logDir.'/index.php', $indexContent);

        // IIS Web.config
        $webconfigContent = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $webconfigContent .= "<configuration>\n";
        $webconfigContent .= "  <system.webServer>\n";
        $webconfigContent .= "    <authorization>\n";
        $webconfigContent .= "      <deny users=\"*\" />\n";
        $webconfigContent .= "    </authorization>\n";
        $webconfigContent .= "  </system.webServer>\n";
        $webconfigContent .= "</configuration>\n";
        file_put_contents($logDir.'/web.config', $webconfigContent);

        // Server configuration guide with environment info
        $serverConfig = $this->generateReadme($pluginFolder);
        file_put_contents($logDir.'/README', $serverConfig);
    }

    /**
     * Generate complete server configuration guide with environment information.
     */
    private function generateReadme(string $pluginFolder): string
    {
        $retentionDays = $this->getLogRetentionDays();
        $disableConstant = $this->config['disable_logging_constant'];
        $retentionConstant = $this->config['log_retention_constant'];
        $pluginEnvPrefix = $this->generateEnvKey($this->config['plugin_name'], '');

        $config = "# LOG DIRECTORY PROTECTION CONFIGURATION\n";
        $config .= "# ========================================\n\n";
        $config .= "This directory contains plugin log files and should be protected from direct access.\n";
        $config .= "The following configurations provide protection for different web servers:\n\n";

        // Add server-specific configurations...
        $config .= "## NGINX\n";
        $config .= "location ~* /wp-content/uploads/{$pluginFolder}/logs/ {\n";
        $config .= "    deny all;\n";
        $config .= "    return 403;\n";
        $config .= "}\n\n";

        $config .= "## APACHE\n";
        $config .= "# Already protected via .htaccess file (automatic)\n\n";

        $config .= "## LOGGING CONTROL\n";
        $config .= "You can control plugin logging behavior via environment variables or wp-config.php:\n\n";

        $config .= "# Environment Variables (recommended):\n";
        $config .= "# Global logger settings:\n";
        $config .= "LOGGER_DISABLED=false\n";
        $config .= \sprintf('LOGGER_RETENTION_DAYS=%d%s', $retentionDays, PHP_EOL);
        $config .= "LOGGER_MIN_LEVEL=info\n\n";

        $config .= "# Plugin-specific settings (higher priority):\n";
        $config .= $pluginEnvPrefix.'DISABLED=false'.PHP_EOL;
        $config .= "{$pluginEnvPrefix}LOG_RETENTION_DAYS={$retentionDays}\n\n";

        $config .= "# WordPress Constants (wp-config.php):\n";
        $config .= "# Enable debug logging (uses error_log):\n";
        $config .= "define('WP_DEBUG', true);\n";
        $config .= "define('WP_ENVIRONMENT_TYPE', 'development'); // or 'staging', 'production'\n\n";
        $config .= "# Disable all plugin logging:\n";
        $config .= "define('{$disableConstant}', true);\n\n";
        $config .= "# Customize log retention period:\n";
        $config .= "define('{$retentionConstant}', {$retentionDays});\n\n";

        $config .= 'Generated: '.gmdate('Y-m-d H:i:s')." UTC\n";
        $config .= 'Plugin: '.$this->config['plugin_name']."\n";
        $config .= 'Environment: '.Environment::getEnvironment()."\n";
        $config .= "Log retention: {$retentionDays} days\n";

        return $config.('Min log level: '.$this->config['min_log_level']."\n");
    }

    /**
     * Clean up old log files based on retention policy.
     */
    private function cleanupOldLogFiles(string $logDir): void
    {
        if (!is_dir($logDir)) {
            return;
        }

        $retentionDays = $this->getLogRetentionDays();
        $cutoffTime = time() - ($retentionDays * DAY_IN_SECONDS);

        // Use glob for better performance than scandir
        $logFiles = glob($logDir.'/*.{dat,log}', GLOB_BRACE);

        if (empty($logFiles)) {
            return;
        }

        foreach ($logFiles as $logFile) {
            if (filemtime($logFile) < $cutoffTime) {
                \function_exists('wp_delete_file')
                    ? wp_delete_file($logFile)
                    : unlink($logFile);
            }
        }
    }
}
