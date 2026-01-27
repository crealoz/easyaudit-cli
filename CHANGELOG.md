# Changelog

All notable changes to EasyAudit CLI will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Version compatibility system** for CLI-Middleware communication:
  - New `Version` class with `VERSION` and `HASH` constants
  - `--version` / `-v` CLI flag to display version information
  - `X-CLI-Version` and `X-CLI-Hash` headers sent with all API requests
  - `UpgradeRequiredException` for handling HTTP 426 (Upgrade Required) responses
- **Automated release workflow**:
  - GitHub Actions builds PHAR with embedded version and SHA-512 hash
  - Webhook notification to middleware for version registration
  - Automatic GitHub Release creation with PHAR artifact
  - Docker image tagging with version numbers

---

## [0.1.0] - 2026-01-27

### Added
- **GitHub repository templates**:
  - Bug report and feature request issue templates (YAML forms)
  - Pull request template
  - Issue template chooser with contact links
  - Dependabot configuration for Composer and GitHub Actions
- **Code coverage** with Codecov integration in CI workflow
- **CI/CD documentation** for multiple platforms:
  - GitHub Actions, GitLab CI, Azure DevOps
  - Jenkins, CircleCI, Travis CI, Bitbucket Pipelines
- **MIT License** file
- **CI/CD environment detection** for API requests:
  - New `CiEnvironmentDetector` service detects 7 CI providers
  - `X-CI-Provider` and `X-CI-Identity` headers sent with API requests
  - Supports GitHub Actions, GitLab CI, Azure DevOps, CircleCI, Jenkins, Travis CI, Bitbucket Pipelines
- **Interactive `--fix-by-rule` mode** for fix-apply command:
  - Select which rule to fix via interactive menu
  - Patches organized into rule-specific subdirectories (`patches/{ruleId}/...`)
  - Sequenced filenames for multiple patches per file (`File-2.patch`, `File-3.patch`)
  - Relative path preservation in patch output structure
- **`ClassToProxy` service** with 220+ heavy Magento classes:
  - Shared detection between `ProxyForHeavyClasses` and `SpecificClassInjection` processors
  - Includes repositories, resource connections, config readers, session handlers, etc.
- New ignored patterns in `SpecificClassInjection`:
  - Classes ending with `Provider` or `Resolver`
  - All `Magento\Framework` classes
  - Catalog visibility/status classes, sales order config, store manager, etc.
- New `Filenames::getRelativePath()` and `Filenames::getSequencedPath()` utility methods
- Integration test suite in phpunit.xml
- Tests for `ClassToProxy` integration in `SpecificClassInjectionTest`

### Changed
- `SpecificClassInjection` now skips CLI commands (Symfony Console) entirely
- `ProxyForHeavyClasses` uses `ClassToProxy` service instead of hardcoded list
- `PreparerInterface::prepareFiles()` now accepts optional `$selectedRule` parameter
- Removed `Collection` and `ResourceModel` from pattern-based heavy class detection (now uses explicit list)

### Fixed
- Reduced false positives in `SpecificClassInjection` for legitimate Magento patterns
- Removed redundant `isRegistry()` and `isFileSystem()` checks (covered by `ClassToProxy`)

---

## [0.0.8] - 2025-01-13

### Added
- Colorful scan output with severity indicators (red for errors, yellow for warnings, blue for info)
- Visual header with processor names in cyan for better readability
- Class hierarchy detection in SpecificClassInjection processor
- New rules for classes with children requiring manual fix:
  - `collectionWithChildrenMustUseFactory`
  - `repositoryWithChildrenMustUseInterface`
- ExternalToolMapping for issues fixable by external tools (php-cs-fixer suggestions)
- Progress bar in FixApply command
- Echo output for all processor rules showing issue counts
- New `PreparerInterface` with `GeneralPreparer` and `DiPreparer` for payload preparation
- Dedicated `Logger` service for error and debug logging
- `Filenames` utility class for path sanitization

### Changed
- Scanner output now displays processor names instead of identifiers
- Improved FixApply command with file-by-file processing
- Refactored `FixApply` to use class properties for better state management
- Extracted payload preparation logic into dedicated preparer classes
- Simplified progress bar rendering using class properties

### Fixed
- Helpers processor echo statements moved from getReport() to process() for consistency

---

## [0.0.7] - 2024-11-06

### Added
- 12 new processors for code quality analysis:
  - AroundPlugins
  - MagentoFrameworkPlugin
  - NoProxyInCommands
  - SameModulePlugins
  - HardWrittenSQL
  - SpecificClassInjection
  - UseOfRegistry
  - UseOfObjectManager
  - Preferences
  - Cacheable
  - BlockViewModelRatio
  - UnusedModules
- Output formats: JSON, SARIF, Text
- Docker support (ghcr.io/crealoz/easyaudit:latest)
- PHAR distribution
- GitHub Code Scanning integration via SARIF output

---

## [0.0.6] - 2024-10-02

## [0.0.5] - 2024-10-02

## [0.0.4] - 2024-09-25

## [0.0.3] - 2024-09-25

## [0.0.2] - 2024-09-25

### Added
- Initial release of EasyAudit CLI