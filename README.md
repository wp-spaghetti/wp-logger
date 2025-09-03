![PHP Version](https://img.shields.io/packagist/php-v/wp-spaghetti/wp-logger)
![GitHub Downloads (all assets, all releases)](https://img.shields.io/github/downloads/wp-spaghetti/wp-logger/total)
![GitHub Actions Workflow Status](https://github.com/wp-spaghetti/wp-logger/actions/workflows/main.yml/badge.svg)
![Coverage Status](https://img.shields.io/codecov/c/github/wp-spaghetti/wp-logger)
![GitHub Issues](https://img.shields.io/github/issues/wp-spaghetti/wp-logger)
![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen)
![GitHub Release](https://img.shields.io/github/v/release/wp-spaghetti/wp-logger)
![License](https://img.shields.io/github/license/wp-spaghetti/wp-logger)
<!--
![Code Climate](https://img.shields.io/codeclimate/maintainability/wp-spaghetti/wp-logger)
-->

# WP Logger

A comprehensive WordPress logging service with Wonolog integration, secure file logging, multi-server protection, and environment-based configuration.

## Features

- **PSR-3 Compatibility**: Full implementation of PSR-3 LoggerInterface for standardized logging
- **Environment-Based Configuration**: Powered by [WP Env](https://github.com/wp-spaghetti/wp-env) for flexible configuration management
- **Wonolog Integration**: Automatic detection and seamless integration with Inpsyde Wonolog
- **Secure File Logging**: WordPress.org compliant fallback logging with multi-server protection
- **Multi-Server Protection**: Built-in protection files for Apache, Nginx, IIS, LiteSpeed, and more
- **Configurable Log Levels**: Minimum log level filtering with environment variable support
- **Intelligent Log Retention**: Environment-specific retention policies with automatic cleanup
- **Hook System**: Extensible architecture with WordPress hooks for customization
- **Debug Mode Support**: Intelligent handling of development vs production environments
- **Zero Dependencies**: Works with or without external logging libraries
- **Plugin Isolation**: Each plugin/theme gets independent log directories and settings

## Installation

Install via Composer:

```bash
composer require wp-spaghetti/wp-logger
```

## Quick Start

### 1. Basic Usage

```php
<?php
use WpSpaghetti\WpLogger\Logger;

// Initialize with minimal configuration
$logger = new Logger([
    'plugin_name' => 'my-awesome-plugin'
]);

// Standard PSR-3 logging methods
$logger->info('Plugin initialized successfully');
$logger->error('Something went wrong', ['error_code' => 500]);
$logger->debug('Debug information', ['user_id' => 123]);
```

### 2. Environment-Based Configuration

```php
<?php
use WpSpaghetti\WpLogger\Logger;

// Configuration can come from environment variables via WP Env
$logger = new Logger([
    'plugin_name' => 'my-plugin',
    'log_retention_days' => 60, // Can be overridden by LOGGER_RETENTION_DAYS env var
    'min_log_level' => 'info',  // Can be overridden by LOGGER_MIN_LEVEL env var
]);

// Logging behavior:
// - If Wonolog is available: uses Wonolog for advanced logging
// - If Wonolog not available: uses fallback (error_log in debug/dev, file logging in production)
$logger->debug('Debug information');
$logger->info('Informational message');
$logger->warning('Warning message');
$logger->error('Error occurred');
```

### 3. WordPress Integration

```php
<?php
// In your plugin main file
use WpSpaghetti\WpLogger\Logger;

class MyPlugin 
{
    private Logger $logger;
    
    public function __construct() 
    {
        $this->logger = new Logger([
            'plugin_name' => 'my-plugin',
            'log_retention_days' => 30
        ]);
        
        add_action('init', [$this, 'init']);
    }
    
    public function init(): void 
    {
        $this->logger->info('Plugin initialized');
        
        // Your plugin logic here...
        try {
            $this->doSomething();
        } catch (\Exception $exception) {
            $this->logger->error('Operation failed', [
                'exception' => $exception
            ]);
        }
    }
}
```

## Configuration

WP Logger v2.0 supports multiple configuration sources with intelligent priority:

### Configuration Priority (Highest to Lowest)

1. **Plugin-specific Environment Variables** (`MY_PLUGIN_*`)
2. **Global Logger Environment Variables** (`LOGGER_*`) 
3. **WordPress Constants** (wp-config.php)
4. **Configuration Array** (passed to constructor)
5. **Default Values**

### Configuration Array Options

```php
$config = [
    // Required: Unique identifier for your plugin/theme
    'plugin_name' => 'my-plugin',
    
    // Optional: Days to keep log files (default: 30)
    'log_retention_days' => 60,
    
    // Optional: Minimum log level to record (default: 'debug')
    'min_log_level' => 'info',
    
    // Optional: Wonolog namespace (default: 'Inpsyde\Wonolog')
    'wonolog_namespace' => 'MyApp\Wonolog',
    
    // Optional: WordPress constant to disable logging
    // (default: auto-generated from plugin_name)
    'disable_logging_constant' => 'MY_PLUGIN_DISABLE_LOGGING',
    
    // Optional: WordPress constant for retention days
    // (default: auto-generated from plugin_name)
    'log_retention_constant' => 'MY_PLUGIN_LOG_RETENTION_DAYS'
];
```

### Environment Variables (via WP Env)

```bash
# Global logger settings
LOGGER_PLUGIN_NAME=my-plugin           # Plugin identifier
LOGGER_RETENTION_DAYS=30               # Log retention period
LOGGER_MIN_LEVEL=info                  # Minimum log level
LOGGER_DISABLED=false                  # Disable all logging
LOGGER_WONOLOG_NAMESPACE=Inpsyde\Wonolog

# Plugin-specific settings (higher priority)
MY_PLUGIN_LOG_RETENTION_DAYS=60
MY_PLUGIN_DISABLED=false
MY_PLUGIN_MIN_LEVEL=warning
```

### WordPress Constants (wp-config.php)

```php
// Control logging behavior per plugin
define('MY_PLUGIN_DISABLE_LOGGING', true);      // Disable all logging
define('MY_PLUGIN_LOG_RETENTION_DAYS', 90);     // Keep logs for 90 days

// Global WordPress debug (affects fallback behavior)
define('WP_DEBUG', true);  // Forces error_log() usage in fallback mode
```

## Logging Behavior

WP Logger uses a simple, reliable logging strategy:

1. **With Wonolog Available**: Uses Wonolog for advanced logging features, routing, and formatting
2. **Without Wonolog (Fallback)**:
   - **Development/Debug Mode**: Logs go to PHP error_log for immediate developer feedback
   - **Production Mode**: Logs go to secure files in WordPress uploads directory
   - **Disabled Mode**: Only hooks are triggered (no actual logging)

### Environment Detection for Fallback Logging

When Wonolog is not available, WP Logger adapts its fallback behavior:

### Development/Debug Environment
- **Detection**: `WP_DEBUG=true` or `WP_ENVIRONMENT_TYPE=development`
- **Behavior**: Uses PHP error_log for immediate developer feedback
- **Format**: Includes environment tags for context
- **Performance**: Optimized for debugging with detailed context

### Production Environment  
- **Detection**: `WP_DEBUG=false` and `WP_ENVIRONMENT_TYPE=production`
- **Behavior**: Uses secure file logging with WordPress-compliant protection
- **Format**: Minimal overhead, performance-optimized
- **Security**: Protected directories with multi-server support

## File Structure

WP Logger creates the following structure:

```
wp-content/uploads/
└── my-plugin/
    ├── index.php              # Directory protection
    └── logs/
        ├── .htaccess          # Apache protection
        ├── web.config         # IIS protection  
        ├── index.php          # Universal protection
        ├── README             # Server configuration guide
        ├── 2024-01-15_a1b2c3d4.dat
        └── 2024-01-16_a1b2c3d4.dat
```

## API Reference

### Core Methods

#### `__construct(array $config = [])`
Initialize logger with configuration options.

#### `log($level, $message, array $context = []): void`
Log message with arbitrary level (PSR-3 interface).

#### PSR-3 Log Level Methods
- `emergency($message, array $context = []): void`
- `alert($message, array $context = []): void` 
- `critical($message, array $context = []): void`
- `error($message, array $context = []): void`
- `warning($message, array $context = []): void`
- `notice($message, array $context = []): void`
- `info($message, array $context = []): void`
- `debug($message, array $context = []): void`

### Utility Methods

#### `getWonologLogger(): ?LoggerInterface`
Get Wonolog logger instance if available.

#### `refreshWonologCache(): void`
Force refresh Wonolog availability cache.

#### `getDebugInfo(): array`
Get comprehensive debug information for troubleshooting.

#### `getConfig(): array`
Get current logger configuration.

## Examples

### Plugin Integration

```php
<?php
/*
Plugin Name: My Awesome Plugin
Version: 1.0.0
*/

use WpSpaghetti\WpLogger\Logger;

class MyAwesomePlugin 
{
    private Logger $logger;
    
    public function __construct() 
    {
        $this->logger = new Logger([
            'plugin_name' => 'my-awesome-plugin',
            'log_retention_days' => 30
        ]);
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    public function activate(): void 
    {
        $this->logger->info('Plugin activated', ['version' => '1.0.0']);
    }
    
    public function deactivate(): void 
    {
        $this->logger->info('Plugin deactivated');
    }
    
    public function init(): void 
    {
        $this->logger->debug('Plugin initialization started');
        
        // Your plugin logic...
        
        $this->logger->info('Plugin fully initialized');
    }
}

new MyAwesomePlugin();
```

### Theme Integration

```php
<?php
// In functions.php

use WpSpaghetti\WpLogger\Logger;

$theme_logger = new Logger([
    'plugin_name' => get_template(),
    'log_retention_days' => 14
]);

// Log theme setup
add_action('after_setup_theme', function() use ($theme_logger) {
    $theme_logger->info('Theme setup completed', [
        'theme' => get_template(),
        'version' => wp_get_theme()->get('Version')
    ]);
});

// Log template loading for debugging
add_action('template_redirect', function() use ($theme_logger) {
    global $template;
    $theme_logger->debug('Template loaded', [
        'template' => basename($template),
        'query_vars' => get_query_var('all')
    ]);
});
```

### Error Handling Integration

```php
<?php
use WpSpaghetti\WpLogger\Logger;

class ErrorHandler 
{
    private Logger $logger;
    
    public function __construct() 
    {
        $this->logger = new Logger(['plugin_name' => 'error-handler']);
        
        // Hook into WordPress error handling
        add_action('wp_die_handler', [$this, 'handle_wp_die']);
        set_error_handler([$this, 'handle_php_error']);
        set_exception_handler([$this, 'handle_exception']);
    }
    
    public function handle_wp_die($message): void 
    {
        $this->logger->critical('WordPress died', ['message' => $message]);
    }
    
    public function handle_php_error($severity, $message, $file, $line): bool 
    {
        $this->logger->error('PHP Error', [
            'severity' => $severity,
            'message' => $message,
            'file' => basename($file),
            'line' => $line
        ]);
        
        return false; // Let PHP handle it too
    }
    
    public function handle_exception(\Throwable $exception): void 
    {
        $this->logger->critical('Uncaught Exception', [
            'exception' => $exception
        ]);
    }
}
```

### Environment-Aware Plugin

```php
<?php
/*
Plugin Name: Environment-Aware Plugin
Version: 2.0.0
*/

use WpSpaghetti\WpLogger\Logger;

class EnvironmentAwarePlugin 
{
    private Logger $logger;
    
    public function __construct() 
    {
        // Logger automatically detects environment via WP Env
        $this->logger = new Logger();
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    public function activate(): void 
    {
        $debugInfo = $this->logger->getDebugInfo();
        
        $this->logger->info('Plugin activated', [
            'environment' => $debugInfo['environment_type'],
            'is_container' => $debugInfo['is_container'],
            'server' => $debugInfo['server_software']
        ]);
    }
    
    public function init(): void 
    {
        $debugInfo = $this->logger->getDebugInfo();
        
        if ($debugInfo['is_development']) {
            // Development-specific initialization
            $this->enableDebugMode();
            $this->logger->debug('Plugin initialized in development mode');
        } elseif ($debugInfo['is_staging']) {
            // Staging-specific initialization
            $this->enableStagingFeatures();
            $this->logger->info('Plugin initialized in staging mode');
        } else {
            // Production initialization
            $this->enableProductionOptimizations();
            $this->logger->notice('Plugin initialized in production mode');
        }
    }
    
    private function enableDebugMode(): void 
    {
        // Enable verbose logging, profiling, etc.
        $this->logger->debug('Debug mode enabled');
    }
    
    private function enableStagingFeatures(): void 
    {
        // Enable testing features, disable emails, etc.
        $this->logger->info('Staging features enabled');
    }
    
    private function enableProductionOptimizations(): void 
    {
        // Enable caching, disable debug features, etc.
        $this->logger->notice('Production optimizations enabled');
    }
}

new EnvironmentAwarePlugin();
```

## Hook System

WP Logger provides several hooks for customization:

### Override Logging Behavior

```php
// Completely override logging (return non-null to prevent default logging)
add_filter('wp_logger_override_log', function($result, $level, $message, $context, $config) {
    // Custom logging implementation
    my_custom_logger($level, $message, $context);
    return true; // Prevent default logging
}, 10, 5);
```

### Customize Wonolog Integration

```php
// Change Wonolog namespace
add_filter('wp_logger_wonolog_namespace', function($namespace) {
    return 'MyApp\Logger';
});

// Modify Wonolog prefix
add_filter('wp_logger_wonolog_prefix', function($prefix, $level, $message, $context, $config) {
    return 'myapp.log';
}, 10, 5);

// Customize Wonolog action name
add_filter('wp_logger_wonolog_action', function($action, $level, $message, $context, $config) {
    return 'custom.log.' . $level;
}, 10, 5);
```

### Hook Into Logging Events

```php
// React to all log entries
add_action('wp_logger_logged', function($level, $message, $context, $plugin_name) {
    // Send critical errors to external monitoring
    if ($level === 'critical') {
        send_to_monitoring_service($message, $context);
    }
}, 10, 4);

// Handle fallback logging
add_action('wp_logger_fallback', function($level, $message, $context, $plugin_name) {
    // Custom fallback when Wonolog is not available
}, 10, 4);

// Hook into specific log levels during fallback
add_action('wp_logger_fallback_error', function($message, $context, $plugin_name) {
    // Handle error-level logs specifically
}, 10, 3);
```

## Multi-Server Protection

WP Logger automatically creates protection files for various web servers:

- **Apache**: `.htaccess` files
- **Nginx**: Configuration examples in README
- **IIS**: `web.config` files
- **LiteSpeed**: `.htaccess` compatible
- **Universal**: `index.php` protection files

### Manual Server Configuration

For Nginx, add to your server configuration:

```nginx
# Block access to WP Logger files
location ~* /wp-content/uploads/.*/logs/ {
    deny all;
    return 403;
}
```

## Troubleshooting

### Debug Information

```php
// Get comprehensive debug info
$debugInfo = $logger->getDebugInfo();
var_dump($debugInfo);
```

### Common Issues

**Logs not appearing:**
- Check if `WP_DEBUG` is enabled for immediate error_log output
- Verify the disable logging constant is not set
- Ensure WordPress upload directory is writable

**Wonolog not detected:**
- Verify Wonolog is properly installed and activated
- Check the Wonolog namespace configuration
- Use `refreshWonologCache()` if Wonolog state changes

**File permissions:**
- WordPress upload directory must be writable
- Log files are created with restrictive permissions automatically

## Requirements

- PHP 8.0 or higher
- WordPress 5.0 or higher
- PSR Log 2.0|3.0 for interface compatibility
- [WP Env](https://github.com/wp-spaghetti/wp-env) 2.0+ for environment management
- Optional: [Inpsyde Wonolog](https://github.com/inpsyde/wonolog) for advanced logging

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for a detailed list of changes for each release.

We follow [Semantic Versioning](https://semver.org/) and use [Conventional Commits](https://www.conventionalcommits.org/) to automatically generate our changelog.

### Release Process

- **Major versions** (1.0.0 → 2.0.0): Breaking changes
- **Minor versions** (1.0.0 → 1.1.0): New features, backward compatible
- **Patch versions** (1.0.0 → 1.0.1): Bug fixes, backward compatible

All releases are automatically created when changes are pushed to the `main` branch, based on commit message conventions.

## Contributing

For your contributions please use:

- [Conventional Commits](https://www.conventionalcommits.org)
- [git-flow workflow](https://danielkummer.github.io/git-flow-cheatsheet/)
- [Pull request workflow](https://docs.github.com/en/get-started/exploring-projects-on-github/contributing-to-a-project)

See [CONTRIBUTING](.github/CONTRIBUTING.md) for detailed guidelines.

## Sponsor

[<img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" width="200" alt="Buy Me A Coffee">](https://buymeacoff.ee/frugan)

## License

(ɔ) Copyleft 2025 [Frugan](https://frugan.it).  
[GNU GPLv3](https://choosealicense.com/licenses/gpl-3.0/), see [LICENSE](LICENSE) file.
