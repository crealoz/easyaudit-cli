# Changelog

All notable changes to EasyAudit CLI will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.9] 2026-04-16

### Fixed
- **Fix-apply: proxy patches not generated**: Rule ID mapping mismatch between Scanner and FixApply prevented proxy findings (`noProxyUsedInCommands`, `noProxyUsedForHeavyClasses`) from reaching the fixer. Scanner checked fixable types using the processor's ruleId (e.g., `noProxyUsedInCommands`) while the API returns the mapped name (`proxyConfiguration`). Both Scanner and FixApply now resolve mapped rule names via `PreparerInterface::MAPPED_RULES` before checking fixable types — consistent with what `AbstractPreparer::isRuleFixable()` already does
- **ProxyForHeavyClasses no longer recommends Proxy for Collections**: Collections are stateful and need a fresh instance per use — the correct pattern is `CollectionFactory`, not `\Proxy`. `SpecificClassInjection` already flags this correctly with `collectionMustUseFactory`. Removed `'Collection'` from heavy class patterns and added an explicit `Types::isCollectionType()` guard in `isHeavyClass()`

### Added
- **Result consolidation**: All processors now merge consecutive-line findings for the same file into a single entry (e.g., two proxy issues on lines 15-16 become one entry with `startLine: 15`, `endLine: 16`). Non-consecutive findings remain separate. Messages are joined with line breaks, metadata entries are collected into arrays, and severity takes the highest value. Consolidation logic lives in `AbstractProcessor::consolidateResults()` and is applied in all 13 processors that can produce per-file duplicates
- **SARIF `endLine` support**: Region objects now include `endLine` when it differs from `startLine`, per the SARIF 2.1.0 spec
- **HTML line ranges**: The Line column now displays ranges (e.g., `15-16`) for consolidated findings, and messages render with `<br>` separators

### Changed
- **DiPreparer**: `processFiles()` now handles both consolidated metadata (array of entries) and single-entry metadata, ensuring fix-apply works correctly with consolidated reports
- **FixApply `selectRules()`**: Interactive rule selection and credit cost lookup now use mapped rule names, fixing proxy rules not appearing in the `--fix-by-rule` menu

---

## [1.0.8] 2026-04-13

### Added
- **Magento Version Security Check**: Detects known security vulnerabilities based on the Magento version found in `composer.lock`. Uses offline security bulletin data (`data/security/`) covering Magento 2.4.3 through 2.4.8. Includes `tools/update-security-bulletins.php` to refresh bulletin data
- **Detailed processor descriptions**: All 21 processors now include structured `longDescription` with Impact, Why change, and How to fix sections
- **HTML report: formatted descriptions**: Long descriptions render with bold labels (Impact, Why change, How to fix) instead of plain short descriptions

### Changed
- **Default output format**: Changed from `json` to `html`
- **JSON output optimized for fixer**: When format is `json`, report entries are stripped of `name`, `shortDescription`, `longDescription`, and per-file `message` to reduce payload size and improve fixer processing. Security check is skipped in JSON mode. Only fixable rules are included.
- **PHAR build**: `data/` directory now included in distribution (`box.json`)
- **Processor documentation** (`docs/processors.md`): Rewritten with per-rule detail, rule counts, and structured explanations

### Removed
- **`$onlyFixable` parameter** in `Scanner::run()`: Replaced by format-driven `$fixerReady` logic

---

## [1.0.7] 2026-03-19

### Added
- **Deep plugin stack detection** (`deepPluginStack` rule in AroundPlugins): Detects when 2+ around plugins intercept the same method on a target class, creating a deep call stack that amplifies performance overhead and complicates debugging. Uses new `Interceptor` and `PluginRegistry` utilities to analyze generated interceptor files and di.xml plugin mappings
- **Stateful model injection detection** (`statefulModelInjection` rule in SpecificClassInjection): Detects direct injection of classes extending `AbstractModel` or `AbstractExtensibleModel` — these hold mutable state and should use a Factory to create fresh instances
- **`Severity` enum** (`src/Core/Scan/Severity.php`): Centralized severity model with three levels (HIGH, MEDIUM, LOW) and SARIF mapping (`toSarif()`)
- **`Interceptor` utility** (`src/Core/Scan/Util/Interceptor.php`): Analyzes Magento's generated interceptor files to extract intercepted method names
- **`PluginRegistry` utility** (`src/Core/Scan/Util/PluginRegistry.php`): Parses all di.xml files to build plugin-to-target class mappings, enabling cross-plugin analysis
- **Generated code auto-detection**: Scanner now detects non-empty `generated/code/` directories in Magento installations and exposes the path via `Scanner::getGeneratedPath()` for advanced analysis
- **CountOnCollection**: phtml detection now works for blocks that inject a Collection directly (not via CollectionFactory) and return it from a method

