# GitHub Actions Integration

EasyAudit integrates with GitHub Actions for automated code scanning. Results appear in the **Security** tab of your repository.

---

## Quick Start

Create `.github/workflows/easyaudit.yml`:

```yaml
name: EasyAudit Scan

on:
  push:
    branches: [main, develop]
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
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var,pub/static,pub/media" \
            "$GITHUB_WORKSPACE"

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit.sarif
```

---

## Workflow Variants

### Scan on Pull Requests Only

```yaml
name: EasyAudit PR Check

on:
  pull_request:
    paths:
      - 'app/code/**'
      - 'app/design/**'

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

      - name: Scan changed code
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            "$GITHUB_WORKSPACE/app/code"

      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit.sarif
```

### Scheduled Weekly Scan

```yaml
name: EasyAudit Weekly

on:
  schedule:
    - cron: '0 6 * * 1'  # Every Monday at 6am UTC
  workflow_dispatch:  # Allow manual trigger

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

      - name: Full codebase scan
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
            "$GITHUB_WORKSPACE"

      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit.sarif
```

### Fail on Errors

```yaml
name: EasyAudit Strict

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

      - name: Run EasyAudit (fail on errors)
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var" \
            "$GITHUB_WORKSPACE"

          # Exit code 2 = errors found
          EXIT_CODE=$?
          if [ $EXIT_CODE -eq 2 ]; then
            echo "::error::EasyAudit found critical issues"
            exit 1
          fi

      - uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: report/easyaudit.sarif
```

### JSON Artifact (no GitHub Security)

```yaml
name: EasyAudit JSON Report

on: [push]

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
          easyaudit scan \
            --format=json \
            --output=report/easyaudit.json \
            "$GITHUB_WORKSPACE"

      - name: Upload JSON report
        uses: actions/upload-artifact@v4
        with:
          name: easyaudit-report
          path: report/easyaudit.json
```

---

## Required Permissions

```yaml
permissions:
  contents: read          # Read repository code
  security-events: write  # Upload SARIF to Security tab
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `GITHUB_WORKSPACE` | Repository root (auto-set) |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

---

## Viewing Results

1. Go to your repository on GitHub
2. Click **Security** tab
3. Click **Code scanning alerts**
4. Filter by tool: "EasyAudit"

![GitHub Code Scanning](../images/scanning-alert-terrible-module.png)

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to README](../../README.md)