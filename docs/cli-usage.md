# CLI Usage

## Table of Contents

- [Installation](#installation)
- [Commands](#commands)
- [Common Use Cases](#common-use-cases)
- [Docker Usage](#docker-usage)
- [Exit Codes](#exit-codes)
- [Output Examples](#output-examples)

---

## Installation

### Using PHAR (recommended)

```bash
# Download latest PHAR
curl -LO https://github.com/crealoz/easyaudit-cli/releases/latest/download/easyaudit.phar
chmod +x easyaudit.phar

# Optional: move to PATH
sudo mv easyaudit.phar /usr/local/bin/easyaudit
```

### Using Docker
```bash
docker pull ghcr.io/crealoz/easyaudit:latest
```

### From Source
```bash
git clone git@github.com:crealoz/easyaudit-cli.git
cd easyaudit-cli
```

---

## Commands

### Scan

Run static analysis on a Magento codebase.

```bash
# Basic scan (JSON output, default)
php bin/easyaudit scan /path/to/magento

# Explicit JSON output
php bin/easyaudit scan /path/to/magento --format=json

# SARIF output (for GitHub Code Scanning)
php bin/easyaudit scan /path/to/magento --format=sarif

# HTML report (visual dashboard, print-to-PDF friendly)
php bin/easyaudit scan /path/to/magento --format=html

# Custom output file
php bin/easyaudit scan /path/to/magento --format=sarif --output=report/scan.sarif

# Exclude directories
php bin/easyaudit scan /path/to/magento --exclude="vendor,generated,var"

# Exclude file extensions
php bin/easyaudit scan /path/to/magento --exclude-ext="js,css"
```

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `--format` | Output format: `json`, `sarif`, `html` | `json` |
| `--output` | Output file path | `report/easyaudit-report.(json\|sarif\|html)` |
| `--exclude` | Comma-separated directories to exclude | none |
| `--exclude-ext` | Comma-separated file extensions to exclude | none |
| `--all-magento` | Include `vendor/` when scanning a Magento root (CI only) | `false` |

### Help

```bash
php bin/easyaudit scan --help
```

---

## Common Use Cases

### Scan app/code only
```bash
php bin/easyaudit scan /path/to/magento/app/code --format=json
```

### Scan a single module
```bash
php bin/easyaudit scan /path/to/magento/app/code/Vendor/Module --format=json
```

### Scan a Magento root

When pointing at a Magento installation root, noise directories (`vendor`, `generated`, `var`, `pub`, `setup`, `lib`, `dev`, `phpserver`, `update`) are **automatically excluded**. No `--exclude` needed.

```bash
# Interactive: you'll be asked whether to include vendor/
php bin/easyaudit scan /path/to/magento --format=sarif

# CI/CD: vendor is excluded by default
# Pass --all-magento to also scan vendor/
php bin/easyaudit scan /path/to/magento --format=sarif --all-magento
```

Detection is based on Magento indicators (`bin/magento`, `nginx.conf.sample`, `app/etc/env.php`, `generated/`, `pub/`). At least 2 must be present.

### Quick check before commit
```bash
php bin/easyaudit scan . --format=json
```

### Scan and fix workflow
```bash
# 1. Scan and generate report
php bin/easyaudit scan /path/to/magento --format=json --output=report.json

# 2. Apply fixes (requires API credits)
php bin/easyaudit fix-apply report.json
```

> **Note**: `fix-apply` requires API credentials stored in `~/.config/easyaudit/`. Use the PHAR directly or `bin/easyaudit` for fix-apply — Docker is not recommended for this command.

Automated PR creation is available as a paid feature. See [Automated PR workflow](request-pr.md) for CI/CD integration.

---

## Docker Usage

> **Tip**: Use `--user "$(id -u):$(id -g)"` so report files are owned by your user instead of root.

### Basic scan
```bash
docker run --rm --user "$(id -u):$(id -g)" -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace --format=json
```

### Generate SARIF report
```bash
docker run --rm --user "$(id -u):$(id -g)" -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace --format=sarif
```

### Scan a specific subdirectory
```bash
docker run --rm --user "$(id -u):$(id -g)" -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace/app/code --format=json
```

---

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | No errors or warnings found |
| `1` | Warnings found |
| `2` | Errors found |

Use exit codes in CI to fail builds:

```bash
php bin/easyaudit scan /path/to/magento --format=sarif || exit 1
```

---

## Output Examples

![cli-scanning.png](../images/cli-scanning.png)

> **Auto-fix available**
> Many issues can be fixed automatically. Run `easyaudit fix-apply` or [set up automated PRs](request-pr.md)

![cli-fix-apply.png](../images/cli-fix-apply.png)

### JSON
```json
[
  {
    "ruleId": "sameModulePlugins",
    "message": "Plugin on same module class",
    "files": [
      {
        "file": "app/code/Vendor/Module/Plugin/SomePlugin.php",
        "line": 15
      }
    ]
  }
]
```

### HTML

The HTML format produces a self-contained dashboard with:

- **Summary cards** — color-coded counts for errors, warnings, and notes
- **Collapsible rule sections** — one per processor, with a table of affected files, line numbers, and messages
- **Print-to-PDF support** — `@media print` styles ensure clean output when printing from the browser

```bash
# Generate HTML report
php bin/easyaudit scan /path/to/magento --format=html

# Open in browser
xdg-open report/easyaudit-report.html    # Linux
open report/easyaudit-report.html         # macOS
```

The report is fully standalone (all CSS inline, no external dependencies) and can be shared as a single file or printed to PDF directly from the browser.

![easyaudit-html-report.png](../images/easyaudit-html-report.png)

---

[Back to README](../README.md)