### Changed
- **Severity model overhaul**: Replaced SARIF-style severity names (`error`/`warning`/`note`) with internal model (`high`/`medium`/`low`) throughout all processors, reporters, and utilities. SARIF output maps back to spec levels automatically (`high`→error, `medium`→warning, `low`→note)
- **HtmlReporter**: Summary cards and filtering now use HIGH/MEDIUM/LOW labels and corresponding CSS classes
- **SpecificClassInjection**: Severity recalibrated — `collection` and `repository` rules changed from `error` to `high`, `resourceModel` and `genericClass` from `warning` to `medium`
- **AroundPlugins**: Severity recalibrated — `aroundWithoutProceed` from `error` to `high`, `aroundWithProceed` from `warning` to `medium`
- **Formater::formatError()**: Default severity changed from `warning` to `medium`
- **CliWriter::resultLine()**: Severity parameter now accepts `high`/`medium`/`low`
- **Scan command**: Exit code logic uses new severity keys (`high`/`medium` instead of `errors`/`warnings`)

### Removed
- **`collectionWithChildren` rule** (SpecificClassInjection): Removed — the `collection` rule now covers all cases without requiring child class detection
- **`repositoryWithChildren` rule** (SpecificClassInjection): Removed — the `repository` rule now covers all cases without requiring child class detection

---

## [1.0.6] 2026-03-13

### Fixed
- **AroundPlugins**: Fixed callable detection for multi-line function signatures — around plugins with parameters spanning multiple lines (common with complex type hints) are now correctly detected and classified
- **AroundPlugins**: Callable parameter is now identified by position (always the second parameter per Magento convention) instead of heuristic name/type matching — eliminates false negatives when the callable has a non-standard name or no type hint
- **AroundPlugins**: Callable invocation detection now matches `$proceed(` instead of `$proceed();` — correctly handles calls with arguments (e.g., `$proceed($product, $request)`)
- **Functions::getFunctionContent()**: Fixed inner content extraction for multi-line signatures — parameter lines before the opening brace are no longer included in the function body

### Changed
- **FixApply**: Added `--format` option (default: `git`) passed through to the API for patch format selection
- **FixApply**: Absolute paths in diffs are now normalized to relative paths for `git apply` compatibility

---

## [1.0.5] 2026-03-10

### Added
- **InlineStyles processor** (21st processor): Detects inline CSS (`style=""` attributes and `<style>` blocks) in phtml/html templates — flags CSP violations and maintainability issues. Email and PDF templates are excluded as they legitimately require inline CSS
- **HTML file scanning**: Scanner now collects `.html` files alongside phtml for template analysis

### Fine-tuning after benchmark

Processors were tested against a real Magento 2 codebase. The following changes reduce false positives and improve detection accuracy.

#### New utilities
- **`Classes::isControllerClass()`**: Detects Magento controller classes (Action, HttpGet/PostActionInterface, Backend Action)
- **`Classes::getParentConstructorParams()`**: Extracts parameter names passed to `parent::__construct()`
- **`Classes::isParentPassthrough()`**: Shared utility to check if a constructor param is forwarded to `parent::__construct()` — used by SpecificClassInjection and UseOfRegistry
- **`Content::getLineNumber()` `$afterLine` parameter**: Optional parameter to skip matches before a given line — enables reporting constructor arg lines instead of property lines
- **`Modules::isEmailTemplate()`**: Detects files in `/email/` directories
- **`Modules::isPdfTemplate()`**: Detects files in `/pdf/`, `/invoice/`, `/shipment/`, `/creditmemo/` directories

