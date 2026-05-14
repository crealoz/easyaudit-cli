# Utilities Reference

All utilities are static classes in `EasyAudit\Core\Scan\Util\`. They provide shared logic used across processors for parsing, type classification, module detection, and output formatting.

## Classes

**File:** `src/Core/Scan/Util/Classes.php`

Constructor parsing, class hierarchy, import resolution.

| Method | Signature | Description |
|--------|-----------|-------------|
| `parseImportedClasses` | `(string $fileContent): array` | Parse `use` statements into `[shortName => FQCN]` map. Handles aliases (`use Foo as Bar`). |
| `hasImportedClass` | `(string $class, string $fileContent): bool` | Check if a specific FQCN appears in the file's imports. |
| `hasImportedClasses` | `(array $classes, string $fileContent): bool` | Check if any of the given FQCNs are imported. |
| `parseConstructorParameters` | `(string $fileContent): array` | Extract raw constructor parameter strings (e.g., `['private ProductFactory $productFactory']`). |
| `getConstructorParameterTypes` | `(string $fileContent): array` | Resolve constructor params to `[$paramName => $fqcn]`, resolving short names via imports. Skips basic types (`string`, `int`, etc.). |
| `getInstantiation` | `(array $constructorParams, string $trackedParam, string $fileContent): ?string` | Find how a constructor param is stored as a property (`$this->foo`). Handles promoted properties. Throws `InstantiationNotFoundException` if assignment is not found. |
| `consolidateParameters` | `(array $constructorParameters, array $importedClasses): array` | Resolve parameter list against import map into `[$paramName => $fqcn]`. |
| `resolveShortClassName` | `(string $shortName, string $fileContent, string $namespace): string` | Resolve a short class name to FQCN using imports, with namespace fallback. |
| `buildClassHierarchy` | `(array $phpFiles): void` | Build internal parent-child class map from an array of PHP file paths. |
| `getChildren` | `(string $className): array` | Get child classes of a FQCN. Throws `NoChildrenException` if none found. Requires `buildClassHierarchy()` first. |
| `extractClassName` | `(string $fileContent): string` | Extract FQCN (`Namespace\ClassName`) from file content. Returns `'UnknownClass'` on failure. |
| `getParentConstructorParams` | `(string $fileContent): array` | Extract variable names passed to `parent::__construct()`. |
| `resolveClassToFile` | `(string $className): ?string` | Resolve a FQCN to its file path under `EA_SCAN_PATH`. Tries full path, then strips `Vendor\Module` prefix. |
| `extendsClass` | `(string $content, string $fqcn): bool` | Check if a class extends a given FQCN (handles `\FQCN`, imported short name, etc.). |
| `findClassDeclarationLine` | `(string $content, string $parentClassName): int` | Find line number of the `class ... extends ParentClass` declaration. |
| `isFactoryClass` | `(string $fileContent): bool` | Check if the file defines a class whose name ends with `Factory`. |
| `isCommandClass` | `(string $fileContent): bool` | Check if the file defines a Symfony Console Command subclass. |
| `derivePropertyName` | `(string $className): string` | Derive a camelCase property name from a FQCN (e.g., `Magento\...\ProductFactory` -> `productFactory`). |

**Example** (from `SpecificClassInjection`):

```php
$params = Classes::getConstructorParameterTypes($content);
foreach ($params as $paramName => $className) {
    if (Types::isCollectionType($className)) {
        // Flag direct collection injection
    }
}
```

## Content

**File:** `src/Core/Scan/Util/Content.php`

Line number resolution, comment removal, and content extraction.

| Method | Signature | Description |
|--------|-----------|-------------|
| `getLineNumber` | `(string $fileContent, string $searchString): int` | Find the 1-based line number of the first occurrence of a string. Returns `-1` if not found. |
| `extractContent` | `(string $fileContent, int $startLine, int $endLine): string` | Extract content between two 1-based line numbers (inclusive). |
| `removeComments` | `(string $content): string` | Strip block (`/* */`), line (`//`), and hash (`#`) comments from PHP content. |
| `findApproximateLine` | `(string $original, string $needle, int $approxLine, bool $normalizeWhitespace = false): int` | Find the actual line of a needle near an approximate line (searches +/-10 lines). Optionally normalizes whitespace for multi-line matching. |

