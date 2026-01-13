# EasyAudit

Static analysis tool for Magento 2 codebases. Detects anti-patterns, security risks, and architectural issues.

## Features

- **16 processors** for DI, code quality, templates, and architecture
- **Zero dependencies** - standalone PHAR (~165KB)
- **CI/CD ready** - SARIF output for GitHub Code Scanning
- **Docker image** available

## Quick Start

### Using PHAR
```bash
# Download from releases
php easyaudit.phar scan /path/to/magento --format=sarif
```

### Using Docker
```bash
docker run --rm -v $PWD:/workspace ghcr.io/crealoz/easyaudit:latest \
  scan /workspace --format=sarif --output=/workspace/report/easyaudit.sarif
```

### From Source
```bash
git clone git@github.com:crealoz/easyaudit-cli.git
php bin/easyaudit scan /path/to/magento
```

## Output Formats

| Format  | Use Case                 |
|---------|--------------------------|
| `text`  | Console output (default) |
| `json`  | Tooling and scripting    |
| `sarif` | GitHub Code Scanning     |

## GitHub Actions

```yaml
name: EasyAudit Scan

on: [push, pull_request]

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
      - run: |
          mkdir -p report
          easyaudit scan --format=sarif --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var,pub/static,pub/media" "$GITHUB_WORKSPACE"
      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit.sarif
```

![GitHub Code Scanning](images/scanning-alert-terrible-module.png)

## Documentation

- [CLI Usage](docs/cli-usage.md) - Commands, options, examples
- [Available Processors](docs/processors.md) - All 16 analysis rules
- [GitHub Actions](docs/github-actions.md) - CI/CD workflow examples
- [Automated PR (paid)](docs/request-pr.md) - Auto-fix via API

## Requirements

- PHP 8.1+
- Docker (optional)

## License

MIT