#### Processor improvements
- **AroundPlugins**: Around plugins using `$proceed()` conditionally (ternary, if/else, short-circuit `&&`/`||`) are no longer flagged — conditional execution is a legitimate around plugin pattern. `try/catch/finally` blocks and closing braces are now treated as structural lines when classifying before/after plugins
- **AdvancedBlockVsViewModel**: Suffix-based exclusion (`*Url`, `*Html`) replaces redundant entries in `allowedMethods`. Threshold lowered from 5 to 1 suspicious call
- **HardWrittenSQL**: Setup/Patch files are no longer skipped entirely — SQL is still detected but severity is reduced to `note`
- **UseOfObjectManager**: Now skips `Setup/Patch`, `Console/Command`, and `Test` paths entirely (legitimate ObjectManager usage). Detects variable-argument OM usage (`->get($variable)`). Config classes (extending `Magento\Framework\Config\Data`) get reduced severity (`note`) for variable-argument usage since the real fix is to use Factory types in configuration XML
- **UseOfRegistry**: Registry injected only to pass to `parent::__construct()` (no active `->registry()`/`->register()`/`->unregister()` calls) is no longer flagged
- **SpecificClassInjection**: Setup directories are now skipped. `AbstractModel` and `AbstractExtensibleModel` added to ignored substrings. `Builder`, `Emulation`, `Reader`, `Service`, `Settings` added to legitimate suffixes. Collection injection inside `AbstractModel` subclasses is no longer flagged (legitimate `$resourceCollection` pattern). Line numbers now report constructor arg position instead of property declaration
- **SameModulePlugins**: Line numbers now use the plugged class (target) instead of the plugin class — prevents duplicate lines when the same plugin targets multiple classes
- **ProxyForHeavyClasses**: Controller classes are now skipped (non-shared, always execute their dependencies)
- **Cacheable**: Admin (`/adminhtml/`) and email template layouts are now skipped (caching not applicable)
- **Scanner**: `Test` directory (singular, Magento convention) added to default exclusions alongside `Tests`
- **Classes::getConstructorParameters()**: Now strips inline comments (`//` and `/* */`) from constructor body before parsing, preventing misidentified parameters
- **Classes::parseConstructorParameters()**: Added validation to skip tokens that aren't valid class names (comments, array syntax, variables)

#### Fixes
- **SpecificClassInjection**: Parent constructor params were never excluded due to `$` prefix mismatch (`$context` vs `context`) — fixed via `Classes::isParentPassthrough()`
- **SameModulePlugins**: Two `<type>` nodes with the same plugin class no longer report the same line number
- **AdvancedBlockVsViewModel**: Fixed `Array to string conversion` error when building data crunch message
- **Psalm**: Added `CliWriter.php` to `PossiblyUnusedMethod` suppression list

---

## [v1.0.4] - 2026-03-06

### Added
- **Multi-select rule fixing**: `--fix-by-rule` now supports comma-separated input (e.g., `1,3,5`) and `all` to select every fixable rule at once
- **Credit cost display**: Rule selection menu now shows the credit cost per file for each rule
- **Path-based exclude patterns**: `--exclude` now supports patterns with `/` that match relative path prefixes (e.g., `--exclude=app/code/SomeVendor`), in addition to simple basename matching
- **Clickable report path**: Report file path in CLI output is now a clickable hyperlink (OSC 8) in supported terminals (PHPStorm, iTerm2, GNOME Terminal, Windows Terminal, etc.)
- **HTML report sponsor link**: Footer now links to the GitHub repository and includes a sponsor link

### Removed
- **`--all-magento` flag**: `vendor/` is now always excluded when scanning — no option to include it (too slow, not useful for static analysis)

### Changed
- **`PreparerInterface::prepareFiles()`**: `$selectedRule` parameter changed from `?string` to `?array` (`$selectedRules`) to support multi-rule selection
- **`FixApply`**: When multiple rules are selected, patches use the default layout (`patches/{path}/File.patch`); single-rule selection keeps the rule-specific layout (`patches/{ruleId}/{path}/File.patch`)

---

## [v1.0.3] - 2026-03-04

### Fixed
- **Lazy service instantiation**: All services (`Scanner`, `Api`, `Logger`) are now created lazily inside the command registry — fixes config directory error when running `scan` in Docker without a home directory

### Changed
- **Dockerfile**: Added `WORKDIR /workspace` so default report output lands inside the mounted volume
- **Docker documentation**: All examples now include `--user "$(id -u):$(id -g)"` for correct file ownership

---

## [v1.0.2] - 2026-03-04

### Added
- **Magento root auto-detection**: When scanning a Magento installation root, EasyAudit now detects it automatically (2+ indicators: `bin/magento`, `nginx.conf.sample`, `app/etc/env.php`, `generated/`, `pub/`) and displays the list of auto-excluded directories
- **Default exclusion of Magento noise directories**: `vendor`, `generated`, `var`, `pub`, `setup`, `lib`, `dev`, `phpserver`, `update` are now always excluded — no need to pass `--exclude` manually
- **Interactive vendor prompt**: When a Magento root is detected in interactive mode, the user is asked whether to include the `vendor/` directory in the scan (default: no)
- **`--all-magento` flag**: In CI/CD environments, pass `--all-magento` to include `vendor/` in the scan (auto-excluded by default)
- **`Scanner::isMagentoRoot()`** public static method for Magento root detection

