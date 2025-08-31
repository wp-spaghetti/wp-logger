# Contributing to WP Env

Thank you for considering contributing to WP Env! This document outlines the process for contributing to this project.

## Development Workflow

We use [git-flow workflow](https://danielkummer.github.io/git-flow-cheatsheet/) and [conventional commits](https://www.conventionalcommits.org).

### Branch Structure
- `main` - Production-ready code
- `develop` - Development branch
- `feature/*` - New features
- `bugfix/*` - Bug fixes
- `hotfix/*` - Critical production fixes

### Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/your-username/wp-logger.git`
3. Create a feature branch: `git checkout -b feature/your-feature-name`
4. Install dependencies: `composer install`
5. Make your changes
6. Run tests: `composer test`
7. Run quality checks: `composer qa`
8. Commit using conventional commits
9. Push and create a pull request

## Development Setup

### Requirements
- PHP 8.0 or higher
- Composer
- Git

### Installation
```bash
git clone https://github.com/wp-spaghetti/wp-logger.git
cd wp-logger
composer install
```

### Running Tests
```bash
# Run all tests
composer test

# Run specific test suite
vendor/bin/phpunit tests/EnvironmentTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Code Quality
```bash
# Run all quality checks
composer qa

# Individual tools
composer lint          # PHP CS Fixer + PHPStan + Rector
composer static-analysis  # PHPStan only
composer security      # Security check
composer code-quality  # PHPMND + PHP Parser
```

## Coding Standards

### PHP Standards
- Follow PSR-12 coding standard
- Use strict typing: `declare(strict_types=1);`
- Document all public methods with PHPDoc
- Use meaningful variable and method names

### Commit Messages
We use [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): description

[optional body]

[optional footer]
```

**Types:**
- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation changes
- `style:` Code style changes (no logic changes)
- `refactor:` Code refactoring
- `test:` Adding or updating tests
- `chore:` Maintenance tasks

**Examples:**
```
feat(environment): add support for custom environment detection
fix(docker): improve container detection accuracy
docs(readme): update installation instructions
test(hooks): add comprehensive hook testing
```

### Code Style
```php
<?php

declare(strict_types=1);

namespace WpSpaghetti\WpEnv;

/**
 * Class documentation.
 */
class ExampleClass
{
    /**
     * Method documentation.
     */
    public function exampleMethod(string $param): bool
    {
        // Method implementation
        return true;
    }
}
```

## Testing Guidelines

### Test Structure
- Unit tests in `tests/` directory
- Test files should end with `Test.php`
- Use descriptive test method names
- Include both positive and negative test cases

### Test Example
```php
public function testMethodReturnsExpectedValue(): void
{
    $result = Environment::get('TEST_VAR', 'default');
    self::assertSame('expected', $result);
}
```

### Mock Data
Use the provided mock functions for testing:
- `set_mock_constant()`
- `set_mock_env_var()`
- `set_mock_file()`

## Documentation

### Code Documentation
- All public methods must have PHPDoc blocks
- Include `@param` and `@return` annotations
- Document complex logic with inline comments
- Use clear, concise language

### README Updates
When adding new features:
- Update the feature list
- Add usage examples
- Update the API reference if needed

## Pull Request Process

1. **Create Feature Branch**
   ```bash
   git checkout develop
   git checkout -b feature/your-feature
   ```

2. **Make Changes**
   - Write code following the standards above
   - Add/update tests for your changes
   - Update documentation if needed

3. **Test Your Changes**
   ```bash
   composer qa  # Run all quality checks
   composer test  # Run all tests
   ```

4. **Commit Changes**
   ```bash
   git add .
   git commit -m "feat: add new feature description"
   ```

5. **Push and Create PR**
   ```bash
   git push origin feature/your-feature
   ```
   Then create a pull request against the `develop` branch.

### PR Requirements
- All tests must pass
- Code coverage should not decrease
- Follow the PR template
- Link to related issues
- Use conventional commit messages

## Release Process

Releases are automated through GitHub Actions:

1. Merge to `main` triggers release workflow
2. Conventional commits determine version bump
3. Changelog is automatically updated
4. GitHub release is created
5. Packagist is notified

## Security

If you discover a security vulnerability, please follow our [Security Policy](SECURITY.md).

## Questions?

- Create an issue for questions about usage
- Join discussions for feature planning
- Contact maintainers for sensitive issues

## License

By contributing, you agree that your contributions will be licensed under the GPL-3.0-or-later license.

## Recognition

Contributors will be recognized in:
- GitHub contributors list
- Release notes for significant contributions
- README acknowledgments for major features

Thank you for contributing! 🎉