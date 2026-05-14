# Extension Points

EasyAudit CLI is intentionally extensible without forking the core entry point. This guide documents the extension surface introduced in 1.3.0:

- [Reporter registry](#reporter-registry)
- [HTML reporter long-description hook](#html-reporter-long-description-hook)
- [Glossary concept annotations](#glossary-concept-annotations)
- [Fixer backend (`FixerInterface`)](#fixer-backend-fixerinterface)
- [Command and fixer registry](#command-and-fixer-registry)

All extension points are wired through `EasyAudit\Service\Config` (`src/Service/Config.php`), which loads a single JSON file (`config/easyaudit.json` in the free repo). Sponsor builds point `EA_CONFIG` at an overlay to register extras without editing core files.

---

## Reporter registry

**File:** `config/easyaudit.json`

```json
{
  "reporters": {
    "html":  "EasyAudit\\Core\\Report\\HtmlReporter",
    "sarif": "EasyAudit\\Core\\Report\\SarifReporter",
    "json":  "EasyAudit\\Core\\Report\\JsonReporter"
  },
  "defaultFormat": "html"
}
```

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

## HTML reporter long-description hook

**File:** `src/Core/Report/HtmlReporter.php`

```php
protected function renderLongDescription(string $escapedText, array $rule): string
{
    return $escapedText;
}
```

`HtmlReporter::formatDescription()` calls this hook for every paragraph of a rule's long description, **after** `htmlspecialchars()` has already been applied. The default implementation is a no-op.

Sponsor's `GlossaryHtmlReporter` overrides it to wrap glossary terms in `<abbr>` tooltips. The escape-then-inject ordering is mandatory — overriders must only emit safe markup over text that is already HTML-escaped, never the other way around.

Minimal override example:

```php
final class GlossaryHtmlReporter extends \EasyAudit\Core\Report\HtmlReporter
{
    protected function renderLongDescription(string $escapedText, array $rule): string
    {
        foreach ($rule['concepts'] ?? [] as $slug) {
            $term = $this->glossary[$slug]['term'] ?? null;
            if ($term !== null) {
                $escapedText = str_ireplace(
                    htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    '<abbr title="' . htmlspecialchars($this->glossary[$slug]['shortDefinition'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</abbr>',
                    $escapedText
                );
            }
        }
        return $escapedText;
    }
}
```

To activate the override, register the class in the reporter registry as shown above.

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

## Fixer backend (`FixerInterface`)

**File:** `src/Service/FixerInterface.php`

```php
interface FixerInterface
{
    public function requestFilefix(
        string $filePath,
        string $content,
        array $rules,
        string $projectId,
        string $format = 'git'
    ): array;

    /** @return array<string, int|true> */
    public function getAllowedType(): array;

    /** @return array{credits: int, credit_expiration_date?: ?string, licence_expiration_date?: ?string, project_id?: string}|null */
    public function getRemainingCredits(string $projectId): ?array;
}
```

`FixApply` depends on this interface, not on `Service\Api` directly. The default implementation is `Api` (remote, credit-aware). To plug in a local/offline fixer:

1. Implement the three methods.
2. Return `null` from `getRemainingCredits()` to signal that this backend doesn't track credits — `FixApply` will then skip credit prompts, the "Credits remaining" line, and the post-run real-cost summary.
3. Have `getAllowedType()` return `[ruleName => true]` (instead of an `int` credit cost) for rules the backend can handle locally.
4. Return a `['diff' => $unifiedDiff]` array from `requestFilefix()`.

Stub example:

```php
final class LocalCsRunner implements FixerInterface
{
    public function requestFilefix(string $filePath, string $content, array $rules, string $projectId, string $format = 'git'): array
    {
        $patched = $this->applyLocalCs($content, $rules);
        return ['diff' => $this->makeDiff($filePath, $content, $patched)];
    }

    public function getAllowedType(): array
    {
        return ['inlineStyles' => true, 'deprecatedEscaperUsage' => true];
    }

    public function getRemainingCredits(string $projectId): ?array
    {
        return null; // local fixer, no credits
    }
}
```

To activate the override, register the class as the fixer in the registry (next section).

---

## Command and fixer registry

**File:** `bin/easyaudit`

The entry point reads its command list and fixer FQCN from `Service\Config::load()`:

```php
$config = Config::load();
$commandClasses = $config['commands'] ?? [
    'scan'                 => Scan::class,
    'auth'                 => Auth::class,
    'fix-apply'            => FixApply::class,
    'activate-self-signed' => ActivateSelfSigned::class,
];
$fixerClass = $config['fixer'] ?? Api::class;
```

To register an additional command or swap the fixer, point `EA_CONFIG` at an overlay JSON file:

```json
{
  "reporters": { "...": "..." },
  "defaultFormat": "html",
  "commands": {
    "scan":            "EasyAudit\\Console\\Command\\Scan",
    "auth":            "EasyAudit\\Console\\Command\\Auth",
    "fix-apply":       "EasyAudit\\Console\\Command\\FixApply",
    "checkout-audit":  "Vendor\\EasyAuditExtras\\Console\\Command\\CheckoutAudit"
  },
  "fixer": "Vendor\\EasyAuditExtras\\Service\\LocalCsRunner"
}
```

Custom commands must implement `EasyAudit\Console\CommandInterface`. The entry point auto-injects dependencies for the built-in `Scan` and `FixApply` classes (and any subclass thereof); custom commands receive no arguments and should resolve their own dependencies inside the constructor.

---

## Related docs

- [Writing Processors](processors.md)
- [Utilities Reference](utilities.md) — including `PluginRegistry`, `Paths`, and the multi-location finding convention used by SARIF output.
