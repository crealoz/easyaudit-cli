# Extension Points

EasyAudit CLI is intentionally extensible without forking the core entry point. This guide documents the extension surface introduced in 1.3.0:

- [Reporter registry](#reporter-registry)
- [Glossary concept annotations](#glossary-concept-annotations)
- [Command registry](#command-registry)
- [Processor directories](#processor-directories)

All extension points are wired through `EasyAudit\Service\Config` (`src/Service/Config.php`), which loads a single JSON file (`config/easyaudit.json` in the free repo). Sponsor builds point `EA_CONFIG` at an overlay to register extras without editing core files.

---

## Reporter registry

**File:** `config/easyaudit.json`

```json
{
  "reporters": {
    "sarif": "EasyAudit\\Core\\Report\\SarifReporter"
  },
  "defaultFormat": "sarif"
}
```

The free edition ships `SarifReporter` only. Automatic fixing (`fix-apply`) and the HTML, Markdown and JSON reports are part of the [sponsors edition](https://github.com/sponsors/crealoz).

`Service\Config::load()` reads this file, validates that every FQCN in `reporters` implements `EasyAudit\Core\Report\ReporterInterface`, and caches the result for the remainder of the process. `Console\Command\Scan` reads the cached map at runtime and instantiates `new $class()` — there is no hardcoded `match($format)` block anywhere.

**Adding a new reporter:**

1. Implement `EasyAudit\Core\Report\ReporterInterface`:
   ```php
   namespace Vendor\EasyAuditExtras\Report;

   use EasyAudit\Core\Report\ReporterInterface;

   final class MdReporter implements ReporterInterface
   {
       public function generate(array $report): string { /* … */ }
   }
   ```
2. Make sure the class is on the autoload path (a Composer dependency, or the PSR-4 source tree for PHAR builds).
3. Add one line to `config/easyaudit.json` (or to your overlay pointed at by `EA_CONFIG`):
   ```json
   "md": "Vendor\\EasyAuditExtras\\Report\\MdReporter"
   ```

The allowed `--format` values, the default format, and the `scan --help` text are all derived from the loaded map — no further changes to `Scan.php` are required.

---

## Glossary concept annotations

**Data:** `data/{lang}/glossary.json`
**Loader:** `src/Core/Glossary/GlossaryLoader.php`

The free repo ships `data/en/glossary.json` only. `GlossaryLoader::load(string $language)` returns the concepts indexed by slug. If the requested language is unavailable, it falls back to `GlossaryLoader::FALLBACK_LANGUAGE` (`'en'`); only when `en` itself is missing does it throw `LanguageNotAvailableException`.

Each concept entry:

```json
{
  "factory_pattern": {
    "term": "Factory pattern",
    "shortDefinition": "Magento generates a Factory companion for any class…",
    "links": ["https://devdocs.magento.com/…"],
    "excludeFromAutoLink": false
  }
}
```

The free repo emits `concepts` annotations on rule output but does not consume them — sponsor reporters (MD, glossary HTML) pick them up via upstream sync.

### Three ways to annotate a rule with concepts

**1. Single-rule processor (default `getReport()`):** override the `CONCEPTS` constant on `AbstractProcessor`.

```php
final class CountOnCollection extends AbstractProcessor
{
    protected const CONCEPTS = ['repository_pattern', 'search_criteria'];
}
```

The default `AbstractProcessor::getReport()` projects `static::CONCEPTS` into the report payload automatically.

**2. Multi-rule processor with a `RULE_CONFIGS` array:** add a `concepts` key per rule entry.

```php
// src/Core/Scan/Processor/SpecificClassInjection.php
private const RULE_CONFIGS = [
    'collection' => [
        'ruleId'   => 'collectionMustUseFactory',
        'concepts' => ['factory_pattern'],
        // …
    ],
    'repository' => [
        'ruleId'   => 'repositoryMustUseInterface',
        'concepts' => ['repository_pattern', 'di_preference'],
        // …
    ],
];
```

The processor's custom `getReport()` reads the `concepts` key and emits it alongside each rule's `ruleId`.

**3. Multi-rule processor with a hand-built report:** keep a per-`ruleId` map and emit it explicitly.

```php
// src/Core/Scan/Processor/AroundPlugins.php
private const RULE_CONCEPTS = [
    'aroundToBeforePlugin' => ['around_plugin', 'interceptor'],
    'aroundToAfterPlugin'  => ['around_plugin', 'interceptor'],
    'overrideNotPlugin'    => ['around_plugin', 'di_preference', 'interceptor'],
    'deepPluginStack'      => ['around_plugin', 'interceptor'],
];

// then inside getReport():
'concepts' => self::RULE_CONCEPTS[$ruleId],
```

### Safety net

`tests/Unit/Core/Glossary/GlossaryIntegrityTest` walks every processor by reflection and asserts that every referenced concept slug exists in `data/en/glossary.json`. Add a concept slug that is not defined and CI fails.

---

## Command registry

**File:** `bin/easyaudit`

The entry point reads its command list from `Service\Config::load()`:

```php
$config = Config::load();
$commandClasses = $config['commands'] ?? [
    'scan' => Scan::class,
];
```

To register an additional command, point `EA_CONFIG` at an overlay JSON file:

```json
{
  "reporters": { "...": "..." },
  "defaultFormat": "sarif",
  "commands": {
    "scan":            "EasyAudit\\Console\\Command\\Scan",
    "checkout-audit":  "Vendor\\EasyAuditExtras\\Console\\Command\\CheckoutAudit"
  }
}
```

Custom commands must implement `EasyAudit\Console\CommandInterface`. The entry point injects a `Scanner` into the built-in `Scan` class (and any subclass thereof); custom commands receive no arguments and should resolve their own dependencies inside the constructor.

The config also accepts an optional `fixer` key holding a class name. `Service\Config` only checks that the class exists; the free edition has no fixer and does not use the value.

---

## Processor directories

**File:** `config/easyaudit.json`, key `processorDirs`

Tells `Scanner::getProcessors()` which `namespace → directory` pairs to scan for processor classes. Sponsors can register additional processor namespaces — or replace the built-in set entirely — without subclassing `Scanner`.

```json
{
  "processorDirs": {
    "EasyAudit\\Core\\Scan\\Processor":             "../src/Core/Scan/Processor",
    "Vendor\\EasyAuditExtras\\Processor":           "../sponsor/Processor"
  }
}
```

### Semantics

- **Replace, not extend.** When `processorDirs` is present and non-empty, it fully replaces the built-in default. A sponsor overlay that wants to keep the core processors must list the `EasyAudit\\Core\\Scan\\Processor` entry explicitly (as above).
- **Empty map falls back to default.** The shipped `config/easyaudit.json` ships with `"processorDirs": {}` as a discoverability placeholder; the in-code default (`EasyAudit\Core\Scan\Processor` → the built-in directory) is what runs until the map is populated. An entirely absent key behaves the same way.
- **Path resolution.** Absolute paths and stream-wrapped paths (`phar://`, `file://`) are kept verbatim. Relative paths resolve against the directory containing the loaded config file (i.e. `dirname($configPath)`), so sponsor overlays can use paths relative to their own location.
- **Validation is shape-only.** `Service\Config` rejects non-string keys/values and empty strings, but does **not** check that the directory exists on disk — sponsor overlays may list paths that only exist in some build flavors. `Scanner` silently skips a missing directory at scan time.

### Class discovery

For each entry, `Scanner` does a flat `scandir()` on the directory and constructs class names as `rtrim($namespace, '\\') . '\\' . pathinfo($file, PATHINFO_FILENAME)`. The class must be reachable via Composer's autoloader (or any registered SPL autoloader) — `Scanner` calls `class_exists()` and uses whatever loader can resolve the name. There is no recursive descent and no glob support; one namespace per flat directory.

Sponsor processor classes must:
1. Implement `EasyAudit\Core\Scan\ProcessorInterface` (typically by extending `AbstractProcessor`).
2. Be autoloadable — add a `psr-4` entry to your `composer.json`, e.g.:
   ```json
   "autoload": {
     "psr-4": {
       "Vendor\\EasyAuditExtras\\": "src/"
     }
   }
   ```
3. Be instantiable with no constructor arguments. Classes that throw during construction are silently skipped (same tolerance as the built-in directory).

---

## Related docs

- [Writing Processors](processors.md)
- [Utilities Reference](utilities.md) — including `PluginRegistry`, `Paths`, and the multi-location finding convention used by SARIF output.
