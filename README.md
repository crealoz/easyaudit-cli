# EasyAudit

Static analysis tool for PHP projects to detect Magento plugins and overrides.

## Requirements

- PHP 8.1+
- Docker (optional but recommended for CI/CD integration)

## Installation

Clone the repository:

```bash
git clone git@github.com:crealoz/easyaudit-cli.git
```

## Local Usage

For full usage information, run:

```bash
bin/easyaudit scan --help
```

### Options

- `--format` : Output format (`json`, `sarif`). Default: `text`.
- `--output` : Output file path. Default: `report/easyaudit-report.(json|sarif)`.
- `--exclude` : Comma-separated list of directories to exclude. Default: none.
- `--exclude-ext` : Comma-separated list of file extensions to exclude. Default: none.

Reports are usually written to the `report/` folder.

## Available Processors

EasyAudit includes **16 static analysis processors** for Magento 2 codebases:

### Dependency Injection (DI) Analysis

- **SameModulePlugins** – Detects plugins targeting classes in the same module (anti-pattern)
- **MagentoFrameworkPlugin** – Detects plugins on Magento Framework classes (performance issue)
- **AroundPlugins** – Classifies around plugins as before/after or override
- **NoProxyInCommands** – Detects console commands without proxy usage
- **Preferences** – Detects multiple preferences for the same interface/class
- **ProxyForHeavyClasses** – Detects heavy classes (Session, Collection, ResourceModel) without proxies

### Code Quality

- **HardWrittenSQL** – Detects raw SQL queries (security risk)
- **UseOfRegistry** – Detects deprecated Registry usage
- **UseOfObjectManager** – Detects direct ObjectManager usage (anti-pattern)
- **SpecificClassInjection** – Detects injection of specific classes instead of interfaces
- **PaymentInterfaceUseAudit** – Detects deprecated payment method implementations

### Template/View Layer

- **Cacheable** – Detects blocks with `cacheable="false"` (performance impact)
- **AdvancedBlockVsViewModel** – Detects `$this` usage and data crunch in templates
- **Helpers** – Detects deprecated Helper patterns (AbstractHelper, helpers in templates)

### Architecture & Best Practices

- **BlockViewModelRatio** – Analyzes ratio of Blocks vs ViewModels per module
- **UnusedModules** – Detects modules present in codebase but disabled in config.php

Each processor outputs findings with appropriate severity levels (`error`, `warning`, or `note`) and provides actionable recommendations.

## Output Formats

- **JSON** – structured output for tooling and scripting.
- **SARIF** – standardized format for GitHub Code Scanning integration.
- **Text** – human-readable console output (default).

### SARIF Severity Levels

EasyAudit uses SARIF's standard levels:

- `error` – critical violation, should block merges.
- `warning` – important issues, but non-blocking.
- `note` – informational findings.
- `none` – explicitly no severity.

Each finding is mapped to a SARIF `ruleId`. Rules are declared in the SARIF output under `tool.driver.rules`.

## Docker Image

EasyAudit provides a ready-to-use Docker image hosted on GitHub Container Registry.

Pull the latest image:

```bash
docker pull ghcr.io/crealoz/easyaudit:latest
```

Run a scan inside a project:

```bash
docker run --rm -it   -v $PWD:/workspace   ghcr.io/crealoz/easyaudit:latest   scan --format=sarif --output=/workspace/report/easyaudit-report.sarif /workspace
```

## GitHub Actions Integration

To automatically scan your codebase on each push or pull request, add this workflow file at `/.github/workflows/scan.yml`:

```yaml
name: EasyAudit – Scan

on:
  push:
  pull_request:

permissions:
  contents: read
  security-events: write

jobs:
  scan:
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/crealoz/easyaudit:latest

    steps:
      - uses: actions/checkout@v4

      - name: Run EasyAudit
        run: |
          mkdir -p report
          easyaudit scan             --format=sarif             --output=report/easyaudit-report.sarif             "$GITHUB_WORKSPACE"             --exclude="vendor,generated,var,pub/static,pub/media"

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit-report.sarif
```

## Example Output in GitHub Code Scanning
![scanning-alert-terrible-module.png](images/scanning-alert-terrible-module.png)