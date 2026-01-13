# Changelog

All notable changes to EasyAudit CLI will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Multi-file fix support via `Api::requestMultiFilefix()` for cross-file issues

### Changed
- Scanner output now displays processor names instead of identifiers
- Improved FixApply command with file-by-file processing
- Refactored `FixApply` to use class properties for better state management
- Extracted payload preparation logic into dedicated preparer classes
- Simplified progress bar rendering using class properties

### Fixed
- Helpers processor echo statements moved from getReport() to process() for consistency

---

## [1.0.0] - 2025-01-06

### Added
- Initial release of EasyAudit CLI
- 16 static analysis processors:
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
  - AdvancedBlockVsViewModel
  - BlockViewModelRatio
  - UnusedModules
  - ProxyForHeavyClasses
  - PaymentInterfaceUseAudit
  - Helpers
- Output formats: JSON, SARIF, Text
- Docker support (ghcr.io/crealoz/easyaudit:latest)
- PHAR distribution (~165KB)
- Zero external dependencies (custom PSR-0 autoloader)
- GitHub Code Scanning integration via SARIF output
- Fix generation via EasyAudit API