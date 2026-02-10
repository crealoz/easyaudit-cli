# Writing a Processor

This guide covers the processor architecture and how to create a new one for EasyAudit CLI.

## Processor Architecture

All analysis in EasyAudit is performed by **processors** located in `src/Core/Scan/Processor/`. Each processor:

1. Extends `AbstractProcessor`
2. Implements `ProcessorInterface`
3. Declares which file type it handles (`php`, `phtml`, `xml`, or `di`)
4. Is **auto-discovered** by the Scanner via directory scan -- no registration needed

### ProcessorInterface Contract

```php
interface ProcessorInterface
{
    public function getIdentifier(): string;      // Unique rule ID (lowercase, hyphen-separated)
    public function getFileType(): string;         // 'php', 'phtml', 'xml', or 'di'
    public function getName(): string;             // Human-readable name
    public function getMessage(): string;          // Short description for SARIF
    public function getLongDescription(): string;  // Detailed explanation for SARIF
    public function process(array $files): void;   // Analyze files, populate results
    public function getFoundCount(): int;          // Number of issues found
    public function getReport(): array;            // SARIF-compatible findings
}
```

### AbstractProcessor

`AbstractProcessor` provides:

- `protected array $results` -- store your findings here
- `protected int $foundCount` -- increment for each issue found
- `getFoundCount()` -- returns `$foundCount`
- `getReport()` -- default implementation that wraps `$results` with the processor's rule metadata

The default `getReport()` returns a single-rule array:

```php
[[
    'ruleId'           => $this->getIdentifier(),
    'name'             => $this->getName(),
    'shortDescription' => $this->getMessage(),
    'longDescription'  => $this->getLongDescription(),
    'files'            => $this->results,
]]
```