**Example** (from `HardWrittenSQL`):

```php
$cleanedContent = Content::removeComments($fileContent);
// ... detect pattern in cleaned content, get approximate line ...
$actualLine = Content::findApproximateLine($originalContent, $match, $approxLine, true);
```

## Functions

**File:** `src/Core/Scan/Util/Functions.php`

Function body extraction and brace-block parsing.

| Method | Signature | Description |
|--------|-----------|-------------|
| `getFunctionContent` | `(string $code, int $startLine): array` | Extract a function body starting at a given line. Returns `['content' => string, 'endLine' => int]`. Uses brace counting. |
| `getFunctionInnerContent` | `(string $functionContent): string` | Extract only the inner body of a function (between its opening and closing braces). |
| `getOccuringLineInFunction` | `(string $functionContent, string $search): ?int` | Find the 1-based relative line number of a search string within function content. |
| `extractBraceBlock` | `(string $content, int $offset): ?string` | Extract the inner content of a `{...}` block starting at or after a given offset. Uses brace counting. Returns `null` if no block found. |

**Example** (from `CollectionInLoop`):

```php
// Find loop constructs, then extract their bodies
$loopBody = Functions::extractBraceBlock($cleanedContent, $loopOffset);
if ($loopBody !== null && str_contains($loopBody, '->load(')) {
    // Flag N+1 pattern
}
```

## Types

**File:** `src/Core/Scan/Util/Types.php`

Magento type classification helpers.

| Method | Signature | Description |
|--------|-----------|-------------|
| `isCollectionType` | `(string $className): bool` | True if name contains `Collection` but not `CollectionFactory`. |
| `isCollectionFactoryType` | `(string $className): bool` | True if name contains `CollectionFactory`. |
| `isRepository` | `(string $className): bool` | True if name contains `Repository`. |
| `isResourceModel` | `(string $className): bool` | True if name contains `ResourceModel`. |
| `isNonMagentoLibrary` | `(string $className): bool` | True if the class belongs to a known third-party vendor (GuzzleHttp, Symfony, Laminas, etc.). |
| `hasApiInterface` | `(string $className): bool` | True if the class/interface implements an interface containing `Api` in its name. Uses reflection. |
| `getApiInterface` | `(string $className): string` | Return the Api interface name for a class (via reflection), or derive one by convention. |
| `matchesSuffix` | `(string $className, array $suffixes): bool` | True if the class name ends with any of the given suffixes. |
| `matchesSubstring` | `(string $className, array $substrings): bool` | True if the class name contains any of the given substrings. |

**Example** (from `SpecificClassInjection`):

```php
if (Types::isCollectionType($className)) {
    // Should inject CollectionFactory instead
}
if (Types::isNonMagentoLibrary($className)) {
    // Skip non-Magento classes
}
```

## Modules

**File:** `src/Core/Scan/Util/Modules.php`

Module name extraction, file grouping, and path utilities.

| Method | Signature | Description |
|--------|-----------|-------------|
| `extractModuleNameFromPath` | `(string $filePath): ?string` | Extract `Vendor_Module` from a file path. Supports `app/code/`, `vendor/`, and generic patterns. |
| `groupFilesByModule` | `(array $files): array` | Group file paths into `['Vendor_Module' => [file1, file2, ...]]`. |
| `isSameModule` | `(string $classA, string $classB): bool` | True if two FQCNs share the same `Vendor\Module` prefix. |
| `isBlockFile` | `(string $file): bool` | True if the file is in a `Block/` directory. |
| `isSetupDirectory` | `(string $filePath): bool` | True if the file is in a `Setup/` directory. |
| `findDiXmlForFile` | `(string $phpFile): ?string` | Walk up from a PHP file to find the module's `etc/di.xml`. |

