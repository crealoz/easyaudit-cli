# Available Processors

EasyAudit includes **20 static analysis processors** for Magento 2 codebases. Each processor outputs findings with appropriate severity levels (`error`, `warning`, or `note`) and provides actionable recommendations.

## Summary

| Processor | Category | Severity | Description |
|-----------|----------|----------|-------------|
| [SameModulePlugins](#samemoduleplugins) | DI | Warning | Plugins targeting classes in the same module |
| [MagentoFrameworkPlugin](#magentoframeworkplugin) | DI | Warning | Plugins on Magento Framework classes |
| [AroundPlugins](#aroundplugins) | DI | Note | Around plugins replaceable with before/after |
| [NoProxyInCommands](#noproxyincommands) | DI | Warning | Console commands without proxy for heavy deps |
| [Preferences](#preferences) | DI | Error | Multiple preferences for the same interface |
| [ProxyForHeavyClasses](#proxyforheavyclasses) | DI | Warning | Heavy classes injected without proxies |
| [DiAreaScope](#diareascope) | DI | Note | Plugins/preferences in global di.xml for area-specific classes |
| [HardWrittenSQL](#hardwrittensql) | Code Quality | Error | Raw SQL queries in PHP code |
| [UseOfRegistry](#useofregistry) | Code Quality | Warning | Deprecated Registry usage |
| [UseOfObjectManager](#useofobjectmanager) | Code Quality | Error | Direct ObjectManager usage |
| [SpecificClassInjection](#specificclassinjection) | Code Quality | Warning | Concrete class injection instead of interfaces |
| [PaymentInterfaceUseAudit](#paymentinterfaceuseaudit) | Code Quality | Error | Deprecated payment method implementations |
| [Cacheable](#cacheable) | Templates | Warning | Blocks with `cacheable="false"` in layout XML |
| [AdvancedBlockVsViewModel](#advancedblockvsviewmodel) | Templates | Note | `$this` usage and data processing in phtml |
| [Helpers](#helpers) | Templates | Warning | Deprecated Helper patterns |
| [DeprecatedEscaperUsage](#deprecatedescaperusage) | Templates | Warning/Error | Deprecated escape methods on $block/$this |
| [CollectionInLoop](#collectioninloop) | Performance | Warning | N+1 queries: model/repository loading inside loops |
| [CountOnCollection](#countoncollection) | Performance | Warning | count() on collections instead of getSize() |
| [BlockViewModelRatio](#blockviewmodelratio) | Architecture | Note | High block-to-viewmodel ratio per module |
| [UnusedModules](#unusedmodules) | Architecture | Note | Modules present but disabled in config.php |

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

### DiAreaScope
Detects plugins and preferences in global `di.xml` that target area-specific classes.

- **Severity**: Note
- **Why it matters**: Plugins/preferences on frontend blocks or admin controllers declared in global `etc/di.xml` are loaded for every area (frontend, adminhtml, cron, REST API). Moving them to the appropriate area `di.xml` reduces DI compilation footprint and improves performance.

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

### DeprecatedEscaperUsage
Detects deprecated escape method calls on `$block` or `$this` instead of `$escaper` in phtml templates.

- **Severity**: Warning (`$block->escapeHtml()`), Error (`$this->escapeHtml()`)
- **Why it matters**: Since Magento 2.3.5, escape methods (`escapeHtml`, `escapeUrl`, `escapeJs`, `escapeHtmlAttr`, `escapeCss`, `escapeQuote`) should be called on the `$escaper` variable. Using `$block->escape*()` is deprecated, and `$this->escape*()` combines the deprecated escaper pattern with the `$this` anti-pattern.
- **Detection**: Regex matching `$block->escape*()` and `$this->escape*()` calls in `.phtml` files
- **Fix**: Replace `$block->escapeHtml($value)` and `$this->escapeHtml($value)` with `$escaper->escapeHtml($value)`

---

## Performance

### CollectionInLoop
Detects N+1 query patterns: model or repository loading inside loops.

- **Severity**: Warning
- **Why it matters**: Loading models one by one inside loops (`->load()`, `->getById()`, `->getFirstItem()`) causes N+1 queries, one of the most common performance killers. Use `getList()` with search criteria or a filtered collection to batch-load entities before the loop.

### CountOnCollection
Detects `count()` usage on Magento collections instead of `getSize()`.

- **Severity**: Warning
- **Why it matters**: `count($collection)` and `$collection->count()` load all items from the database into memory. Use `$collection->getSize()` which executes a `COUNT(*)` SQL query without loading items.

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