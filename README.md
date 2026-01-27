# EasyAudit

[![Latest Release](https://img.shields.io/github/v/release/crealoz/easyaudit-cli?style=flat-square)](https://github.com/crealoz/easyaudit-cli/releases)
[![License: MIT](https://img.shields.io/github/license/crealoz/easyaudit-cli?style=flat-square)](./LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF?style=flat-square)](https://php.net)
[![Tests](https://img.shields.io/github/actions/workflow/status/crealoz/easyaudit-cli/tests.yml?style=flat-square&label=tests)](https://github.com/crealoz/easyaudit-cli/actions)
[![codecov](https://codecov.io/gh/crealoz/easyaudit-cli/graph/badge.svg?token=JA0WEVL9XM)](https://codecov.io/gh/crealoz/easyaudit-cli)

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
- [CI/CD Integration](docs/ci-cd.md) - GitHub, GitLab, Bitbucket, Azure, CircleCI, Jenkins, Travis
- [Automated PR (paid)](docs/request-pr.md) - Auto-fix via API

## Requirements

- PHP 8.1+
- Docker (optional)

## License

MIT

