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
 * - Support for custom PSR Log namespace (Mozart compatibility)
 */
class Logger implements LoggerInterface
{
    /**
     * Default log retention days.
     */
    private const DEFAULT_RETENTION_DAYS = 30;

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
        'component_name' => null, // Required - can come from environment
        'retention_days' => self::DEFAULT_RETENTION_DAYS,
        'wonolog_namespace' => 'Inpsyde\Wonolog',
        'psr_log_namespace' => 'Psr\Log',
        'min_level' => LogLevel::DEBUG,
        'disabled_constant' => null, // Will be auto-generated if not provided
        'retention_days_constant' => null,   // Will be auto-generated if not provided
    ];

    /**
     * Configuration array.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Component prefix for environment variables (cached for performance).
     */
    private string $componentEnvPrefix;

    /**
     * Cache for Wonolog availability check.
     */
    private ?bool $wonologActiveCache = null;

    /**
     * Cache for hook-filtered values.
     */
    private ?string $wonologNamespace = null;

    /**
     * Cache for PSR Log namespace.
     */
    private ?string $psrLogNamespace = null;

    /**
     * Cache for log directory path.
     */
    private ?string $logDirectoryCache = null;

    /**
     * Cache for PSR Log level priority mapping with custom namespace.
     *
     * @var null|array<string, int>
     */
    private ?array $psrLogLevels = null;

    /**
     * Initialize logger with configuration and environment integration.
     *
     * @param array<string, mixed> $config Configuration array with the following options:
     *                                     - component_name: string - Unique identifier (can come from LOGGER_COMPONENT_NAME env var)
     *                                     - retention_days: int (default: 30) - Days to keep log files
     *                                     - wonolog_namespace: string (default: 'Inpsyde\Wonolog') - Wonolog namespace
     *                                     - psr_log_namespace: string (default: 'Psr\Log') - PSR Log namespace
     *                                     - min_level: string (default: 'debug') - Minimum log level to record
     *                                     - disabled_constant: string - WordPress constant name to disable logging
     *                                     - retention_days_constant: string - WordPress constant name for retention days
     *
     * @throws \InvalidArgumentException When component_name is missing from both config and environment
     */
    public function __construct(array $config = [])
    {
        // Get component name from config or environment
        $componentName = $config['component_name'] ?? Environment::get('LOGGER_COMPONENT_NAME');

        if (!$componentName || !\is_string($componentName) || '' === trim($componentName)) {
            throw new \InvalidArgumentException('component_name is required in configuration or LOGGER_COMPONENT_NAME environment variable');
        }

        // Generate and cache component prefix
        $this->componentEnvPrefix = $this->generateComponentEnvPrefix($componentName);

        // Build configuration with priority: Plugin-specific env > Global env > Constants > Config array > Defaults
        $this->config = $this->buildConfiguration($componentName, $config);
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
     * Logs with an arbitrary level with environment-aware behavior.
     *
     * MESSAGE TYPE COMPATIBILITY:
     *
     * - Wonolog (primary target): Accepts truly mixed types natively
     *   ✅ Throwable, WP_Error, Arrays, Objects, Strings - all supported
     *
     * - PSR Log ^1.0: Uses mixed type hint but expects primarily strings
     *   ⚠️  Throwable/Objects/Arrays not officially supported by spec
     *
     * - PSR Log ^2.0+: Strictly enforces string|\Stringable only
     *   ❌ Throwable, Arrays, Objects cause TypeError
     *
     * DESIGN DECISION:
     * WP Logger maintains Wonolog-compatible design for maximum flexibility
     * in the WordPress ecosystem. When Wonolog is active, all mixed types
     * are passed through natively. In fallback mode, types are converted
     * to strings via formatMessage().
     *
     * ACCEPTED TYPES:
     * - Throwable objects: $logger->error($exception)
     * - WP_Error objects: $logger->error($wpError)
     * - Strings: $logger->error('message')
     * - Arrays: $logger->error(['user' => 123])
     * - Objects: $logger->error($customObject)
     *
     * @param mixed                $level
     * @param mixed                $message - Accepts any type (Throwable, WP_Error, string, array, object)
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
            do_action('wp_logger_logged', $level, $message, $context, $this->config['component_name']);
        }
    }

    /**
     * System is unusable.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function emergency($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('EMERGENCY'), $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function alert($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('ALERT'), $message, $context);
    }

    /**
     * Critical conditions.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function critical($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('CRITICAL'), $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function error($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('ERROR'), $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function warning($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('WARNING'), $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function notice($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('NOTICE'), $message, $context);
    }

    /**
     * Interesting events.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function info($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('INFO'), $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    public function debug($message, array $context = []): void
    {
        $this->log($this->getPsrLogLevel('DEBUG'), $message, $context);
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
     * Force refresh the PSR Log namespace cache.
     */
    public function refreshPsrLogCache(): void
    {
        $this->psrLogNamespace = null;
        $this->psrLogLevels = null;
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
            'component_name' => $this->config['component_name'],
            'min_level' => $this->config['min_level'],
            'retention_days' => $retentionDays,
            'disabled_constant' => $this->config['disabled_constant'],
            'retention_days_constant' => $this->config['retention_days_constant'],

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
            'psr_log_namespace' => $this->getPsrLogNamespace(),
            'logging_disabled' => $this->isLoggingDisabled(),
            'log_directory' => $this->getLogDirectory(),

            // WordPress integration
            'wp_debug' => Environment::getBool('WP_DEBUG', false),
            'wp_multisite' => Environment::isMultisite(),

            // Constants status
            'constants_defined' => [
                $this->config['disabled_constant'] => \defined($this->config['disabled_constant']),
                $this->config['retention_days_constant'] => \defined($this->config['retention_days_constant']),
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
    private function buildConfiguration(string $componentName, array $config): array
    {
        // Start with defaults
        $finalConfig = self::DEFAULT_CONFIG;
        $finalConfig['component_name'] = $componentName;

        // Merge configuration array
        $finalConfig = array_merge($finalConfig, $config);

        // Apply environment-based overrides
        $this->applyEnvironmentOverrides($finalConfig);

        // Auto-generate constant names if not provided
        $this->generateMissingConstants($finalConfig);

        return $finalConfig;
    }

    /**
     * Apply environment variable overrides with correct priority.
     *
     * @param array<string, mixed> $config
     */
    private function applyEnvironmentOverrides(array &$config): void
    {
        // WordPress constants
        $retentionConstant = $this->buildConstantName('RETENTION_DAYS');
        if (\defined($retentionConstant)) {
            $days = \constant($retentionConstant);
            if (\is_int($days) && $days > 0) {
                $config['retention_days'] = $days;
            }
        }

        // Global environment variables (higher priority)
        $globalRetention = Environment::getInt('LOGGER_RETENTION_DAYS');
        if ($globalRetention > 0) {
            $config['retention_days'] = $globalRetention;
        }

        $globalMinLevel = Environment::get('LOGGER_MIN_LEVEL');
        if ($globalMinLevel) {
            $config['min_level'] = $globalMinLevel;
        }

        $globalWonolog = Environment::get('LOGGER_WONOLOG_NAMESPACE');
        if ($globalWonolog) {
            $config['wonolog_namespace'] = $globalWonolog;
        }

        $globalPsrLog = Environment::get('LOGGER_PSR_LOG_NAMESPACE');
        if ($globalPsrLog) {
            $config['psr_log_namespace'] = $globalPsrLog;
        }

        // Plugin-specific environment variables (highest priority)
        $componentRetention = Environment::getInt($this->buildConstantName('RETENTION_DAYS'));
        if ($componentRetention > 0) {
            $config['retention_days'] = $componentRetention;
        }

        $componentMinLevel = Environment::get($this->buildConstantName('MIN_LEVEL'));
        if ($componentMinLevel) {
            $config['min_level'] = $componentMinLevel;
        }

        $componentPsrLog = Environment::get($this->buildConstantName('PSR_LOG_NAMESPACE'));
        if ($componentPsrLog) {
            $config['psr_log_namespace'] = $componentPsrLog;
        }
    }

    /**
     * Generate missing constant names.
     *
     * @param array<string, mixed> $config
     */
    private function generateMissingConstants(array &$config): void
    {
        if (!$config['disabled_constant']) {
            $config['disabled_constant'] = $this->buildConstantName('DISABLED');
        }

        if (!$config['retention_days_constant']) {
            $config['retention_days_constant'] = $this->buildConstantName('RETENTION_DAYS');
        }
    }

    /**
     * Check if message should be logged based on minimum level configuration.
     */
    private function shouldLog(string $level): bool
    {
        // Get the log levels with current PSR Log namespace
        $logLevels = $this->getPsrLogLevels();
        $minPriority = $logLevels[$this->config['min_level']] ?? 0;
        $currentPriority = $logLevels[$level] ?? 0;

        return $currentPriority >= $minPriority;
    }

    /**
     * Generate component prefix for environment variables.
     *
     * Transforms component name to uppercase with underscores, following wp-vite pattern.
     * Examples: 'my-plugin' → 'MY_PLUGIN_LOGGER', 'camelCase' → 'CAMELCASE_LOGGER'
     */
    private function generateComponentEnvPrefix(string $componentName): string
    {
        $normalizedName = preg_replace('/[^a-zA-Z0-9]/', '_', $componentName) ?? $componentName;

        return strtoupper($normalizedName).'_LOGGER';
    }

    /**
     * Build component-specific environment variable name.
     *
     * Examples: 'LOGGER_DISABLED' → 'MY_PLUGIN_LOGGER_DISABLED'
     */
    private function buildConstantName(string $suffix): string
    {
        return \sprintf('%s_%s', $this->componentEnvPrefix, $suffix);
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
     * Get PSR Log namespace with hook support.
     */
    private function getPsrLogNamespace(): string
    {
        if (null === $this->psrLogNamespace) {
            if (\function_exists('apply_filters')) {
                $result = apply_filters('wp_logger_psr_log_namespace', $this->config['psr_log_namespace']);
                $this->psrLogNamespace = \is_string($result) && '' !== $result ? $result : $this->config['psr_log_namespace'];
            } else {
                $this->psrLogNamespace = $this->config['psr_log_namespace'];
            }
        }

        // Ensure we always return a string
        return $this->psrLogNamespace ?? $this->config['psr_log_namespace'];
    }

    /**
     * Get PSR Log level constant from custom namespace.
     */
    private function getPsrLogLevel(string $level): string
    {
        $psrNamespace = $this->getPsrLogNamespace();

        // For default namespace, use static constants for better performance
        if ('Psr\Log' === $psrNamespace) {
            return match ($level) {
                'EMERGENCY' => LogLevel::EMERGENCY,
                'ALERT' => LogLevel::ALERT,
                'CRITICAL' => LogLevel::CRITICAL,
                'ERROR' => LogLevel::ERROR,
                'WARNING' => LogLevel::WARNING,
                'NOTICE' => LogLevel::NOTICE,
                'INFO' => LogLevel::INFO,
                'DEBUG' => LogLevel::DEBUG,
                default => LogLevel::DEBUG,
            };
        }

        // For custom namespace, construct dynamically
        $logLevelClass = $psrNamespace.'\LogLevel';
        if (class_exists($logLevelClass) && \defined($logLevelClass.'::'.$level)) {
            return \constant($logLevelClass.'::'.$level);
        }

        // Fallback to standard values if custom namespace doesn't work
        return match ($level) {
            'EMERGENCY' => 'emergency',
            'ALERT' => 'alert',
            'CRITICAL' => 'critical',
            'ERROR' => 'error',
            'WARNING' => 'warning',
            'NOTICE' => 'notice',
            'INFO' => 'info',
            'DEBUG' => 'debug',
            default => 'debug',
        };
    }

    /**
     * Get PSR Log level priority mapping with custom namespace support.
     *
     * @return array<string, int>
     */
    private function getPsrLogLevels(): array
    {
        if (null !== $this->psrLogLevels) {
            return $this->psrLogLevels;
        }

        // Use standard level names as keys, priorities as values
        // This works regardless of PSR Log namespace
        $this->psrLogLevels = [
            'debug' => 0,
            'info' => 1,
            'notice' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
            'alert' => 6,
            'emergency' => 7,
        ];

        return $this->psrLogLevels;
    }

    /**
     * Log via Wonolog with native mixed type support.
     *
     * Unlike PSR Log ^2.0+ (string|\Stringable only), Wonolog was designed
     * for WordPress ecosystem flexibility and accepts truly mixed types:
     * - Throwable objects (exceptions)
     * - WP_Error objects
     * - Arrays and Objects (structured data)
     * - Strings and primitives
     *
     * This allows natural WordPress-style logging without type conversions.
     *
     * @param mixed                $message - Accepts mixed types, conversion happens in formatMessage()
     * @param array<string, mixed> $context
     */
    private function logViaWonolog(string $level, mixed $message, array $context): void
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
            // BUGFIX: Wonolog v2.x/v3.x PSR-3 placeholder substitution compatibility
            //
            // HookLogFactory::fromString() wraps context as $arguments = [0 => $context],
            // breaking PsrLogMessageProcessor placeholders like {handle} and {url}.
            //
            // Using array format forces fromArray() method which preserves correct
            // context structure. This works with all current Wonolog versions and
            // has no negative side effects.
            $logData = [
                'message' => $message,
                'context' => $context,
            ];
            do_action($actionName, $logData);
        }
    }

    /**
     * Fallback logging when Wonolog is not available with environment-aware behavior.
     *
     * @param mixed                $message - Accepts mixed types, conversion happens in formatMessage()
     * @param array<string, mixed> $context
     */
    private function logViaFallback(string $level, mixed $message, array $context): void
    {
        // Allow third-party plugins to handle logging when Wonolog is not available
        if (\function_exists('do_action')) {
            do_action('wp_logger_fallback', $level, $message, $context, $this->config['component_name']);
            do_action('wp_logger_fallback_'.$level, $message, $context, $this->config['component_name']);
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

        // Check component-specific environment variable
        if (Environment::getBool($this->buildConstantName('DISABLED'))) {
            return true;
        }

        // Fallback to WordPress constant
        $constantName = $this->config['disabled_constant'];

        return \defined($constantName) && \constant($constantName);
    }

    /**
     * Get log retention days from configuration (already processed with correct priority).
     */
    private function getLogRetentionDays(): int
    {
        return $this->config['retention_days'];
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
                $this->logDirectoryCache = $uploadDir['basedir'].'/'.$this->config['component_name'].'/logs';
            }
        }

        return $this->logDirectoryCache;
    }

    /**
     * Log to protected file in WordPress uploads directory.
     *
     * @param mixed                $message - Passes mixed message to formatLogEntry() for conversion
     * @param array<string, mixed> $context
     */
    private function logToFile(string $level, mixed $message, array $context): void
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
        $componentDir = \dirname($logDir);

        if (!wp_mkdir_p($logDir)) {
            return;
        }

        $this->createProtectionFiles($componentDir, $logDir);
    }

    /**
     * Format log entry for consistent output.
     *
     * @param mixed                $message - Calls formatMessage() to convert mixed → string
     * @param array<string, mixed> $context
     */
    private function formatLogEntry(string $level, mixed $message, array $context): string
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
        $hash = substr(md5($this->config['component_name']), 0, self::LOG_FILE_HASH_LENGTH);

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
     * @param mixed                $message - Calls formatMessage() to convert mixed → string
     * @param array<string, mixed> $context
     */
    private function logToErrorLog(string $level, mixed $message, array $context): void
    {
        $environmentInfo = '['.Environment::getEnvironment().']';

        $logEntry = \sprintf(
            '%s [%s] [%s] %s',
            $environmentInfo,
            $this->config['component_name'],
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
     * Format message for fallback logging (when Wonolog not available).
     *
     * Converts mixed types to strings for traditional logging methods.
     * Only used in fallback mode - when Wonolog is active, mixed types
     * are passed through natively without conversion.
     *
     * SUPPORTED CONVERSIONS:
     * - Throwable → "Message in file.php:123"
     * - WP_Error → error message string
     * - Arrays/Objects → JSON representation
     * - Primitives → string cast
     *
     * @param mixed $message - Accepts any type (Throwable, WP_Error, string, array, object)
     */
    private function formatMessage(mixed $message): string
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
    private function createProtectionFiles(string $componentDir, string $logDir): void
    {
        $componentFolder = basename($componentDir);

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
        file_put_contents($componentDir.'/index.php', $indexContent);
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
        $serverConfig = $this->generateReadme($componentFolder);
        file_put_contents($logDir.'/README', $serverConfig);
    }

    /**
     * Generate complete server configuration guide with environment information.
     */
    private function generateReadme(string $componentFolder): string
    {
        $retentionDays = $this->getLogRetentionDays();
        $disableConstant = $this->config['disabled_constant'];
        $retentionConstant = $this->config['retention_days_constant'];

        $config = "# LOG DIRECTORY PROTECTION CONFIGURATION\n";
        $config .= "# ========================================\n\n";
        $config .= "This directory contains plugin/theme log files and should be protected from direct access.\n";
        $config .= "The following configurations provide protection for different web servers:\n\n";

        // Add server-specific configurations...
        $config .= "## NGINX\n";
        $config .= "location ~* /wp-content/uploads/{$componentFolder}/logs/ {\n";
        $config .= "    deny all;\n";
        $config .= "    return 403;\n";
        $config .= "}\n\n";

        $config .= "## APACHE\n";
        $config .= "# Already protected via .htaccess file (automatic)\n\n";

        $config .= "## LOGGING CONTROL\n";
        $config .= "You can control plugin/theme logging behavior via environment variables or wp-config.php:\n\n";

        $config .= "# Environment Variables (recommended):\n";
        $config .= "# Global logger settings:\n";
        $config .= "LOGGER_DISABLED=false\n";
        $config .= \sprintf('LOGGER_RETENTION_DAYS=%d%s', $retentionDays, PHP_EOL);
        $config .= "LOGGER_MIN_LEVEL=info\n";
        $config .= "LOGGER_PSR_LOG_NAMESPACE=Psr\\Log\n\n";

        $config .= "# Plugin/Theme-specific settings (higher priority):\n";
        $config .= $this->buildConstantName('DISABLED')."=false\n";
        $config .= $this->buildConstantName('RETENTION_DAYS').\sprintf('=%d%s', $retentionDays, PHP_EOL);
        $config .= $this->buildConstantName('MIN_LEVEL')."=warning\n";
        $config .= $this->buildConstantName('PSR_LOG_NAMESPACE')."=MyPlugin\\Vendor\\Psr\\Log\n\n";

        $config .= "# WordPress Constants (wp-config.php):\n";
        $config .= "# Enable debug logging (uses error_log):\n";
        $config .= "define('WP_DEBUG', true);\n";
        $config .= "define('WP_ENVIRONMENT_TYPE', 'development'); // or 'staging', 'production'\n\n";
        $config .= "# Disable all plugin/theme logging:\n";
        $config .= "define('{$disableConstant}', true);\n\n";
        $config .= "# Customize log retention period:\n";
        $config .= "define('{$retentionConstant}', {$retentionDays});\n\n";

        $config .= 'Generated: '.gmdate('Y-m-d H:i:s')." UTC\n";
        $config .= 'Plugin/Theme: '.$this->config['component_name']."\n";
        $config .= 'Environment: '.Environment::getEnvironment()."\n";
        $config .= "Log retention: {$retentionDays} days\n";
        $config .= 'Min log level: '.$this->config['min_level']."\n";

        return $config.('PSR Log namespace: '.$this->getPsrLogNamespace()."\n");
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
