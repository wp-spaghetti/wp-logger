# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.2](https://github.com/wp-spaghetti/wp-logger/compare/v2.2.1...v2.2.2) (2025-09-14)


### Performance Improvements

* add archive:exclude in composer ([38c0566](https://github.com/wp-spaghetti/wp-logger/commit/38c05664cbbe48c2d7c931ac0f6d584c95adaec8))

## [2.2.1](https://github.com/wp-spaghetti/wp-logger/compare/v2.2.0...v2.2.1) (2025-09-07)

## [2.2.0](https://github.com/wp-spaghetti/wp-logger/compare/v2.1.3...v2.2.0) (2025-09-06)

### Features

* replace plugin_name arg w/ component_name ([#23](https://github.com/wp-spaghetti/wp-logger/issues/23)) ([329353e](https://github.com/wp-spaghetti/wp-logger/commit/329353e24253b25fa1cfbccf59875722768b841c))

## [2.1.3](https://github.com/wp-spaghetti/wp-logger/compare/v2.1.2...v2.1.3) (2025-09-05)

### Bug Fixes

* wonolog v2.x/v3.x PSR-3 placeholder substitution compatibility ([#22](https://github.com/wp-spaghetti/wp-logger/issues/22)) ([6abb84e](https://github.com/wp-spaghetti/wp-logger/commit/6abb84e21497b228417d1fddd4956acba52856f7))

## [2.1.2](https://github.com/wp-spaghetti/wp-logger/compare/v2.1.1...v2.1.2) (2025-09-05)

### Bug Fixes

* $message param accepts any type (Throwable, WP_Error, string, array, object) ([#21](https://github.com/wp-spaghetti/wp-logger/issues/21)) ([5e940bf](https://github.com/wp-spaghetti/wp-logger/commit/5e940bfd71d4ad63088f5773a9d6592ecc8edff5))

## [2.1.1](https://github.com/wp-spaghetti/wp-logger/compare/v2.1.0...v2.1.1) (2025-09-04)

### Bug Fixes

* fix type hints for compatibility w/ psr/log:^1.0 ([#20](https://github.com/wp-spaghetti/wp-logger/issues/20)) ([9732933](https://github.com/wp-spaghetti/wp-logger/commit/9732933e1fe5d70b0705d0c8c9e47e1a2910ea3b))

## [2.1.0](https://github.com/wp-spaghetti/wp-logger/compare/v2.0.1...v2.1.0) (2025-09-03)

### Features

* add support to psr/log:^1.x for inpsyde/wonolog:^2.x compatibility ([#18](https://github.com/wp-spaghetti/wp-logger/issues/18)) ([#19](https://github.com/wp-spaghetti/wp-logger/issues/19)) ([48a249b](https://github.com/wp-spaghetti/wp-logger/commit/48a249b2b5988f9e6509985fada41760bb59423c))

## [2.0.1](https://github.com/wp-spaghetti/wp-logger/compare/v2.0.0...v2.0.1) (2025-09-03)

### Performance Improvements

* better wp-env integration ([#16](https://github.com/wp-spaghetti/wp-logger/issues/16)) ([#17](https://github.com/wp-spaghetti/wp-logger/issues/17)) ([6c36cd1](https://github.com/wp-spaghetti/wp-logger/commit/6c36cd1841fd33db546ec10ae16e0adc7a65f5e1))

## [2.0.0](https://github.com/wp-spaghetti/wp-logger/compare/v1.0.0...v2.0.0) (2025-09-02)

### ⚠ BREAKING CHANGES

* add wp-env package (#14) (#15)

### Features

* add wp-env package ([#14](https://github.com/wp-spaghetti/wp-logger/issues/14)) ([#15](https://github.com/wp-spaghetti/wp-logger/issues/15)) ([c8afd7c](https://github.com/wp-spaghetti/wp-logger/commit/c8afd7c88f77dba98c31713dd92cc640d491197d))

## 1.0.0 (2025-09-01)

### Bug Fixes

* **deps:** resolve PHP 8.0 dependency conflict with psr/log ([#1](https://github.com/wp-spaghetti/wp-logger/issues/1)) ([b63ec26](https://github.com/wp-spaghetti/wp-logger/commit/b63ec26430b273e864c6fcb1096aaaed303aac50))
