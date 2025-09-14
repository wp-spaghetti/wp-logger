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

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('WP_ENV')) {
    define('WP_ENV', 'testing');
}

if (!defined('WP_ENVIRONMENT_TYPE')) {
    define('WP_ENVIRONMENT_TYPE', 'testing');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Autoload Composer dependencies
require_once dirname(__DIR__).'/vendor/autoload.php';

// Global test variables for mocking WordPress functions
// @var array<string, mixed>
global $wp_upload_dir_result;
// @var int
global $wp_rand_result;
// @var array<int, array<string, mixed>>
global $applied_filters;
// @var array<int, array<string, mixed>>
global $triggered_actions;
// @var array<string, int>
global $did_action_calls;
// @var array<string, mixed>
global $mock_environment_vars;
// @var array<string, mixed>
global $mock_constants;

$wp_upload_dir_result = [
    'path' => '/tmp/wp-content/uploads',
    'url' => 'https://example.com/wp-content/uploads',
    'subdir' => '',
    'basedir' => '/tmp/wp-content/uploads',
    'baseurl' => 'https://example.com/wp-content/uploads',
    'error' => false,
];

$wp_rand_result = 50; // Default wp_rand result
$applied_filters = [];
$triggered_actions = [];
$did_action_calls = [];
$mock_environment_vars = [];
$mock_constants = [];

// Mock WordPress functions for testing

if (!function_exists('wp_upload_dir')) {
    /**
     * @return array<string, mixed>
     */
    function wp_upload_dir(): array
    {
        global $wp_upload_dir_result;

        return $wp_upload_dir_result;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        if (!is_dir($target)) {
            return mkdir($target, 0755, true);
        }

        return true;
    }
}

if (!function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        global $wp_rand_result;

        return $wp_rand_result;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     */
    function wp_json_encode($data, int $options = 0, int $depth = 512): false|string
    {
        return json_encode($data, $options, max(1, $depth));
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): bool
    {
        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    function apply_filters(string $hook_name, $value, ...$args)
    {
        global $applied_filters;

        $applied_filters[] = [
            'hook' => $hook_name,
            'value' => $value,
            'args' => $args,
        ];

        // Return modified value for specific test scenarios
        return match ($hook_name) {
            'wp_logger_wonolog_namespace' => $applied_filters[count($applied_filters) - 1]['test_override'] ?? $value,
            'wp_logger_wonolog_prefix' => $applied_filters[count($applied_filters) - 1]['test_override'] ?? $value,
            'wp_logger_wonolog_action' => $applied_filters[count($applied_filters) - 1]['test_override'] ?? $value,
            'wp_logger_override_log' => $applied_filters[count($applied_filters) - 1]['test_override'] ?? $value,
            default => $value,
        };
    }
}

if (!function_exists('do_action')) {
    /**
     * @param mixed ...$args
     */
    function do_action(string $hook_name, ...$args): void
    {
        global $triggered_actions;

        $triggered_actions[] = [
            'hook' => $hook_name,
            'args' => $args,
        ];
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook_name): int
    {
        global $did_action_calls;

        return $did_action_calls[$hook_name] ?? 0;
    }
}

if (!function_exists('is_wp_error')) {
    /**
     * @param mixed $thing
     */
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production';
    }
}

// Mock getenv() function to support environment variable testing
if (!function_exists('mock_getenv')) {
    /**
     * Mock getenv for testing purposes.
     */
    function mock_getenv(string $varname, bool $local_only = false): false|string
    {
        global $mock_environment_vars;

        return $mock_environment_vars[$varname] ?? false;
    }
}

// Override native getenv in test context
if (!function_exists('getenv')) {
    function getenv(string $varname, bool $local_only = false): false|string
    {
        return mock_getenv($varname, $local_only);
    }
}

// Mock WP_Error class
if (!class_exists('WP_Error')) {
    class bootstrap
    {
        /**
         * @var array<string, array<string>>
         */
        private array $errors = [];

        public function __construct(string $code = '', string $message = '')
        {
            if (!empty($code)) {
                $this->errors[$code] = [$message];
            }
        }

        public function get_error_message(): string
        {
            if (empty($this->errors)) {
                return '';
            }

            $code = array_keys($this->errors)[0];

            return $this->errors[$code][0] ?? '';
        }
    }
}

// Test utilities for resetting global state
function reset_wp_logger_test_globals(): void
{
    global $applied_filters, $triggered_actions, $did_action_calls, $wp_rand_result;
    global $mock_environment_vars, $mock_constants;

    $applied_filters = [];
    $triggered_actions = [];
    $did_action_calls = [];
    $wp_rand_result = 50;
    $mock_environment_vars = [];
    $mock_constants = [];
}

function set_did_action_result(string $hook, int $count): void
{
    global $did_action_calls;
    $did_action_calls[$hook] = $count;
}

function set_wp_rand_result(int $result): void
{
    global $wp_rand_result;
    $wp_rand_result = $result;
}

/**
 * Set mock environment variable for testing.
 */
function set_mock_env_var(string $name, string $value): void
{
    global $mock_environment_vars;
    $mock_environment_vars[$name] = $value;
}

/**
 * Unset mock environment variable.
 */
function unset_mock_env_var(string $name): void
{
    global $mock_environment_vars;
    unset($mock_environment_vars[$name]);
}

/**
 * Get all mock environment variables.
 *
 * @return array<string, mixed>
 */
function get_mock_env_vars(): array
{
    global $mock_environment_vars;

    return $mock_environment_vars;
}

/**
 * Set mock WordPress constant for testing.
 */
function set_mock_constant(string $name, mixed $value): void
{
    global $mock_constants;
    $mock_constants[$name] = $value;

    if (!defined($name)) {
        define($name, $value);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_applied_filters(): array
{
    global $applied_filters;

    return $applied_filters;
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_triggered_actions(): array
{
    global $triggered_actions;

    return $triggered_actions;
}

/**
 * @param mixed $override_value
 */
function set_filter_override(string $hook, $override_value): void
{
    global $applied_filters;

    // Find the last entry for this hook and set override
    for ($i = count($applied_filters) - 1; $i >= 0; --$i) {
        if ($applied_filters[$i]['hook'] === $hook) {
            $applied_filters[$i]['test_override'] = $override_value;

            break;
        }
    }
}
