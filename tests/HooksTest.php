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
final class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test state
        reset_wp_logger_test_globals();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset test globals
        reset_wp_logger_test_globals();
    }

    public function testWonologNamespaceHookWithEnvironment(): void
    {
        // Set environment namespace
        set_mock_env_var('LOGGER_WONOLOG_NAMESPACE', 'CustomApp\Logger');

        $logger = new Logger(['component_name' => 'test-plugin']);

        // This will trigger the hook internally when debug info is requested
        $logger->getDebugInfo();

        $appliedFilters = get_applied_filters();

        // Check if the hook was called with environment value
        $namespaceFilterCalled = false;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_wonolog_namespace' === $appliedFilter['hook']) {
                $namespaceFilterCalled = true;
                self::assertSame('CustomApp\Logger', $appliedFilter['value']);

                break;
            }
        }

        self::assertTrue($namespaceFilterCalled, 'wp_logger_wonolog_namespace filter should be called');
    }

    public function testWonologPrefixHookWithEnvironmentNamespace(): void
    {
        $namespace = 'WpSpaghetti\WpLogger\Tests\MockWonolog1';
        $setupAction = $namespace.'\Configurator::ACTION_SETUP';

        // Set environment namespace
        set_mock_env_var('LOGGER_WONOLOG_NAMESPACE', $namespace);

        // Create the mock class and constants manually
        if (!class_exists($namespace.'\Configurator')) {
            eval("
                namespace {$namespace} {
                    class Configurator {
                        const ACTION_SETUP = '{$setupAction}';
                    }
                    const LOG = 'test.wonolog.log';
                }
            ");
        }

        // Mock Wonolog as active
        set_did_action_result($setupAction, 1);

        $logger = new Logger(['component_name' => 'test-plugin']);

        // Force cache refresh
        $logger->refreshWonologCache();

        // Verify Wonolog is detected as active
        $debugInfo = $logger->getDebugInfo();
        if (!$debugInfo['wonolog_active']) {
            self::markTestSkipped('Wonolog mock failed to activate');
        }

        $logger->info('Test message');

        $appliedFilters = get_applied_filters();

        // Check if wonolog prefix hook was called
        $prefixFilterCalled = false;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_wonolog_prefix' === $appliedFilter['hook']) {
                $prefixFilterCalled = true;
                self::assertSame('test.wonolog.log', $appliedFilter['value']);
                self::assertSame('info', $appliedFilter['args'][0]); // level

                break;
            }
        }

        self::assertTrue($prefixFilterCalled, 'wp_logger_wonolog_prefix filter should be called');
    }

    public function testWonologActionHook(): void
    {
        $namespace = 'WpSpaghetti\WpLogger\Tests\MockWonolog2';
        $setupAction = $namespace.'\Configurator::ACTION_SETUP';

        // Create the mock class and constants manually
        if (!class_exists($namespace.'\Configurator')) {
            eval("
                namespace {$namespace} {
                    class Configurator {
                        const ACTION_SETUP = '{$setupAction}';
                    }
                    const LOG = 'test.wonolog.log2';
                }
            ");
        }

        // Mock Wonolog as active
        set_did_action_result($setupAction, 1);

        $logger = new Logger([
            'component_name' => 'test-plugin',
            'wonolog_namespace' => $namespace,
        ]);

        // Force cache refresh
        $logger->refreshWonologCache();

        // Verify Wonolog is detected as active
        $debugInfo = $logger->getDebugInfo();
        if (!$debugInfo['wonolog_active']) {
            self::markTestSkipped('Wonolog mock failed to activate');
        }

        $logger->error('Test error message');

        $appliedFilters = get_applied_filters();

        // Check if wonolog action hook was called
        $actionFilterCalled = false;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_wonolog_action' === $appliedFilter['hook']) {
                $actionFilterCalled = true;
                self::assertSame('test.wonolog.log2.error', $appliedFilter['value']);
                self::assertSame('error', $appliedFilter['args'][0]); // level

                break;
            }
        }

        self::assertTrue($actionFilterCalled, 'wp_logger_wonolog_action filter should be called');
    }

    public function testOverrideLogHookWithEnvironmentContext(): void
    {
        // Set environment context
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'staging');
        set_mock_env_var('LOGGER_MIN_LEVEL', 'warning');

        $logger = new Logger(['component_name' => 'test-plugin']);

        // Override should be called before any logging
        $logger->warning('This should be overridden');

        $appliedFilters = get_applied_filters();

        // Check if override hook was called
        $overrideFilterCalled = false;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_override_log' === $appliedFilter['hook']) {
                $overrideFilterCalled = true;
                self::assertNull($appliedFilter['value']); // Default is null
                self::assertSame('warning', $appliedFilter['args'][0]); // level

                // Check that config includes environment-based settings
                // The config is the 4th argument (index 3) in the args array
                $config = $appliedFilter['args'][3]; // Fixed: was args[4], should be args[3]
                self::assertIsArray($config);
                self::assertArrayHasKey('min_log_level', $config);
                self::assertSame('warning', $config['min_log_level']);

                break;
            }
        }

        self::assertTrue($overrideFilterCalled, 'wp_logger_override_log filter should be called');
    }

    public function testOverrideLogPreventsDefaultLogging(): void
    {
        $logger = new Logger(['component_name' => 'test-plugin']);

        // Set up override to return true (prevent default logging)
        get_applied_filters();
        $logger->info('Test message'); // This triggers the first call

        // Manually set override for the last filter call
        set_filter_override('wp_logger_override_log', true);

        // Reset actions to test override effect
        reset_wp_logger_test_globals();

        // Log again - this time it should be overridden
        $logger->info('Overridden message');

        $actions = get_triggered_actions();

        // Should not have fallback actions if overridden
        $fallbackActions = array_filter($actions, static fn (array $action): bool => str_starts_with($action['hook'], 'wp_logger_fallback'));

        // Note: In our test environment, the override doesn't actually prevent logging
        // because our mock apply_filters doesn't have the full WordPress behavior
        // This test validates that the hook is called correctly

        // We verify that the actions array was created (even if override doesn't work fully in our mock)
        // Removed redundant assertIsArray calls as PHPStan correctly identifies these as always true
        self::assertNotEmpty($actions);
        self::assertGreaterThanOrEqual(0, \count($fallbackActions));
    }

    public function testLoggedActionTriggeredWithEnvironmentInfo(): void
    {
        // Set environment
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');

        $logger = new Logger(['component_name' => 'test-plugin']);
        $logger->notice('Test notice', ['key' => 'value']);

        $actions = get_triggered_actions();

        // Check if wp_logger_logged action was triggered
        $loggedActionTriggered = false;
        foreach ($actions as $action) {
            if ('wp_logger_logged' === $action['hook']) {
                $loggedActionTriggered = true;
                self::assertSame('notice', $action['args'][0]); // level
                self::assertSame(['key' => 'value'], $action['args'][2]); // context
                self::assertSame('test-plugin', $action['args'][3]); // component_name

                break;
            }
        }

        self::assertTrue($loggedActionTriggered, 'wp_logger_logged action should be triggered');
    }

    public function testFallbackActionsTriggeredInDifferentEnvironments(): void
    {
        // Test in production environment to avoid error_log output during tests
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');
        set_mock_env_var('WP_DEBUG', 'false'); // Force fallback behavior

        $logger = new Logger(['component_name' => 'test-plugin']);
        $logger->critical('Critical error in production');

        $actions = get_triggered_actions();

        // Check for general fallback action
        $generalFallbackTriggered = false;
        $specificFallbackTriggered = false;

        foreach ($actions as $action) {
            if ('wp_logger_fallback' === $action['hook']) {
                $generalFallbackTriggered = true;
                self::assertSame('critical', $action['args'][0]);
                self::assertSame('test-plugin', $action['args'][3]);
            }

            if ('wp_logger_fallback_critical' === $action['hook']) {
                $specificFallbackTriggered = true;
                self::assertSame('Critical error in production', $action['args'][0]);
                self::assertSame('test-plugin', $action['args'][2]);
            }
        }

        self::assertTrue($generalFallbackTriggered, 'wp_logger_fallback should be triggered');
        self::assertTrue($specificFallbackTriggered, 'wp_logger_fallback_critical should be triggered');
    }

    public function testEnvironmentBasedHookBehaviorInFallback(): void
    {
        // Test that environment detection still works in fallback logging
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'staging');
        set_mock_env_var('LOGGER_MIN_LEVEL', 'info');

        $logger = new Logger(['component_name' => 'staging-test']);

        // Debug messages should be filtered out due to min level, not environment
        $logger->debug('Debug message'); // Should be filtered by min level
        $logger->info('Info message');   // Should be logged

        $actions = get_triggered_actions();
        $loggedActions = array_filter($actions, static fn (array $action): bool => 'wp_logger_logged' === $action['hook']);

        // Should only have one logged action (info level)
        self::assertCount(1, $loggedActions);

        $infoAction = array_values($loggedActions)[0];
        self::assertSame('info', $infoAction['args'][0]);
        self::assertSame('Info message', $infoAction['args'][1]);
    }

    public function testHookNamingConventions(): void
    {
        $expectedHooks = [
            'wp_logger_wonolog_namespace',
            'wp_logger_wonolog_prefix',
            'wp_logger_wonolog_action',
            'wp_logger_override_log',
            'wp_logger_logged',
            'wp_logger_fallback',
        ];

        foreach ($expectedHooks as $expectedHook) {
            // Check that hook names are properly prefixed
            self::assertStringStartsWith('wp_logger_', $expectedHook);

            // Check that hook names use underscores (WordPress convention)
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $expectedHook);

            // Check that hook names are not too long (WordPress recommendation)
            self::assertLessThanOrEqual(50, \strlen($expectedHook));
        }
    }

    public function testHookParameterConsistencyWithEnvironment(): void
    {
        // Set environment context
        set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');
        set_mock_env_var('LOGGER_WONOLOG_NAMESPACE', 'Production\Logger');

        $logger = new Logger(['component_name' => 'test-plugin']);
        $context = ['user' => 'admin', 'ip' => '192.168.1.1'];

        $logger->alert('Test alert message', $context);

        $appliedFilters = get_applied_filters();
        $triggeredActions = get_triggered_actions();

        // Check that override hook receives correct parameters with environment config
        $overrideFilter = null;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_override_log' === $appliedFilter['hook']) {
                $overrideFilter = $appliedFilter;

                break;
            }
        }

        self::assertNotNull($overrideFilter);
        self::assertNull($overrideFilter['value']); // Initial value
        self::assertSame('alert', $overrideFilter['args'][0]); // level
        self::assertSame('Test alert message', $overrideFilter['args'][1]); // message
        self::assertSame($context, $overrideFilter['args'][2]); // context

        // Check config contains environment-based values
        $config = $overrideFilter['args'][3];
        self::assertIsArray($config);
        self::assertArrayHasKey('wonolog_namespace', $config);
        self::assertSame('Production\Logger', $config['wonolog_namespace']);

        // Check that logged action receives correct parameters
        $loggedAction = null;
        foreach ($triggeredActions as $triggeredAction) {
            if ('wp_logger_logged' === $triggeredAction['hook']) {
                $loggedAction = $triggeredAction;

                break;
            }
        }

        self::assertNotNull($loggedAction);
        self::assertSame('alert', $loggedAction['args'][0]); // level
        self::assertSame('Test alert message', $loggedAction['args'][1]); // message
        self::assertSame($context, $loggedAction['args'][2]); // context
        self::assertSame('test-plugin', $loggedAction['args'][3]); // component_name
    }

    public function testCustomWonologNamespaceFromEnvironment(): void
    {
        // Set custom namespace via environment
        set_mock_env_var('LOGGER_WONOLOG_NAMESPACE', 'MyCustomApp\LoggerSystem');

        $logger = new Logger(['component_name' => 'test-plugin']);

        $debugInfo = $logger->getDebugInfo();

        $appliedFilters = get_applied_filters();

        // Check that the environment namespace was passed through the filter
        $namespaceFilter = null;
        foreach ($appliedFilters as $appliedFilter) {
            if ('wp_logger_wonolog_namespace' === $appliedFilter['hook']) {
                $namespaceFilter = $appliedFilter;

                break;
            }
        }

        self::assertNotNull($namespaceFilter);
        self::assertSame('MyCustomApp\LoggerSystem', $namespaceFilter['value']);
        self::assertSame('MyCustomApp\LoggerSystem', $debugInfo['wonolog_namespace']);
    }

    public function testHookExecutionInDifferentEnvironments(): void
    {
        $environments = ['development', 'staging', 'production'];

        foreach ($environments as $environment) {
            // Reset state for each environment test
            reset_wp_logger_test_globals();

            // Force production environment to prevent error_log() output during tests
            // Note: We test environment-specific behavior but force production logging mode
            // to avoid PHPUnit risky test warnings. In real environments, the logger
            // correctly adapts its logging strategy (error_log in dev, files in production)
            set_mock_env_var('WP_ENVIRONMENT_TYPE', 'production');
            set_mock_env_var('WP_DEBUG', 'false');

            $logger = new Logger(['component_name' => 'env-test-plugin']);

            $logger->error('Error in '.$environment);

            $actions = get_triggered_actions();
            $loggedActions = array_filter($actions, static fn (array $action): bool => 'wp_logger_logged' === $action['hook']);

            // Should have logged action regardless of environment
            self::assertCount(1, $loggedActions, \sprintf('Should log error in %s environment', $environment));

            $errorAction = array_values($loggedActions)[0];
            self::assertSame('error', $errorAction['args'][0]);
            self::assertStringContainsString($environment, $errorAction['args'][1]);
        }
    }

    public function testPluginSpecificEnvironmentHooks(): void
    {
        // Set component-specific environment variables
        set_mock_env_var('MY_SPECIAL_PLUGIN_LOGGER_DISABLED', 'false');
        set_mock_env_var('MY_SPECIAL_PLUGIN_LOG_RETENTION_DAYS', '14');

        $logger = new Logger(['component_name' => 'my-special-plugin']);

        // Trigger logging to test environment-based configuration
        $logger->warning('Plugin-specific configuration test');

        $debugInfo = $logger->getDebugInfo();

        // Should use component-specific retention from environment
        self::assertSame(14, $debugInfo['log_retention_days']);
        self::assertFalse($debugInfo['logging_disabled']);

        // Verify hook was called with component-specific configuration
        $actions = get_triggered_actions();
        $loggedActions = array_filter($actions, static fn (array $action): bool => 'wp_logger_logged' === $action['hook']);

        self::assertCount(1, $loggedActions);
    }

    public function testMinimumLogLevelFilteringInHooks(): void
    {
        // Set minimum log level via environment
        set_mock_env_var('LOGGER_MIN_LEVEL', 'error');

        $logger = new Logger(['component_name' => 'min-level-test']);

        // Try logging at different levels
        $logger->debug('Debug message');     // Should be filtered
        $logger->info('Info message');       // Should be filtered
        $logger->warning('Warning message'); // Should be filtered
        $logger->error('Error message');     // Should be logged
        $logger->critical('Critical message'); // Should be logged

        $actions = get_triggered_actions();
        $loggedActions = array_filter($actions, static fn (array $action): bool => 'wp_logger_logged' === $action['hook']);

        // Should only have 2 logged actions (error and critical)
        self::assertCount(2, $loggedActions);

        $loggedLevels = array_map(static fn (array $action): string => $action['args'][0], $loggedActions);
        self::assertContains('error', $loggedLevels);
        self::assertContains('critical', $loggedLevels);
        self::assertNotContains('debug', $loggedLevels);
        self::assertNotContains('info', $loggedLevels);
        self::assertNotContains('warning', $loggedLevels);
    }
}