### Fixed
- **`--exclude` now matches directory basenames**: Previously, `--exclude=custom` only matched full paths; now it correctly skips directories by name during recursive scanning

---

## [v1.0.1] - 2026-03-04

### Fixed
- **Content::removeComments**: Now throws `InvalidArgumentException` on `preg_replace` failure instead of silently returning null — prevents downstream errors on malformed files
- **CollectionInLoop**: Gracefully handles `removeComments` failures with a warning instead of crashing the scan
- **HardWrittenSQL**: Gracefully handles `removeComments` failures with a warning instead of crashing the scan
- **Content::findApproximateLine**: Moved `preg_replace` for needle normalization outside the loop — minor performance improvement

### Added
- **PHAR provenance attestation** in release workflow via `actions/attest-build-provenance@v2`
- **Security & Privacy documentation** (`docs/security.md`)
- **CliWriter**: Overridable `$stderr` stream for testability

### Changed
- README updated with privacy notice and 20 processors count

---

## [v1.0.0] - 2026-02-27

### Added
- **DeprecatedEscaper processor**: Detects deprecated `$block->escapeHtml()` / `$this->escapeHtml()` usage in phtml templates — should use `$escaper->escapeHtml()` instead (Magento 2.4+ best practice)

---

## [v0.6.2] - 2026-02-19

### Changed
- Documentation updates

---

## [v0.6.1] - 2026-02-16

### Fixed
- **ltrim** fix in path handling

---

## [v0.6.0]

### Added
- **Content-Security-Policy** meta tag in HTML reports — restricts scripts, styles, and external resources to prevent XSS in report files
- **New legitimate suffixes** in `SpecificClassInjection`: `Pool`, `Logger`, and `Config` suffixes are no longer flagged as concrete class injections
- **Expanded `Classes::BASIC_TYPES`** — now includes `object`, `callable`, `iterable`, `void`, `never`, `self`, `static`, `parent`, `true`, `false`, PHP standard classes (`DateTime`, `DateTimeImmutable`, `Closure`, `stdClass`, `JsonSerializable`), and exception types (`Throwable`, `Exception`, `RuntimeException`)
- **New tests** for `SpecificClassInjection`: Pool/Logger/Config suffix handling, PHP standard class detection, nullable basic type handling

### Fixed
- **HtmlReporter**: Added `ENT_SUBSTITUTE` flag to all `htmlspecialchars()` calls — prevents silent data loss on malformed UTF-8 sequences in file paths and messages
- **Classes utility**: Nullable type hints (e.g. `?int`, `?string`) are now properly stripped before basic-type checking, preventing false positives in constructor analysis

### Changed
- **Release workflow** fixes (v0.5.1)

---

## [v0.5.0]

### Changed
- **Release workflow**: Replaced `Notify middleware webhook` step with version-aligned deployment — CLI release now checks middleware readiness and triggers symlink switch via deploy webhook before publishing, ensuring CLI and middleware versions are always in sync

### Fixed
- **UseOfObjectManager**: Metadata now distinguishes `get` vs `create` calls — `injections` values changed from `$propertyName` to `['property' => $propertyName, 'method' => 'get'|'create']` so the middleware fixer can inject a Factory for `create()` calls instead of always generating singleton DI
- **SpecificClassInjection**: Removed `str_contains($className, 'Model')` guard that prevented collection, repository, resource model, and API interface detection when the containing class was outside a `Model` namespace (e.g., controllers, services, helpers)
- **SpecificClassInjection**: Collection check now inspects the injected parameter class instead of the containing class, fixing misclassification of collections as resource models
- **SpecificClassInjection**: Added `$shouldCheckModel` cascade to prevent double-detection (collections/repositories in `ResourceModel` namespace no longer also flagged as resource model injections)
- **SpecificClassInjection**: Legitimate resource model injections (in repositories, in other resource models) no longer fall through to the generic `specificClassInjection` rule

---

## [v0.4.0]

### Added
- **3 new processors** (19 total):
  - **CollectionInLoop**: Detects N+1 query patterns (model/repository loading inside loops)
  - **CountOnCollection**: Detects `count()` on collections instead of `getSize()`
  - **DiAreaScope**: Detects plugins/preferences in global `di.xml` targeting area-specific classes
- **6 new utility classes** for shared logic across processors:
  - **`DiScope`**: DI scope detection, XML loading, and class area detection
  - **`Types`**: Type checking helpers (`isCollectionType`, `isRepository`, `isResourceModel`, `hasApiInterface`, etc.)
  - **`Modules`**: Module extraction, file grouping, and `di.xml` lookup
  - **`Functions`**: Function content extraction and brace block parsing
  - **`Xml`**: Safe XML loading with libxml error suppression
  - Extended **`Classes`** with `findClassDeclarationLine`, `isFactoryClass`, `isCommandClass`, `derivePropertyName`
  - Extended **`Content`** with `removeComments`, `findApproximateLine`
