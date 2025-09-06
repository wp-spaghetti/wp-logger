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

namespace WpSpaghetti\WpLogger\Tests;

use PHPUnit\Framework\TestCase;
use WpSpaghetti\WpLogger\Logger;

/**
 * @internal
 *
 * @coversNothing
 */
final class LoggerTest extends TestCase
{
    private string $testLogDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test state
        reset_wp_logger_test_globals();

        // Create temporary log directory for testing
        $this->testLogDir = sys_get_temp_dir().'/wp-logger-test-'.uniqid();
        mkdir($this->testLogDir, 0755, true);

        // Override wp_upload_dir to use our test directory
        global $wp_upload_dir_result;
        $wp_upload_dir_result['basedir'] = $this->testLogDir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test directory
        if (is_dir($this->testLogDir)) {
            $this->removeDirectory($this->testLogDir);
        }

        // Reset test globals
        reset_wp_logger_test_globals();
    }

    public function testBasicConfiguration(): void
    {
        $logger = new Logger([
            'component_name' => 'test-plugin',
            'log_retention_days' => 60,
        ]);

        $config = $logger->getConfig();

        self::assertSame('test-plugin', $config['component_name']);
        self::assertSame(60, $config['log_retention_days']);
        self::assertSame('TEST_PLUGIN_DISABLE_LOGGING', $config['disable_logging_constant']);
        self::assertSame('TEST_PLUGIN_LOG_RETENTION_DAYS', $config['log_retention_constant']);
    }

    public function testEnvironmentBasedConfiguration(): void
    {
        // Set environment variables
        set_mock_env_var('LOGGER_COMPONENT_NAME', 'env-plugin');
        set_mock_env_var('LOGGER_RETENTION_DAYS', '45');
        set_mock_env_var('LOGGER_MIN_LEVEL', 'warning');
        set_mock_env_var('LOGGER_WONOLOG_NAMESPACE', 'Custom\Logger');

        $logger = new Logger();

        $config = $logger->getConfig();

        self::assertSame('env-plugin', $config['component_name']);
        self::assertSame(45, $config['log_retention_days']);
        self::assertSame('warning', $config['min_log_level']);
        self::assertSame('Custom\Logger', $config['wonolog_namespace']);
    }

    public function testPluginSpecificEnvironmentVariables(): void
    {
        // Set component-specific environment variables (should override global ones)
        set_mock_env_var('LOGGER_RETENTION_DAYS', '30'); // Global
        set_mock_env_var('MY_PLUGIN_LOG_RETENTION_DAYS', '90'); // Plugin-specific

        $logger = new Logger([
            'component_name' => 'my-plugin',
        ]);

        $config = $logger->getConfig();

        // Should use component-specific value
        self::assertSame(90, $config['log_retention_days']);
    }

    public function testConfigurationPriority(): void
    {
        // Set multiple sources of configuration
        set_mock_env_var('LOGGER_RETENTION_DAYS', '30'); // Environment
        \define('TEST_PLUGIN_LOG_RETENTION_DAYS', 60); // WordPress constant

        $logger = new Logger([
            'component_name' => 'test-plugin',
            'log_retention_days' => 90, // Config array
        ]);

        // Environment should override config array
        $debugInfo = $logger->getDebugInfo();
        self::assertSame(30, $debugInfo['log_retention_days']);
    }

    public function testDefaultConfiguration(): void
    {
        $logger = new Logger(['component_name' => 'test']);

        $config = $logger->getConfig();

        self::assertSame('test', $config['component_name']);
        self::assertSame(30, $config['log_retention_days']); // Default value
        self::assertSame('Inpsyde\Wonolog', $config['wonolog_namespace']);
        self::assertSame('debug', $config['min_log_level']);
    }

    public function testRequiredPluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('component_name is required in configuration or LOGGER_COMPONENT_NAME environment variable');

        new Logger([]);
    }

    public function testPluginNameFromEnvironment(): void
    {
        set_mock_env_var('LOGGER_COMPONENT_NAME', 'env-test-plugin');

        $logger = new Logger();

        $config = $logger->getConfig();
        self::assertSame('env-test-plugin', $config['component_name']);
    }

    public function testEmptyPluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('component_name is required in configuration or LOGGER_COMPONENT_NAME environment variable');

        new Logger(['component_name' => '']);
    }

    public function testWhitespacePluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('component_name is required in configuration or LOGGER_COMPONENT_NAME environment variable');

        new Logger(['component_name' => '   ']);
    }

    public function testMinimumLogLevelFiltering(): void
    {
        // Set minimum level to warning
        $logger = new Logger([
            'component_name' => 'test-plugin',
            'min_log_level' => 'warning',
        ]);

        $logger->debug('This should be filtered out');
        $logger->info('This should be filtered out');
        $logger->warning('This should be logged');
        $logger->error('This should be logged');

        $actions = get_triggered_actions();

        // Should only have logged actions for warning and error
        $loggedActions = array_filter($actions, static fn (array $action): bool => 'wp_logger_logged' === $action['hook']);

        self::assertCount(2, $loggedActions);
    }

    public function testLoggingBehaviorWithAndWithoutWonolog(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        // Without Wonolog (fallback mode)
        $logger->info('Test message without Wonolog');

        $actions = get_triggered_actions();

        // Should have fallback actions since Wonolog is not active
        $fallbackActions = array_filter($actions, static fn (array $action): bool => str_starts_with($action['hook'], 'wp_logger_fallback'));

        // Should have both general fallback and specific level fallback
        self::assertCount(2, $fallbackActions);
    }

    public function testBasicLogging(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        // Test all PSR-3 log levels
        $logger->emergency('Emergency message');
        $logger->alert('Alert message');
        $logger->critical('Critical message');
        $logger->error('Error message');
        $logger->warning('Warning message');
        $logger->notice('Notice message');
        $logger->info('Info message');
        $logger->debug('Debug message');

        // Check if fallback actions were triggered
        $actions = get_triggered_actions();

        self::assertNotEmpty($actions);

        // Should have fallback actions since Wonolog is not active
        $fallbackActions = array_filter($actions, static fn (array $action): bool => str_starts_with($action['hook'], 'wp_logger_fallback'));

        self::assertCount(16, $fallbackActions); // 8 levels × 2 actions each
    }

    public function testLoggingWithContext(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        $context = ['user_id' => 123, 'action' => 'login'];
        $logger->info('User logged in', $context);

        $actions = get_triggered_actions();
        self::assertNotEmpty($actions);

        // Find the logged action
        $loggedAction = null;
        foreach ($actions as $action) {
            if ('wp_logger_logged' === $action['hook']) {
                $loggedAction = $action;

                break;
            }
        }

        self::assertNotNull($loggedAction);
        self::assertSame('info', $loggedAction['args'][0]);
        self::assertSame($context, $loggedAction['args'][2]);
    }

    public function testFileLoggingWithEnvironmentInfo(): void
    {
        // Set environment for testing
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'development');

        $logger = new Logger(['component_name' => 'file-logging-test']);

        // Verify test environment setup
        $uploadDir = wp_upload_dir();
        self::assertSame($this->testLogDir, $uploadDir['basedir'], 'Upload dir should be our test directory');

        // Force file logging (simulate production-like environment)
        set_mock_env_var('WP_DEBUG', 'false');
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');

        $logger->info('Test file logging with environment');

        // Check if log directory was created
        $logDir = $this->testLogDir.'/file-logging-test/logs';
        self::assertDirectoryExists($logDir, 'Log directory should be created at: '.$logDir);

        // Check for log files
        $files = glob($logDir.'/*.dat');
        self::assertNotEmpty($files, 'Log files should exist in: '.$logDir);

        // Check log file content includes environment info
        if (!empty($files)) {
            $logContent = file_get_contents($files[0]);
            self::assertIsString($logContent);
            self::assertStringContainsString('INFO: Test file logging with environment', $logContent);
        }
    }

    public function testLoggingDisabledViaEnvironment(): void
    {
        // Disable logging via environment variable
        set_mock_env_var('LOGGER_DISABLED', 'true');

        $logger = new Logger(['component_name' => 'test-plugin']);

        // Verify logging is disabled
        $debugInfo = $logger->getDebugInfo();
        self::assertTrue($debugInfo['logging_disabled']);

        $logger->info('This should not be logged');

        // Should not create log directory when disabled
        $logDir = $this->testLogDir.'/test-plugin/logs';
        self::assertDirectoryDoesNotExist($logDir);
    }

    public function testPluginSpecificLoggingDisabled(): void
    {
        // Disable logging for specific component
        set_mock_env_var('TEST_PLUGIN_LOGGER_DISABLED', 'true');

        $logger = new Logger(['component_name' => 'test-plugin']);

        // Verify logging is disabled
        $debugInfo = $logger->getDebugInfo();
        self::assertTrue($debugInfo['logging_disabled']);
    }

    public function testEnhancedDebugInfo(): void
    {
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'staging');
        set_mock_env_var('LOGGER_MIN_LEVEL', 'warning');

        $logger = new Logger([
            'component_name' => 'debug-test',
            'log_retention_days' => 45,
        ]);

        $debugInfo = $logger->getDebugInfo();

        // Test basic configuration
        self::assertArrayHasKey('component_name', $debugInfo);
        self::assertArrayHasKey('min_log_level', $debugInfo);
        self::assertArrayHasKey('log_retention_days', $debugInfo);

        // Test environment information
        self::assertArrayHasKey('environment_type', $debugInfo);
        self::assertArrayHasKey('is_debug', $debugInfo);
        self::assertArrayHasKey('is_development', $debugInfo);
        self::assertArrayHasKey('is_staging', $debugInfo);
        self::assertArrayHasKey('is_production', $debugInfo);
        self::assertArrayHasKey('is_container', $debugInfo);
        self::assertArrayHasKey('server_software', $debugInfo);

        // Test WordPress integration
        self::assertArrayHasKey('wp_debug', $debugInfo);
        self::assertArrayHasKey('wp_multisite', $debugInfo);

        // Test values
        self::assertSame('debug-test', $debugInfo['component_name']);
        self::assertSame('warning', $debugInfo['min_log_level']);
        self::assertSame('staging', $debugInfo['environment_type']);
        self::assertTrue($debugInfo['is_staging']);
        self::assertFalse($debugInfo['is_production']);
    }

    public function testProtectionFilesWithEnvironmentInfo(): void
    {
        // Set environment to production to force file logging (not error_log)
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');
        set_mock_env_var('WP_DEBUG', 'false');

        $logger = new Logger(['component_name' => 'protection-files-test']);

        $logger->info('Create protection files');

        $componentDir = $this->testLogDir.'/protection-files-test';
        $logDir = $componentDir.'/logs';

        // Check that directory exists first
        self::assertDirectoryExists($logDir, 'Log directory should be created');

        // Check protection files exist
        self::assertFileExists($logDir.'/.htaccess');
        self::assertFileExists($logDir.'/web.config');
        self::assertFileExists($logDir.'/index.php');
        self::assertFileExists($componentDir.'/index.php');
        self::assertFileExists($logDir.'/README');

        // Check README includes environment information
        $readmeContent = file_get_contents($logDir.'/README');
        self::assertIsString($readmeContent);
        self::assertStringContainsString('Environment Variables', $readmeContent);
        self::assertStringContainsString('LOGGER_DISABLED', $readmeContent);
        self::assertStringContainsString('PROTECTION_FILES_TEST_DISABLED', $readmeContent);
        self::assertStringContainsString('Environment: production', $readmeContent);
    }

    public function testWonologNotActiveByDefault(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        $debugInfo = $logger->getDebugInfo();

        self::assertFalse($debugInfo['wonolog_active']);
        self::assertNull($logger->getWonologLogger());
    }

    public function testWonologCacheRefresh(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        // Initial state
        self::assertFalse($logger->getDebugInfo()['wonolog_active']);

        // Simulate Wonolog becoming active
        set_did_action_result('Inpsyde\Wonolog\Configurator::ACTION_SETUP', 1);

        // Should still be cached as false
        self::assertFalse($logger->getDebugInfo()['wonolog_active']);

        // After cache refresh, should check again
        $logger->refreshWonologCache();

        // Note: In real test, this would be true if Wonolog class exists
        // But in our test environment, class doesn't exist so still false
        self::assertFalse($logger->getDebugInfo()['wonolog_active']);
    }

    public function testCustomRetentionDays(): void
    {
        // Define custom retention via environment
        set_mock_env_var('TEST_PLUGIN_LOG_RETENTION_DAYS', '90');

        $logger = new Logger(['component_name' => 'test_plugin']);

        $debugInfo = $logger->getDebugInfo();
        self::assertSame(90, $debugInfo['log_retention_days']);
    }

    public function testConstantNameGeneration(): void
    {
        // Test with various component name formats
        $testCases = [
            'simple' => 'SIMPLE_DISABLE_LOGGING',
            'with-dashes' => 'WITH_DASHES_DISABLE_LOGGING',
            'with_underscores' => 'WITH_UNDERSCORES_DISABLE_LOGGING',
            'mixed-format_test' => 'MIXED_FORMAT_TEST_DISABLE_LOGGING',
            'numbers123' => 'NUMBERS123_DISABLE_LOGGING',
        ];

        foreach ($testCases as $componentName => $expectedConstant) {
            $logger = new Logger(['component_name' => $componentName]);
            $config = $logger->getConfig();

            self::assertSame($expectedConstant, $config['disable_logging_constant']);
        }
    }

    public function testLogCleanupWithEnvironmentRetention(): void
    {
        // Set environment retention
        set_mock_env_var('CLEANUP_TEST_LOG_RETENTION_DAYS', '1'); // 1 day retention
        set_wp_rand_result(1); // Force cleanup to run

        $logger = new Logger(['component_name' => 'cleanup-test']);

        // Create old log file
        $logDir = $this->testLogDir.'/cleanup-test/logs';
        mkdir($logDir, 0755, true);

        $oldLogFile = $logDir.'/old-log.dat';
        file_put_contents($oldLogFile, 'old log content');

        // Set file modification time to be older than 1 day retention period
        touch($oldLogFile, time() - (2 * DAY_IN_SECONDS)); // 2 days old

        // Trigger logging which should trigger cleanup
        $logger->info('Trigger cleanup');

        // Old file should be deleted due to environment-based retention
        self::assertFileDoesNotExist($oldLogFile);
    }

    public function testFallbackLoggingBehaviorInDifferentEnvironments(): void
    {
        // Test debug environment (should use error_log)
        set_mock_env_var('WP_DEBUG', 'true');

        $debugLogger = new Logger(['component_name' => 'debug-test']);

        // Verify it's in debug mode
        $debugInfo = $debugLogger->getDebugInfo();
        self::assertTrue($debugInfo['is_debug']);

        // Test production environment (should use file logging)
        set_mock_env_var('WP_DEBUG', 'false');
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');

        $prodLogger = new Logger(['component_name' => 'prod-test']);

        $debugInfo = $prodLogger->getDebugInfo();
        self::assertTrue($debugInfo['is_production']);
        self::assertFalse($debugInfo['is_debug']);
    }

    /**
     * Recursively remove directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
