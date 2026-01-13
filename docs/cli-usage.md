# CLI Usage

## Installation

### Clone the repository
```bash
git clone git@github.com:crealoz/easyaudit-cli.git
cd easyaudit-cli
```

### Using PHAR (recommended)
Download the latest `easyaudit.phar` from [releases](https://github.com/crealoz/easyaudit-cli/releases).

```bash
# Make it executable
chmod +x easyaudit.phar

# Optional: move to PATH
sudo mv easyaudit.phar /usr/local/bin/easyaudit
```

### Using Docker
```bash
docker pull ghcr.io/crealoz/easyaudit:latest
```

---

## Commands

### Scan

Run static analysis on a Magento codebase.

```bash
# Basic scan (text output)
php bin/easyaudit scan /path/to/magento

# JSON output
php bin/easyaudit scan /path/to/magento --format=json

# SARIF output (for GitHub Code Scanning)
php bin/easyaudit scan /path/to/magento --format=sarif

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
| `--format` | Output format: `text`, `json`, `sarif` | `text` |
| `--output` | Output file path | `report/easyaudit-report.(json\|sarif)` |
| `--exclude` | Comma-separated directories to exclude | none |
| `--exclude-ext` | Comma-separated file extensions to exclude | none |

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
php bin/easyaudit scan /path/to/magento/app/code/Vendor/Module --format=text
```

### Scan with all exclusions (production-like)
```bash
php bin/easyaudit scan /path/to/magento \
  --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
  --format=sarif \
  --output=report/easyaudit.sarif
```

### Quick check before commit
```bash
php bin/easyaudit scan . --format=text
```

---

## Docker Usage

### Basic scan
```bash
docker run --rm -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace --format=text
```

### Generate SARIF report
```bash
docker run --rm -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace \
  --format=sarif \
  --output=/workspace/report/easyaudit.sarif
```

### Scan with exclusions
```bash
docker run --rm -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace \
  --exclude="vendor,generated,var,pub/static,pub/media" \
  --format=json
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

### Text (default)
```
=== SameModulePlugins ===
[WARNING] Plugin on same module class
  File: app/code/Vendor/Module/Plugin/SomePlugin.php
  Target: Vendor\Module\Model\SomeModel

=== UseOfObjectManager ===
[ERROR] Direct ObjectManager usage
  File: app/code/Vendor/Module/Helper/Data.php:42
```

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

---

[Back to README](../README.md)