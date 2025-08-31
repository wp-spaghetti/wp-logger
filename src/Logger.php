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

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive WordPress logger with Wonolog integration and secure fallback.
 *
 * Features:
 * - PSR-3 compatible logging interface
 * - Automatic Wonolog detection and integration
 * - Secure file-based fallback logging
 * - Multi-server protection (Apache, Nginx, IIS, etc.)
 * - Configurable log retention and cleanup
 * - Hook-based customization system
 */
class Logger implements LoggerInterface
{
    /**
     * Default configuration values.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_CONFIG = [
        'plugin_name' => 'wp-logger',
        'log_retention_days' => 30,
        'wonolog_namespace' => 'Inpsyde\Wonolog',
        'disable_logging_constant' => null, // Will be auto-generated if not provided
        'log_retention_constant' => null,   // Will be auto-generated if not provided
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
     * Initialize logger with configuration.
     *
     * @param array<string, mixed> $config Configuration array with the following options:
     *                                     - plugin_name: string (required) - Unique identifier for your plugin/theme
     *                                     - log_retention_days: int (default: 30) - Days to keep log files
     *                                     - wonolog_namespace: string (default: 'Inpsyde\Wonolog') - Wonolog namespace
     *                                     - disable_logging_constant: string - WordPress constant name to disable logging
     *                                     - log_retention_constant: string - WordPress constant name for retention days
     *
     * @throws \InvalidArgumentException When plugin_name is missing or empty
     */
    public function __construct(array $config = [])
    {
        // Validate required configuration BEFORE merging with defaults
        $pluginName = $config['plugin_name'] ?? null;
        if (!$pluginName || !\is_string($pluginName) || '' === trim($pluginName)) {
            throw new \InvalidArgumentException('plugin_name is required in configuration');
        }

        $this->config = array_merge(self::DEFAULT_CONFIG, $config);

        // Auto-generate constant names if not provided
        if (!$this->config['disable_logging_constant']) {
            $this->config['disable_logging_constant'] = $this->generateConstantName($this->config['plugin_name'], 'DISABLE_LOGGING');
        }

        if (!$this->config['log_retention_constant']) {
            $this->config['log_retention_constant'] = $this->generateConstantName($this->config['plugin_name'], 'LOG_RETENTION_DAYS');
        }
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
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately.
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Critical conditions.
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Normal but significant events.
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Interesting events.
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Detailed debug information.
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed                $level
     * @param array<string, mixed> $context
     *
     * @throws \Psr\Log\InvalidArgumentException
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
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
     * Get debug information for troubleshooting.
     *
     * @return array<string, mixed>
     */
    public function getDebugInfo(): array
    {
        $retentionDays = $this->getLogRetentionDays();

        return [
            'plugin_name' => $this->config['plugin_name'],
            'wonolog_active' => $this->isWonologActive(),
            'wonolog_namespace' => $this->getWonologNamespace(),
            'log_retention_days' => $retentionDays,
            'disable_constant' => $this->config['disable_logging_constant'],
            'retention_constant' => $this->config['log_retention_constant'],
            'wp_debug' => \defined('WP_DEBUG') && WP_DEBUG, // @phpstan-ignore-line
            'logging_disabled' => $this->isLoggingDisabled(),
            'log_directory' => $this->getLogDirectory(),
            'constants_defined' => [
                $this->config['disable_logging_constant'] => \defined($this->config['disable_logging_constant']),
                $this->config['log_retention_constant'] => \defined($this->config['log_retention_constant']),
            ],
        ];
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

        $constantBase = strtoupper($constantBase);

        return $constantBase.'_'.$suffix;
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
     * Fallback logging when Wonolog is not available.
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
        // Note: In test environment WP_DEBUG is typically false, but in real WordPress it can be true
        if (\defined('WP_DEBUG') && true === WP_DEBUG) { // @phpstan-ignore-line
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
     * Check if logging is disabled via WordPress constant.
     */
    private function isLoggingDisabled(): bool
    {
        $constantName = $this->config['disable_logging_constant'];

        return \defined($constantName) && \constant($constantName);
    }

    /**
     * Get log retention days from configuration or WordPress constant.
     */
    private function getLogRetentionDays(): int
    {
        $constantName = $this->config['log_retention_constant'];

        if (\defined($constantName)) {
            $days = \constant($constantName);

            return \is_int($days) && $days > 0 ? $days : $this->config['log_retention_days'];
        }

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
     * Get log directory path.
     */
    private function getLogDirectory(): string
    {
        if (!\function_exists('wp_upload_dir')) {
            return '';
        }

        $uploadDir = wp_upload_dir();

        return $uploadDir['basedir'].'/'.$this->config['plugin_name'].'/logs';
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

        $uploadDir = wp_upload_dir();
        $pluginDir = $uploadDir['basedir'].'/'.$this->config['plugin_name'];
        $logDir = $pluginDir.'/logs';

        // Create directory structure if it doesn't exist
        if (!file_exists($logDir)) {
            wp_mkdir_p($logDir);
            $this->createProtectionFiles($pluginDir, $logDir);
        }

        // Use date-based filename with plugin hash for security
        $logFile = $logDir.'/'.gmdate('Y-m-d').'_'.substr(md5($this->config['plugin_name']), 0, 8).'.dat';

        $timestamp = gmdate('Y-m-d H:i:s');
        $logEntry = \sprintf(
            "[%s] %s: %s\n",
            $timestamp,
            strtoupper($level),
            $this->formatMessage($message)
        );

        if (!empty($context)) {
            if (\function_exists('wp_json_encode')) {
                $logEntry .= 'Context: '.wp_json_encode($context)."\n";
            } else {
                $logEntry .= 'Context: '.json_encode($context)."\n";
            }
        }

        $logEntry .= "---\n";

        // Append to log file with proper file locking
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Simple cleanup: delete files older than retention period (run randomly 1% of the time)
        if (\function_exists('wp_rand') && 1 === wp_rand(1, 100)) {
            $this->cleanupOldLogFiles($logDir);
        }
    }

    /**
     * Traditional error_log (only when WP_DEBUG is enabled).
     *
     * @param array<string, mixed> $context
     */
    private function logToErrorLog(string $level, string|\Stringable $message, array $context): void
    {
        $logEntry = \sprintf(
            '[%s] [%s] %s',
            $this->config['plugin_name'],
            strtoupper($level),
            $this->formatMessage($message)
        );

        if (!empty($context)) {
            if (\function_exists('wp_json_encode')) {
                $logEntry .= ' | Context: '.wp_json_encode($context);
            } else {
                $logEntry .= ' | Context: '.json_encode($context);
            }
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only used in debug mode
        error_log($logEntry);
    }

    /**
     * Format message for consistent logging output.
     */
    private function formatMessage(string|\Stringable $message): string
    {
        if ($message instanceof \Throwable) {
            return \sprintf(
                '%s in %s:%d',
                $message->getMessage(),
                basename($message->getFile()),
                $message->getLine()
            );
        }

        if (\function_exists('is_wp_error') && is_wp_error($message) && \is_object($message) && method_exists($message, 'get_error_message')) {
            return $message->get_error_message();
        }

        if (\is_object($message)) {
            if (\function_exists('wp_json_encode')) {
                return 'Data: '.wp_json_encode($message);
            }

            return 'Data: '.json_encode($message);
        }

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

        // Server configuration guide
        $serverConfig = $this->generateReadme($pluginFolder);
        file_put_contents($logDir.'/README', $serverConfig);
    }

    /**
     * Generate complete server configuration guide.
     */
    private function generateReadme(string $pluginFolder): string
    {
        $retentionDays = $this->getLogRetentionDays();
        $disableConstant = $this->config['disable_logging_constant'];
        $retentionConstant = $this->config['log_retention_constant'];

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
        $config .= "You can control plugin logging behavior via wp-config.php:\n\n";
        $config .= "# Enable debug logging (uses error_log):\n";
        $config .= "define('WP_DEBUG', true);\n\n";
        $config .= "# Disable all plugin logging (production optimization):\n";
        $config .= "define('{$disableConstant}', true);\n\n";
        $config .= "# Customize log retention period (default: {$retentionDays} days):\n";
        $config .= "define('{$retentionConstant}', {$retentionDays});\n\n";

        $config .= 'Generated: '.gmdate('Y-m-d H:i:s')." UTC\n";
        $config .= 'Plugin: '.$this->config['plugin_name']."\n";

        return $config."Log retention: {$retentionDays} days\n";
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
        $files = scandir($logDir);

        if ($files) {
            foreach ($files as $file) {
                if (\in_array(pathinfo($file, PATHINFO_EXTENSION), ['log', 'dat'], true)) {
                    $filePath = $logDir.'/'.$file;
                    if (file_exists($filePath) && filemtime($filePath) < $cutoffTime) {
                        if (\function_exists('wp_delete_file')) {
                            wp_delete_file($filePath);
                        } else {
                            unlink($filePath);
                        }
                    }
                }
            }
        }
    }
}