Override `getReport()` only when your processor emits **multiple rule IDs** (see [Multi-Rule Processors](#multi-rule-processors)).

### File Types

| Value   | Matches                        |
|---------|--------------------------------|
| `php`   | `*.php` files                  |
| `phtml` | `*.phtml` template files       |
| `xml`   | `*.xml` files (except `di.xml`)|
| `di`    | `**/di.xml` files specifically |

The `$files` array passed to `process()` is keyed by type, so access your files as `$files[$this->getFileType()]`.

## Adding a New Processor

### Step 1: Create the Class

Create `src/Core/Scan/Processor/YourProcessor.php`:

```php
<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\CliWriter;

class YourProcessor extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'magento.code.your-rule-id';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'Your Processor Name';
    }

    public function getMessage(): string
    {
        return 'Short description of what this detects.';
    }

    public function getLongDescription(): string
    {
        return 'Detailed explanation of why this is a problem and how to fix it.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        foreach ($files['php'] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $cleaned = Content::removeComments($content);
            $this->analyze($cleaned, $file, $content);
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('Issues found', count($this->results), 'warning');
        }
    }

    private function analyze(string $cleaned, string $file, string $original): void
    {
        // Your detection logic here
        // When you find an issue:
        $line = Content::getLineNumber($original, 'pattern');
        $this->results[] = Formater::formatError($file, $line, 'Description of issue', 'warning');
        $this->foundCount++;
    }
}
```

### Step 2: Add Test Fixtures

Create directories:

```
tests/fixtures/YourProcessor/
├── Bad/
│   └── Example.php       # File that SHOULD trigger findings
└── Good/
    └── Example.php       # File that should NOT trigger findings
```

### Step 3: Write Tests

Create `tests/Unit/Core/Scan/Processor/YourProcessorTest.php` (see [Testing Your Processor](#testing-your-processor)).

That's it -- the processor is automatically discovered at runtime.

## Best Practices

### Use Shared Utilities

Don't reinvent logic that already exists in `Util/` classes. Before writing custom parsing, check the [Utilities Reference](utilities.md):

- **`Types`** for class classification (`isCollectionType`, `isRepository`, `isResourceModel`)
- **`Modules`** for module name extraction and file grouping
- **`Classes`** for constructor parsing and class hierarchy
- **`Content`** for line numbers and comment removal
- **`Functions`** for function body extraction
- **`DiScope`** for DI area scope detection

### Use `Formater::formatError()` for All Results

Every finding must go through `Formater::formatError()` to ensure SARIF-compatible output. Don't build result arrays manually.

### Use `CliWriter::resultLine()` for Console Output

Report findings with `CliWriter::resultLine()` for consistent terminal output with severity icons, counts, and coloring. Call it at the end of `process()`.

### Keep `process()` Focused

Extract detection logic into private methods. The `process()` method should iterate files and delegate to focused helpers.

Good pattern (from `HardWrittenSQL`):

```php
public function process(array $files): void
{
    foreach ($files['php'] as $file) {
        $content = file_get_contents($file);
        $cleaned = Content::removeComments($content);
        $this->detectSQL($cleaned, $file, $content);
    }
    $this->reportResults();
}
```

### Override `getReport()` Only for Multi-Rule Processors

The default `AbstractProcessor::getReport()` works for single-rule processors. Override only when your processor emits **multiple rule IDs** -- for example, `HardWrittenSQL` emits separate rules for SELECT, DELETE, INSERT, UPDATE, and JOIN.

### Multi-Rule Processors

When a processor detects multiple distinct categories, override `getReport()` to return separate entries:

```php
public function getReport(): array
{
    $report = [];
    foreach ($this->resultsByType as $type => $findings) {
        $report[] = [
            'ruleId'           => "magento.code.your-rule-$type",
            'name'             => "Rule for $type",
            'shortDescription' => "Short description for $type",
            'longDescription'  => "Long description for $type",
            'files'            => $findings,
        ];
    }
    return $report;
}
```

See `HardWrittenSQL` and `AdvancedBlockVsViewModel` for real examples.

### Declare the Right File Type

- `php` for PHP source files
- `phtml` for template files
- `xml` for layout and config XML (but **not** `di.xml`)
- `di` specifically for `di.xml` dependency injection files

### Choose Severity Carefully

| Level     | Meaning                        | Use When                              |
|-----------|--------------------------------|---------------------------------------|
| `error`   | Should block CI                | Security risk, will break at runtime  |
| `warning` | Important but non-blocking     | Bad practice, performance issue       |
| `note`    | Informational                  | Style issue, minor improvement        |

Most new processors should default to `warning`.

### Avoid False Positives

- Use `Content::removeComments()` before regex matching on PHP code
- Check for edge cases: string literals, commented-out code, test files
- Skip `Setup/` directories for SQL-related rules (raw SQL is expected there)
- Use `Modules::isSetupDirectory()` to detect setup scripts

## Testing Your Processor

### PHPUnit Setup

Tests use PHPUnit 10.x with `beStrictAboutOutputDuringTests="true"`. Since processors write to stdout via `CliWriter`, you **must** capture output in tests.

### Test Structure

Create `tests/Unit/Core/Scan/Processor/YourProcessorTest.php`:

```php
<?php

namespace Tests\Unit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\YourProcessor;
use PHPUnit\Framework\TestCase;

class YourProcessorTest extends TestCase
{
    private YourProcessor $processor;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->processor = new YourProcessor();
        $this->fixturePath = dirname(__DIR__, 4) . '/fixtures/YourProcessor';
    }

    public function testGetIdentifier(): void
    {
        $this->assertSame('magento.code.your-rule-id', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertSame('php', $this->processor->getFileType());
    }

    public function testDetectsBadPattern(): void
    {
        $files = ['php' => glob($this->fixturePath . '/Bad/*.php')];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGoodPattern(): void
    {
        $files = ['php' => glob($this->fixturePath . '/Good/*.php')];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertSame(0, $this->processor->getFoundCount());
    }

    public function testReportStructure(): void
    {
        $files = ['php' => glob($this->fixturePath . '/Bad/*.php')];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();
        $this->assertNotEmpty($report);
        $this->assertArrayHasKey('ruleId', $report[0]);
        $this->assertArrayHasKey('files', $report[0]);
    }
}
```

### Key Testing Patterns

- **`ob_start()` / `ob_end_clean()`**: Wrap every `process()` call to capture console output
- **Fixture files**: Create minimal PHP/XML files that trigger (or don't trigger) your rule
- **Temporary files**: Use `tempnam()` for edge-case tests, clean up in `tearDown()`
- **Fresh instances**: Create a new processor instance if you need isolated state between tests

### Fixture Structure

```
tests/fixtures/YourProcessor/
├── Bad/
│   ├── DirectCollection.php    # Triggers the rule
│   └── MultipleIssues.php      # Multiple findings in one file
└── Good/
    ├── FactoryPattern.php       # Correct pattern, no findings
    └── EmptyConstructor.php     # Edge case, no findings
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run a single processor test
vendor/bin/phpunit tests/Unit/Core/Scan/Processor/YourProcessorTest.php

# Run with coverage
vendor/bin/phpunit --coverage-text
```
