# Available Processors

EasyAudit includes **21 static analysis processors** detecting **37 rules** across Magento 2 codebases. Each processor outputs findings with appropriate severity levels (`error`, `warning`, or `note`) and provides actionable recommendations.

## Summary

| Processor | Category | Severity | Rules | Description |
|-----------|----------|----------|-------|-------------|
| [AroundPlugins](#aroundplugins) | DI | Warning/Note | 4 | Around plugins convertible to before/after or overrides |
| [SameModulePlugins](#samemoduleplugins) | DI | Warning | 1 | Plugins targeting classes in the same module |
| [MagentoFrameworkPlugin](#magentoframeworkplugin) | DI | Warning | 1 | Plugins on Magento Framework classes |
| [NoProxyInCommands](#noproxyincommands) | DI | Warning | 1 | Console commands without proxy for heavy deps |
| [Preferences](#preferences) | DI | Error | 1 | Multiple preferences for the same interface |
| [ProxyForHeavyClasses](#proxyforheavyclasses) | DI | Warning | 1 | Heavy classes injected without proxies |
| [DiAreaScope](#diareascope) | DI | Note | 1 | Plugins/preferences in global di.xml for area-specific classes |
| [SpecificClassInjection](#specificclassinjection) | DI | Error/Warning | 6 | Concrete class injection instead of interfaces/factories |
| [HardWrittenSQL](#hardwrittensql) | Code Quality | Error/Warning | 5 | Raw SQL queries in PHP code |
| [UseOfObjectManager](#useofobjectmanager) | Code Quality | Error/Note | 2 | Direct ObjectManager usage |
| [UseOfRegistry](#useofregistry) | Code Quality | Warning | 1 | Deprecated Registry usage |
| [PaymentInterfaceUseAudit](#paymentinterfaceuseaudit) | Code Quality | Error | 1 | Deprecated payment method implementations |
| [Cacheable](#cacheable) | Templates | Warning | 1 | Blocks with `cacheable="false"` in layout XML |
| [AdvancedBlockVsViewModel](#advancedblockvsviewmodel) | Templates | Warning/Note | 2 | `$this` usage and data processing in phtml |
| [Helpers](#helpers) | Templates | Warning | 2 | Deprecated Helper patterns |
| [InlineStyles](#inlinestyles) | Templates | Low/Medium | 2 | Inline `style` attributes and `<style>` blocks in phtml |
| [DeprecatedEscaperUsage](#deprecatedescaperusage) | Templates | Warning/Error | 1 | Deprecated escape methods on $block/$this |
| [CollectionInLoop](#collectioninloop) | Performance | Warning | 1 | N+1 queries: model/repository loading inside loops |
| [CountOnCollection](#countoncollection) | Performance | Warning | 1 | count() on collections instead of getSize() |
| [BlockViewModelRatio](#blockviewmodelratio) | Architecture | Note | 1 | High block-to-viewmodel ratio per module |
| [UnusedModules](#unusedmodules) | Architecture | Note | 1 | Modules present but disabled in config.php |

---

## Dependency Injection (DI) Analysis

### AroundPlugins
Classifies around plugins and detects deep plugin stacks.

- **Severity**: Warning / Note
- **Rules**:
  - `aroundToBeforePlugin` — Around plugin where all logic precedes `$proceed`. Can be converted to a lighter before plugin.
  - `aroundToAfterPlugin` — Around plugin where all logic follows `$proceed`. Can be converted to a lighter after plugin.
  - `overrideNotPlugin` — Around plugin that never calls `$proceed`, completely replacing the method. Should be a preference instead.
  - `deepPluginStack` — Multiple around plugins on the same method. Each wraps the next, multiplying overhead and making debugging difficult.
- **Why it matters**: Around plugins wrap the entire call chain and add overhead on every invocation. Stacked around plugins compound this: benchmarks show wall-time increases over 13,000% and memory overhead exceeding 62,000% compared to equivalent before/after implementations.

### SameModulePlugins
Detects plugins targeting classes in the same module.

- **Severity**: Warning
- **Why it matters**: Plugins add interceptor overhead that is justified for cross-module extension. Within the same module, a preference or direct class modification is simpler, avoids that overhead, and is easier to trace during debugging.

### MagentoFrameworkPlugin
Detects plugins on Magento Framework classes.

- **Severity**: Warning
- **Why it matters**: Framework classes are instantiated on every request across all areas. An interceptor on any of them runs in the critical path unconditionally, increasing chain depth for the entire platform. Internal method signatures at this level can also change between minor releases.

### NoProxyInCommands
Detects console commands without proxy usage for heavy dependencies.

- **Severity**: Warning
- **Why it matters**: Every registered command is instantiated during CLI bootstrap, even for unrelated commands and all cron runs. Without proxies, all constructor dependencies are built eagerly, producing significant and unnecessary memory overhead on every CLI invocation.

### Preferences
Detects multiple preferences for the same interface/class across modules.

- **Severity**: Error
- **Rules**:
  - `duplicatePreferences` — Multiple di.xml preferences targeting the same interface or class. Only one wins at runtime, determined by module load order.
- **Why it matters**: Module load order is not guaranteed to be stable across environments. Adding, removing, or reordering any module can silently change the active implementation, creating non-deterministic behavior that is hard to reproduce and debug.

### ProxyForHeavyClasses
Detects heavy classes (Session, Collection, ResourceModel) injected without proxies.

- **Severity**: Warning
- **Rules**:
  - `noProxyUsedForHeavyClasses` — Heavy classes trigger database connections, configuration reads, or session initialization at construction time. Without a proxy, this cost is paid even when the dependency is never used.
- **Why it matters**: On widely instantiated parent classes, the cascading effect on memory and initialization time is substantial and entirely avoidable with proxies.

### DiAreaScope
Detects plugins and preferences in global `di.xml` that target area-specific classes.

- **Severity**: Note
- **Why it matters**: Global DI configuration is compiled and loaded for every request type. Area-specific interceptors declared globally inflate the DI graph and add objects to the instantiation surface of requests that have no use for them (e.g., frontend plugins loaded during cron or REST API calls).

### SpecificClassInjection
Detects concrete class injection where interfaces or factories should be used.

- **Severity**: Error / Warning
- **Rules**:
  - `collectionMustUseFactory` (Error) — Collection injected directly instead of through its Factory. Causes eager instantiation and prevents fresh queries.
  - `repositoryMustUseInterface` (Error) — Repository typed to concrete class instead of its interface. Breaks DI preferences and prevents mocking.
  - `modelUseApiInterface` (Error) — Model injected as concrete class when an API data interface is available. Bypasses the data contract abstraction.
  - `noResourceModelInjection` (Warning) — Resource model injected directly. Couples business logic to the persistence layer, bypassing the repository pattern.
  - `statefulModelInjection` (Warning) — Class extending AbstractModel injected directly. Shared mutable state causes stale data and side effects.
  - `specificClassInjection` (Warning) — Generic concrete class injection where an interface or factory would be more appropriate. May have false positives — verify manually.
- **Why it matters**: Concrete injection tightly couples dependent classes to specific implementations, reducing substitutability and making unit testing significantly harder. When concrete classes accumulate across a codebase, the cost of any refactoring increases.

---

## Code Quality

### HardWrittenSQL
Detects raw SQL queries in PHP code.

- **Severity**: Error / Warning
- **Rules**:
  - `hard-written-sql-select` (Error) — Raw SELECT queries bypass parameter binding and the data abstraction layer, creating SQL injection risk.
  - `hard-written-sql-delete` (Error) — Raw DELETE queries bypass the event system and referential integrity checks.
  - `hard-written-sql-insert` (Warning) — Raw INSERT queries bypass validation, event dispatch, and indexing triggers.
  - `hard-written-sql-update` (Warning) — Raw UPDATE queries bypass the event system and can silently overwrite data without triggering reindexing.
  - `hard-written-sql-join` (Note) — Raw JOIN queries hardcode table relationships and are fragile across schema changes.
- **Why it matters**: Raw SQL bypasses parameter binding, making it a direct SQL injection vector. It is also fragile across schema changes, incompatible with read/write connection splitting, and invisible to Magento's query profiling tools.

### UseOfObjectManager
Detects direct ObjectManager usage.

- **Severity**: Error / Note
- **Rules**:
  - `replaceObjectManager` (Error) — Direct `ObjectManager::getInstance()` or `$objectManager->get()/create()` calls. Hides dependencies from the DI graph and makes the class untestable.
  - `useless-object-manager-import` (Note) — ObjectManager imported but never used. Dead import that adds noise and may confuse developers.
- **Why it matters**: Direct ObjectManager usage bypasses constructor injection, proxy and preference configurations, and makes it impossible to trace the full dependency graph statically.

### UseOfRegistry
Detects deprecated Registry usage.

- **Severity**: Warning
- **Rules**:
  - `use-of-registry` — Usage of the deprecated `\Magento\Framework\Registry` class. Global mutable store with no scoping or type safety.
- **Why it matters**: Registry creates hidden coupling between unrelated classes, makes execution order sensitive, and breaks test isolation. It was designed as a temporary compatibility bridge from Magento 1 and has no place in production code.

### PaymentInterfaceUseAudit
Detects deprecated payment method implementations.

- **Severity**: Error
- **Rules**:
  - `extensionOfAbstractMethod` — Payment methods extending the deprecated `\Magento\Payment\Model\Method\AbstractMethod`.
- **Why it matters**: AbstractMethod is officially deprecated by Adobe and scheduled for removal. A broken payment flow at upgrade time has immediate and severe business impact. Modern payment methods should use the command pool / gateway adapter pattern.

---

## Template/View Layer

### Cacheable
Detects blocks with `cacheable="false"` in layout XML.

- **Severity**: Warning
- **Rules**:
  - `useCacheable` — A single `cacheable="false"` anywhere in the layout hierarchy disables Full Page Cache for the entire page, for every visitor.
- **Why it matters**: Every request hits the full application stack and database. At any meaningful traffic level, this is one of the most damaging performance anti-patterns in Magento 2, and it often goes unnoticed because the effect is global rather than localized to the block.

### AdvancedBlockVsViewModel
Detects `$this` usage and excessive data processing in phtml templates.

- **Severity**: Warning / Note
- **Rules**:
  - `thisToBlock` (Warning) — Template uses `$this` instead of `$block`. Unreliable because the rendering context is not guaranteed to be the Block itself.
  - `dataCrunchInPhtml` (Note) — Template makes excessive data retrieval calls through the Block. Logic should be moved to a ViewModel.
- **Why it matters**: Blocks that accumulate data retrieval logic become tightly coupled to the rendering pipeline, making them hard to unit test and encouraging further responsibility creep.

### Helpers
Detects deprecated Helper patterns.

- **Severity**: Warning
- **Rules**:
  - `extensionOfAbstractHelper` — Helper class extends `\Magento\Framework\App\Helper\AbstractHelper`. This pattern is deprecated in Magento 2.
  - `helpersInsteadOfViewModels` — Template uses `$this->helper()` to retrieve a Helper. Should be replaced with a ViewModel.
- **Why it matters**: Helpers are instantiated at layout load time as part of the object graph, regardless of whether the template is rendered. They are nearly impossible to mock in unit tests due to their AbstractHelper dependency.

### InlineStyles
Detects inline `style=""` attributes and `<style>` blocks in phtml templates.

- **Severity**: Low / Medium
- **Rules**:
  - `inline-style-attribute` (Low) — Inline `style=""` attributes on HTML elements. Cannot be overridden by child themes.
  - `inline-style-block` (Medium) — Inline `<style>` blocks in templates. Bypass the LESS compilation pipeline.
- **Why it matters**: Inline styles bypass CSS merging/minification, violate Content Security Policy, break the standard Magento theming contract, and prevent proper stylesheet caching by the browser. Email and PDF templates are excluded.

### DeprecatedEscaperUsage
Detects deprecated escape method calls on `$block` or `$this` instead of `$escaper` in phtml templates.

- **Severity**: Warning (`$block->escapeHtml()`), Error (`$this->escapeHtml()`)
- **Why it matters**: Since Magento 2.3.5, `$escaper` is the supported service. Escape methods on `$block` or `$this` create a hard dependency on the Block instance and will break if the rendering context changes. Relying on the deprecated approach accumulates technical debt that will require migration before any major version upgrade.

---

## Performance

### CollectionInLoop
Detects N+1 query patterns: model or repository loading inside loops.

- **Severity**: Warning
- **Why it matters**: Each iteration triggers a separate SQL query. On a list of 100 items, that means 100 database round-trips. Under concurrent traffic this leads to connection pool exhaustion, high database CPU, and degraded response times. N+1 queries scale linearly with dataset size, making them progressively worse as the catalog grows.

### CountOnCollection
Detects `count()` usage on Magento collections instead of `getSize()`.

- **Severity**: Warning
- **Why it matters**: `count($collection)` triggers a full `SELECT *` query, loading all matching records into PHP memory just to produce a single integer. `getSize()` issues a `COUNT(*)` query at the database level and returns only the integer. The performance difference is orders of magnitude on large datasets.

---

## Architecture & Best Practices

### BlockViewModelRatio
Analyzes ratio of Block classes vs total PHP classes per module.

- **Severity**: Note
- **Rules**:
  - `blockViewModelRatio` — Module where Block classes exceed 50% of total PHP classes. Data preparation logic has accumulated in a layer coupled to rendering.
- **Why it matters**: Blocks are tied to layout XML and the rendering lifecycle. The higher the ratio, the harder the module becomes to test, refactor, and extend. Business logic buried in Blocks cannot be shared across API endpoints or CLI commands.

### UnusedModules
Detects modules present in codebase but disabled in `config.php`.

- **Severity**: Note
- **Rules**:
  - `unusedModules` — Modules disabled in `app/etc/config.php` that still exist on disk.
- **Why it matters**: Disabled modules remain indexed by the autoloader, can be re-enabled accidentally through `setup:upgrade`, and create confusion about what code is actually active. Over time they fall out of sync with the rest of the codebase, making re-enabling them risky.

---

## Severity Levels

| Level | Meaning | CI Behavior |
|-------|---------|-------------|
| `error` | Critical violation | Should block merges |
| `warning` | Important issue | Non-blocking |
| `note` | Informational | Non-blocking |

---

[Back to README](../README.md)
