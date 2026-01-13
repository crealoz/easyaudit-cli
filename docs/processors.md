# Available Processors

EasyAudit includes **16 static analysis processors** for Magento 2 codebases. Each processor outputs findings with appropriate severity levels (`error`, `warning`, or `note`) and provides actionable recommendations.

---

## Dependency Injection (DI) Analysis

### SameModulePlugins
Detects plugins targeting classes in the same module.

- **Severity**: Warning
- **Why it matters**: Plugins on same-module classes are an anti-pattern. Use preferences or extend the class directly instead.

### MagentoFrameworkPlugin
Detects plugins on Magento Framework classes.

- **Severity**: Warning
- **Why it matters**: Plugins on core framework classes cause performance overhead and can break during upgrades.

### AroundPlugins
Classifies around plugins as before/after replaceable or true overrides.

- **Severity**: Note
- **Why it matters**: Many around plugins can be replaced with simpler before/after plugins, improving performance.

### NoProxyInCommands
Detects console commands without proxy usage for heavy dependencies.

- **Severity**: Warning
- **Why it matters**: Console commands should use proxies for heavy classes to avoid loading unnecessary dependencies.

### Preferences
Detects multiple preferences for the same interface/class.

- **Severity**: Error
- **Why it matters**: Multiple preferences for the same target cause unpredictable behavior.

### ProxyForHeavyClasses
Detects heavy classes (Session, Collection, ResourceModel) injected without proxies.

- **Severity**: Warning
- **Why it matters**: Heavy classes should use proxies to defer instantiation and improve performance.

---

## Code Quality

### HardWrittenSQL
Detects raw SQL queries in PHP code.

- **Severity**: Error
- **Why it matters**: Raw SQL is a security risk (SQL injection) and bypasses Magento's database abstraction.

### UseOfRegistry
Detects deprecated Registry usage.

- **Severity**: Warning
- **Why it matters**: Registry is deprecated since Magento 2.3. Use dependency injection instead.

### UseOfObjectManager
Detects direct ObjectManager usage.

- **Severity**: Error
- **Why it matters**: Direct ObjectManager usage breaks dependency injection and makes code untestable.

### SpecificClassInjection
Detects injection of specific classes instead of interfaces.

- **Severity**: Warning
- **Why it matters**: Injecting concrete classes instead of interfaces limits flexibility and testability.
- **Special rules**:
  - `collectionWithChildrenMustUseFactory` - Collections with child classes need factories
  - `repositoryWithChildrenMustUseInterface` - Repositories with children need interfaces

### PaymentInterfaceUseAudit
Detects deprecated payment method implementations.

- **Severity**: Error
- **Why it matters**: `AbstractMethod` is deprecated. Modern payment methods should implement `PaymentMethodInterface`.

---

## Template/View Layer

### Cacheable
Detects blocks with `cacheable="false"` in layout XML.

- **Severity**: Warning
- **Why it matters**: Disabling cache on blocks impacts full-page cache performance.

### AdvancedBlockVsViewModel
Detects `$this` usage and data processing in phtml templates.

- **Severity**: Note
- **Why it matters**: Templates should use ViewModels for data. Direct `$this` usage couples templates to blocks.

### Helpers
Detects deprecated Helper patterns.

- **Severity**: Warning
- **Why it matters**:
  - Extending `AbstractHelper` is deprecated
  - Using helpers directly in templates (`$this->helper()`) should be replaced with ViewModels

---

## Architecture & Best Practices

### BlockViewModelRatio
Analyzes ratio of Blocks vs ViewModels per module.

- **Severity**: Note
- **Why it matters**: High block-to-viewmodel ratio suggests outdated patterns. ViewModels are preferred.

### UnusedModules
Detects modules present in codebase but disabled in `config.php`.

- **Severity**: Note
- **Why it matters**: Disabled modules should be removed from the codebase to reduce maintenance burden.

---

## Severity Levels

| Level | Meaning | CI Behavior |
|-------|---------|-------------|
| `error` | Critical violation | Should block merges |
| `warning` | Important issue | Non-blocking |
| `note` | Informational | Non-blocking |

---

[Back to README](../README.md)