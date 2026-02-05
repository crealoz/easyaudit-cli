# Changelog

All notable changes to EasyAudit CLI will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Improved ObjectManager detection for fixing (better identification of actual usages vs imports)
- Fixed class name comparison in `UseOfRegistry` and `SpecificClassInjection` processors (leading backslash normalization)

---

## [0.2.0] - 2026-02-05

### Added
- **`CliWriter` service** for centralized CLI output formatting:
  - Colored output methods: `success()`, `error()`, `warning()`, `info()`
  - Inline color helpers: `green()`, `blue()`, `bold()`
  - Progress bar with credits display
  - Menu item rendering for interactive selection
  - Result line with severity icons
- **New exceptions** for better error handling:
  - `CliException` with exit code support
  - `CouldNotPreparePayloadException` for payload preparation failures
  - `CurlResponseException` for API response errors
  - `RuleNotAppliedException` for rule selection errors
  - `NoChildrenException` for class hierarchy queries
- **`AbstractPreparer`** base class for payload preparers with shared logic
- **Rule mapping** via `MAPPED_RULES` constant for proxy configuration rules
- `phpcs.xml` configuration for PSR-12 code style enforcement
- Required PHP extensions declared in `composer.json`: `ext-curl`, `ext-libxml`, `ext-simplexml`
- Codecov token authentication in GitHub Actions workflow

### Changed
- **`FixApply` command** completely refactored:
  - Extracted into smaller focused methods
  - Uses `CliWriter` for all output
  - Proper exception handling instead of exit codes
  - Better separation of concerns
- **`UseOfObjectManager` processor** improved detection:
  - Now correctly identifies useless imports vs actual usage
  - Won't false-positive on unrelated `->get()` or `->create()` calls
  - Uses class constants for ObjectManager patterns
  - Leverages `Classes` utility for constructor analysis
- **`SpecificClassInjection` processor** simplified:
  - Consolidated 7 result arrays into `resultsByCategory` with `RULE_CONFIGS`
  - Single `addViolation()` method replaces multiple add methods
  - Uses `CliWriter::resultLine()` for output
- **Payload preparers** now extend `AbstractPreparer`:
  - `GeneralPreparer` and `DiPreparer` share common logic
  - Throws typed exceptions instead of `RuntimeException`
- **`UnusedModules` processor** improved config.php detection:
  - Now traverses up from scan path until config.php is found
  - Removed hardcoded relative path guessing
- **`Auth` command** simplified option parsing using `Args` utility
- **`Args` utility** refactored with `parseLongOption()` and `parseShortFlags()` methods
- Exit code now respects exception code via `$e->getCode() ?: 1`
- All processors updated for PSR-12 compliance (line length ≤150)

### Removed
- **`credits` command** (unused, stub only)
- **`fix-plan` command** (unused, stub only)
- Redundant checks like `hasChildren()` and `getChildren()` in SpecificClassInjection (uses `Classes::getChildren()`)
- Removed implicit `EnvAuthException` throw when credentials are empty

### Fixed
- ObjectManager useless import detection no longer triggers API fix attempts
- PSR-12 violations across all source files
- Missing newlines at end of files

---

## [0.1.2] - 2026-02-03

### Added
- Direct `curl` download command in README and CLI docs for easier PHAR installation

### Changed
- **Format validation**: `scan` command now validates `--format` option and shows error for unknown formats (only `json` and `sarif` are supported)
- Updated `actions/checkout` from v4 to v6 in all documentation examples
- Default format is now explicitly `json` (was implicitly text before)

### Fixed
- **Docker**: Added `easyaudit` wrapper script so the command works inside containers (CI/CD workflows using `container: image`)

### Removed
- `text` output format removed (console output is always displayed regardless of format)

---

## [0.1.1] - 2026-02-03

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

### Changed
- **Dockerfile simplified**: Now uses PHAR distribution instead of copying source files
- Removed unused imports and variables across multiple files
- `FixApply` refactored to use instance property for error tracking

### Removed
- Deleted `src/Core/Scan/Util/Fixable.php` (unused)
- Removed metadata section from `box.json`

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