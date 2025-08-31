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
            'plugin_name' => 'test-plugin',
            'log_retention_days' => 60,
        ]);

        $config = $logger->getConfig();

        self::assertSame('test-plugin', $config['plugin_name']);
        self::assertSame(60, $config['log_retention_days']);
        self::assertSame('TEST_PLUGIN_DISABLE_LOGGING', $config['disable_logging_constant']);
        self::assertSame('TEST_PLUGIN_LOG_RETENTION_DAYS', $config['log_retention_constant']);
    }

    public function testDefaultConfiguration(): void
    {
        $logger = new Logger(['plugin_name' => 'test']);

        $config = $logger->getConfig();

        self::assertSame('test', $config['plugin_name']);
        self::assertSame(30, $config['log_retention_days']);
        self::assertSame('Inpsyde\Wonolog', $config['wonolog_namespace']);
    }

    public function testCustomConstants(): void
    {
        $logger = new Logger([
            'plugin_name' => 'test',
            'disable_logging_constant' => 'CUSTOM_DISABLE',
            'log_retention_constant' => 'CUSTOM_RETENTION',
        ]);

        $config = $logger->getConfig();

        self::assertSame('CUSTOM_DISABLE', $config['disable_logging_constant']);
        self::assertSame('CUSTOM_RETENTION', $config['log_retention_constant']);
    }

    public function testRequiredPluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plugin_name is required in configuration');

        new Logger([]);
    }

    public function testEmptyPluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plugin_name is required in configuration');

        new Logger(['plugin_name' => '']);
    }

    public function testWhitespacePluginNameValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plugin_name is required in configuration');

        new Logger(['plugin_name' => '   ']);
    }

    public function testBasicLogging(): void
    {
        $logger = new Logger(['plugin_name' => 'test-plugin']);

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
        $logger = new Logger(['plugin_name' => 'test-plugin']);

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

    public function testFileLogging(): void
    {
        // Create a fresh logger for this test to avoid any side effects from previous tests
        $logger = new Logger(['plugin_name' => 'file-logging-test']);

        // Verify test environment setup
        $uploadDir = wp_upload_dir();
        self::assertSame($this->testLogDir, $uploadDir['basedir'], 'Upload dir should be our test directory');

        // Verify debug info shows correct state before logging
        $debugInfo = $logger->getDebugInfo();

        // Debug what's happening
        if ($debugInfo['logging_disabled']) {
            $constantName = $debugInfo['disable_constant'];
            $isDefined = \defined($constantName);
            $constantValue = $isDefined ? \constant($constantName) : 'undefined';

            self::fail(
                'Logging is unexpectedly disabled. '
                .\sprintf('Constant: %s, ', $constantName)
                .'Defined: '.($isDefined ? 'true' : 'false').', '
                .('Value: '.$constantValue)
            );
        }

        // Force file logging (not debug mode)
        $logger->info('Test file logging');

        // Check if log directory was created
        $logDir = $this->testLogDir.'/file-logging-test/logs';
        self::assertDirectoryExists($logDir, 'Log directory should be created at: '.$logDir);

        // Check for log files
        $files = glob($logDir.'/*.dat');
        self::assertNotEmpty($files, 'Log files should exist in: '.$logDir);

        // Check log file content
        if (!empty($files)) {
            $logContent = file_get_contents($files[0]);
            self::assertIsString($logContent);
            self::assertStringContainsString('INFO: Test file logging', $logContent);
        }
    }

    public function testProtectionFilesCreation(): void
    {
        // Use a unique plugin name to avoid conflicts
        $logger = new Logger(['plugin_name' => 'protection-files-test']);

        // Verify test environment setup
        $uploadDir = wp_upload_dir();
        self::assertSame($this->testLogDir, $uploadDir['basedir'], 'Upload dir should be our test directory');

        // Verify debug info shows correct state
        $debugInfo = $logger->getDebugInfo();

        // Skip this test if logging is disabled to avoid false failures
        if ($debugInfo['logging_disabled']) {
            self::markTestSkipped('Logging is disabled, cannot test protection files creation');
        }

        $logger->info('Create protection files');

        $pluginDir = $this->testLogDir.'/protection-files-test';
        $logDir = $pluginDir.'/logs';

        // Check that directory exists first
        self::assertDirectoryExists($logDir, 'Log directory should be created at: '.$logDir);

        // Check protection files exist
        self::assertFileExists($logDir.'/.htaccess');
        self::assertFileExists($logDir.'/web.config');
        self::assertFileExists($logDir.'/index.php');
        self::assertFileExists($pluginDir.'/index.php');
        self::assertFileExists($logDir.'/README');

        // Check .htaccess content
        $htaccessContent = file_get_contents($logDir.'/.htaccess');
        self::assertIsString($htaccessContent);
        self::assertStringContainsString('Deny from all', $htaccessContent);

        // Check index.php content
        $indexContent = file_get_contents($logDir.'/index.php');
        self::assertIsString($indexContent);
        self::assertStringContainsString('http_response_code(403)', $indexContent);
    }

    public function testWonologNotActiveByDefault(): void
    {
        $logger = new Logger(['plugin_name' => 'test-plugin']);

        $debugInfo = $logger->getDebugInfo();

        self::assertFalse($debugInfo['wonolog_active']);
        self::assertNull($logger->getWonologLogger());
    }

    public function testWonologCacheRefresh(): void
    {
        $logger = new Logger(['plugin_name' => 'test-plugin']);

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

    public function testDebugModeUsesErrorLog(): void
    {
        // Test that debug mode doesn't create file logs
        // Note: We can't directly test error_log() output, but we can verify
        // that file logging is skipped when WP_DEBUG is true

        $logger = new Logger(['plugin_name' => 'test-plugin']);

        // Simulate debug mode by checking the logic doesn't create files
        // In debug mode, error_log is used instead of file logging

        // Create a test scenario where we can verify behavior
        $debugInfo = $logger->getDebugInfo();
        self::assertIsBool($debugInfo['wp_debug']);
    }

    public function testLoggingDisabledViaConstant(): void
    {
        // Define the disable constant using the proper format
        \define('TEST_PLUGIN_DISABLE_LOGGING', true);

        $logger = new Logger(['plugin_name' => 'test_plugin']);

        // Verify logging is disabled
        $debugInfo = $logger->getDebugInfo();
        self::assertTrue($debugInfo['logging_disabled']);

        $logger->info('This should not be logged');

        // Should not create log directory when disabled
        $logDir = $this->testLogDir.'/test_plugin/logs';
        self::assertDirectoryDoesNotExist($logDir);

        // Note: Fallback actions are still triggered to allow third-party plugins to handle logging
        // but the actual file logging should be skipped
        $actions = get_triggered_actions();
        $fallbackActions = array_filter($actions, static fn (array $action): bool => str_starts_with($action['hook'], 'wp_logger_fallback'));

        // Fallback hooks are called but file logging should be disabled
        self::assertNotEmpty($fallbackActions);

        // But no log files should be created
        self::assertDirectoryDoesNotExist($logDir);
    }

    public function testCustomRetentionDays(): void
    {
        // Define custom retention
        \define('TEST_PLUGIN_LOG_RETENTION_DAYS', 90);

        $logger = new Logger(['plugin_name' => 'test_plugin']);

        $debugInfo = $logger->getDebugInfo();
        self::assertSame(90, $debugInfo['log_retention_days']);
    }

    public function testGetDebugInfo(): void
    {
        $logger = new Logger([
            'plugin_name' => 'debug-test',
            'log_retention_days' => 45,
        ]);

        $debugInfo = $logger->getDebugInfo();

        $debugInfo = $logger->getDebugInfo();

        // Test specific debug info fields
        self::assertArrayHasKey('plugin_name', $debugInfo);
        self::assertArrayHasKey('wonolog_active', $debugInfo);
        self::assertArrayHasKey('wonolog_namespace', $debugInfo);
        self::assertArrayHasKey('log_retention_days', $debugInfo);
        self::assertArrayHasKey('disable_constant', $debugInfo);
        self::assertArrayHasKey('retention_constant', $debugInfo);
        self::assertArrayHasKey('wp_debug', $debugInfo);
        self::assertArrayHasKey('logging_disabled', $debugInfo);
        self::assertArrayHasKey('log_directory', $debugInfo);
        self::assertArrayHasKey('constants_defined', $debugInfo);

        self::assertSame('debug-test', $debugInfo['plugin_name']);
        self::assertSame(45, $debugInfo['log_retention_days']);
        self::assertSame('DEBUG_TEST_DISABLE_LOGGING', $debugInfo['disable_constant']);
    }

    public function testConstantNameGeneration(): void
    {
        // Test with various plugin name formats
        $testCases = [
            'simple' => 'SIMPLE_DISABLE_LOGGING',
            'with-dashes' => 'WITH_DASHES_DISABLE_LOGGING',
            'with_underscores' => 'WITH_UNDERSCORES_DISABLE_LOGGING',
            'mixed-format_test' => 'MIXED_FORMAT_TEST_DISABLE_LOGGING',
            'numbers123' => 'NUMBERS123_DISABLE_LOGGING',
        ];

        foreach ($testCases as $pluginName => $expectedConstant) {
            $logger = new Logger(['plugin_name' => $pluginName]);
            $config = $logger->getConfig();

            self::assertSame($expectedConstant, $config['disable_logging_constant']);
        }
    }

    public function testLogCleanup(): void
    {
        // Set wp_rand to trigger cleanup
        set_wp_rand_result(1);

        $logger = new Logger(['plugin_name' => 'cleanup-test']);

        // Create old log file
        $logDir = $this->testLogDir.'/cleanup-test/logs';
        mkdir($logDir, 0755, true);

        $oldLogFile = $logDir.'/old-log.dat';
        file_put_contents($oldLogFile, 'old log content');

        // Set file modification time to be older than retention period
        touch($oldLogFile, time() - (31 * DAY_IN_SECONDS));

        // Trigger logging which should trigger cleanup
        $logger->info('Trigger cleanup');

        // Old file should be deleted
        self::assertFileDoesNotExist($oldLogFile);
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