**Example** (from `HardWrittenSQL`):

```php
// Skip Setup scripts where raw SQL is expected
if (Modules::isSetupDirectory($file)) {
    continue;
}
```

## DiScope

**File:** `src/Core/Scan/Util/DiScope.php`

DI area scope detection for `di.xml` files.

| Constant | Value |
|----------|-------|
| `GLOBAL` | `'global'` |
| `FRONTEND` | `'frontend'` |
| `ADMINHTML` | `'adminhtml'` |
| `WEBAPI_REST` | `'webapi_rest'` |
| `WEBAPI_SOAP` | `'webapi_soap'` |
| `CRONTAB` | `'crontab'` |
| `GRAPHQL` | `'graphql'` |

| Method | Signature | Description |
|--------|-----------|-------------|
| `getScope` | `(string $filePath): string` | Determine the area scope from a `di.xml` file path (e.g., `etc/frontend/di.xml` -> `'frontend'`). Returns `'global'` for `etc/di.xml`. |
| `isGlobal` | `(string $filePath): bool` | True if the file is in global (non-area) scope. |
| `detectClassArea` | `(string $className): ?string` | Suggest an area from a class name based on namespace patterns (e.g., `\Block\Adminhtml\` -> `'adminhtml'`, `\ViewModel\` -> `'frontend'`). Returns `null` if no area can be inferred. |
| `loadXml` | `(string $file): \SimpleXMLElement\|false` | Load an XML file safely. Delegates to `Xml::loadFile()`. |

**Example** (from `Preferences`):

```php
$scope = DiScope::getScope($diFile);
// Only flag duplicate preferences within the same scope
```

## Xml

**File:** `src/Core/Scan/Util/Xml.php`

Safe XML loading with error suppression.

| Method | Signature | Description |
|--------|-----------|-------------|
| `loadFile` | `(string $file): \SimpleXMLElement\|false` | Load an XML file using `simplexml_load_file()` with `libxml_use_internal_errors(true)`. Returns `false` on parse failure without triggering PHP warnings. Restores previous error state. |

**Example:**

```php
$xml = Xml::loadFile($diXmlPath);
if ($xml === false) {
    continue; // Skip malformed XML
}
foreach ($xml->preference ?? [] as $preference) {
    // Process
}
```

## Formater

**File:** `src/Core/Scan/Util/Formater.php`

SARIF-compatible error formatting.

```php
public static function formatError(
    string $file,
    int    $startLine,
    string $message  = '',
    string $severity = 'warning',
    int    $endLine  = 0,
    array  $metadata = []
): array
```

Returns:

```php
[
    'file'      => '/absolute/path/to/file.php',
    'startLine' => 42,
    'endLine'   => 42,      // defaults to startLine if 0
    'message'   => 'Description of the issue',
    'severity'  => 'warning',
    'metadata'  => [...]     // only present if non-empty
]
```

The `file` path is resolved to an absolute path via `Paths::getAbsolutePath()`.

**Always use `Formater::formatError()`** for all results to ensure consistent SARIF output.

### Multi-location findings

A single finding can point at multiple source locations by encoding them into the `file` field as comma-separated `path:line` segments and passing `startLine = 0`:

```php
$locations = [];
foreach ($plugins as $plugin) {
    $locations[] = $plugin['diFile'] . ':' . $plugin['line'];
}
$this->results[] = Formater::formatError(implode(', ', $locations), 0, $message, 'high');
```

`SarifReporter` splits this back into one SARIF `physicalLocation` per segment. `HtmlReporter` renders the Line column as `—` when `startLine` is `0`. `AroundPlugins::deepPluginStack` uses this convention to surface every di.xml declaration that participates in a stack.

## PluginRegistry

**File:** `src/Core/Scan/Util/PluginRegistry.php`

Global index of all `<plugin>` declarations across every `di.xml` in the scan tree. Built once per scan from `DOMDocument` so each entry retains its source line.

| Method | Signature | Description |
|--------|-----------|-------------|
| `build` | `(array $diFiles): void` | Parse the given `di.xml` files and populate the registry. Subsequent calls are no-ops until `reset()` is called. |
| `isBuilt` | `(): bool` | True once `build()` has run. |
| `reset` | `(): void` | Clear the index (used in tests). |
| `getTargetClass` | `(string $pluginClass): ?string` | Reverse-lookup: return the target class for a given plugin class, or `null` if unknown. |
| `getPluginsForTarget` | `(string $targetClass): array` | Return every active (non-disabled) `<plugin>` declared against a target class. |

Plugin entry shape:

```php
[
    'class'    => 'Vendor\\Module\\Plugin\\FooPlugin',
    'name'     => 'foo_plugin',
    'disabled' => false,
    'diFile'   => '/abs/path/etc/di.xml',
    'line'     => 17,   // line of the <plugin> node inside diFile
]
```

The `line` field is populated via `DOMNode::getLineNo()`. Only direct `<plugin>` children of a `<type>` are collected — nested `<plugin>` nodes inside `<arguments>` are skipped.

## Paths

**File:** `src/Service/Paths.php`

Filesystem path helpers, XDG-aware.

| Method | Signature | Description |
|--------|-----------|-------------|
| `configDir` | `(): string` | Resolves to `$XDG_CONFIG_HOME/easyaudit` (falling back to `$HOME/.config/easyaudit`). |
| `configFile` | `(): string` | Path to the active config file (`config.json.local` if present, otherwise `config.json`). |
| `cacheDir` | `(string $subdir = ''): string` | Resolves to `$XDG_CACHE_HOME/easyaudit` (falling back to `$HOME/.cache/easyaudit`, then the system temp dir). Creates the directory and the optional sub-directory on first use with mode `0700`. Throws `RuntimeException` on failure. |
| `getAbsolutePath` | `(string $path): string` | Resolve a possibly-relative path against `EA_SCAN_PATH`. Used by `Formater::formatError()`. |

## Console Output (CliWriter)

**File:** `src/Service/CliWriter.php`

All console output should go through `CliWriter` for consistent formatting and coloring.

### Result Reporting

The most important method for processors:

```php
CliWriter::resultLine(string $label, int $count, string $severity = 'error'): void
```

Outputs a colored line with a severity icon:

```
  ✗ Hard-written SELECT queries: 3       (error - red)
  ! N+1 patterns (load in loop): 5       (warning - yellow)
  i Missing strict_types: 12             (note - blue)
```

Call this at the end of your `process()` method to report findings to the console.

### Other Methods

| Method | Use Case |
|--------|----------|
| `success($msg)` | Green message (task completed) |
| `error($msg)` | Red message (failure) |
| `warning($msg)` | Yellow message |
| `info($msg)` | Blue message |
| `section($title)` | Yellow section header |
| `processorHeader($name)` | Cyan processor name (called by Scanner) |
| `skipped($msg)` | Dim "skipped" indicator |
| `line($msg)` | Plain text line |
| `labelValue($label, $value, $color)` | `Label: Value` with colored value |

In processors, you typically only need `resultLine()`. The Scanner handles `processorHeader()` calls automatically.

## Scanner mode signal

**File:** `src/Core/Scan/Scanner.php`

`Scanner` exposes a process-wide scan-mode signal so commands can tell processors which feature is driving the current run. Processors read the mode to tune behavior — for example, amplifying severity on checkout-critical files in a `checkout-audit` run.

| Method | Signature | Description |
|--------|-----------|-------------|
| `Scanner::getMode` | `(): ?string` | Active scan mode, or `null` for a default general-purpose scan. |
| `Scanner::setMode` | `(?string $mode): void` | Set the active mode. Commands call this before `Scanner::run()`. Tests should reset it to `null` afterwards. |

The free repo does not set a mode in the default `scan` command; the hook exists so sponsor commands (e.g. `checkout-audit`) can opt in without forking the scanner.