- **Console Command tests** (ScanTest, FixApplyTest, AuthTest, ActivateSelfSignedTest)
- **Unit tests** for all new processors and utilities (CollectionInLoop, CountOnCollection, DiAreaScope, DiScope, Types, Modules, Functions, Classes)
- Expanded tests for existing processors (Helpers, NoProxyInCommands, Preferences, ProxyForHeavyClasses, SpecificClassInjection, UseOfObjectManager, UseOfRegistry)

### Changed
- **Preferences processor** now scope-aware: duplicate preferences in different scopes (e.g., `frontend/di.xml` vs `adminhtml/di.xml`) are no longer flagged as conflicts
- **Refactored 10 processors** to use shared utility classes instead of inline logic (Types, Modules, Content, Functions, Classes)
- **Moved support classes** from `src/Support/` to `src/Service/` (`Env`, `Paths`, `ProjectIdentifier`)
- **Constructor injection** in all Console Commands (Scan, FixApply) replacing static instantiation — enables mocking in tests
- **Scanner** now uses injected dependency instead of hardcoded `Api` instantiation
- **Code coverage** increased from 77% to 92.82% (2521/2716 lines), 750 tests
- Excluded untestable infrastructure files from coverage (`Api.php`, `Env.php`, `Auth.php`)
- **Psalm 5** static analysis integrated:
  - Fixed redundant casts, missing return paths, dead code, docblock mismatches
  - Configured `psalm.xml` with issue handlers for auto-discovered classes and runtime constants
- **Developer Guide** documentation:
  - [Writing Processors](docs/developer-guide/processors.md) — architecture, step-by-step guide, best practices, testing
  - [Utilities Reference](docs/developer-guide/utilities.md) — all 8 utility classes with method signatures and examples

### Fixed
- **AuthTest** no longer writes test credentials to real config directory (uses temp `XDG_CONFIG_HOME`)

### Removed
- `src/Support/` namespace (replaced by `src/Service/`)

---

## [0.3.0] - 2026-02-09

### Added
- **HTML report format** (`--format=html`):
  - Self-contained single-file dashboard with inline CSS
  - Color-coded summary cards (Total, Errors, Warnings, Notes)
  - Collapsible rule sections with severity badges and file tables
  - **Interactive filtering**: click any summary card to filter rules by severity
  - Print-to-PDF support with `@media print` styles (4 cards on one row, all rules expanded)
- **GitHub Pages documentation site**:
  - Custom layout with responsive navigation and mobile hamburger menu
  - Dark teal (`#142d37`) header/footer matching report branding
  - Deployed via `deploy-docs.yml` GitHub Actions workflow
  - "Buy Fixer Credits" CTA link in navigation
- **17 new unit test files** covering Args, Filenames, HtmlReporter, ExternalToolMapping, Scanner, ClassToProxy, CliWriter, Paths, Version, and multiple processors (AroundPlugins, AdvancedBlockVsViewModel, PaymentInterfaceUseAudit, SpecificClassInjection, Classes, Content, Formater, Functions)

### Changed
- `SpecificClassInjection` processor refactored: inlined private methods (`addGenericClassWarning`, `addModelWithInterfaceError`, `addResourceModelError`, `guessInterfaceName`, `printResults`), reduced duplicate `Classes::getChildren()` calls per parameter
- **Documentation overhaul** across 16 files:
  - Added summary table to processors.md with all 16 rules at a glance
  - Added table of contents to cli-usage.md, request-pr.md, github-actions.md, and fixtures README
  - Fixed exit code capture bug in all 7 CI/CD platform docs (`EXIT_CODE=$?` replaced with `|| EXIT_CODE=$?` pattern for `set -e` compatibility)
  - Fixed `upload-sarif` action version inconsistency (standardized to `@v4`)
  - Replaced 45-line paid PR workflow YAML in README with concise summary + link
  - Reorganized fixtures README from session-based to category-based headings
  - Added breadcrumb navigation to all CI/CD platform docs
  - Removed broken Jenkins "Fail on Errors" example (kept correct `returnStatus` approach)
  - Fixed PR template markdown formatting

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

## [0.0.3] - [0.0.6] - 2024-09-25 to 2024-10-02

No user-facing changes.

## [0.0.2] - 2024-09-25

### Added
- Initial release of EasyAudit CLI