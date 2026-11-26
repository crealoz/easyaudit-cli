# GitHub Actions Integration

EasyAudit integrates with GitHub Actions for automated code scanning. Results appear in the **Security** tab of your repository.

## Table of Contents

- [Quick Start](#quick-start)
- [Workflow Variants](#workflow-variants)
- [Private Repositories](#private-repositories)
- [Required Permissions](#required-permissions)
- [Environment Variables](#environment-variables)
- [Viewing Results](#viewing-results)
- [See Also](#see-also)

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
      - uses: actions/checkout@v6

      - name: Run EasyAudit
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var,pub/static,pub/media" \
            "$GITHUB_WORKSPACE"

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v4
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
      - uses: actions/checkout@v6

      - name: Scan changed code
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            "$GITHUB_WORKSPACE/app/code"

      - uses: github/codeql-action/upload-sarif@v4
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
      - uses: actions/checkout@v6

      - name: Full codebase scan
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
            "$GITHUB_WORKSPACE"

      - uses: github/codeql-action/upload-sarif@v4
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
      - uses: actions/checkout@v6

      - name: Run EasyAudit (fail on errors)
        run: |
          mkdir -p report
          EXIT_CODE=0
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            --exclude="vendor,generated,var" \
            "$GITHUB_WORKSPACE" || EXIT_CODE=$?

          if [ $EXIT_CODE -eq 2 ]; then
            echo "::error::EasyAudit found critical issues"
            exit 1
          fi

      - uses: github/codeql-action/upload-sarif@v4
        if: always()
        with:
          sarif_file: report/easyaudit.sarif
```

---

## Private Repositories

SARIF upload to GitHub Code Scanning requires [GitHub Advanced Security](https://docs.github.com/en/get-started/learning-about-github/about-github-advanced-security), which is **free for public repos** but a **paid feature for private repos**.

Without it, the `github/codeql-action/upload-sarif` step will fail with:
```
Error: Resource not accessible by integration
```

**For private repos**, use the SARIF Artifact workflow below, or rely on exit codes to fail your CI pipeline.

---

### SARIF Artifact (no GitHub Security)

```yaml
name: EasyAudit SARIF Report

on: [push]

jobs:
  scan:
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/crealoz/easyaudit:latest

    steps:
      - uses: actions/checkout@v6

      - name: Run EasyAudit
        run: |
          mkdir -p report
          easyaudit scan \
            --format=sarif \
            --output=report/easyaudit.sarif \
            "$GITHUB_WORKSPACE"

      - name: Upload SARIF report
        uses: actions/upload-artifact@v4
        with:
          name: easyaudit-report
          path: report/easyaudit.sarif
```

---

## Required Permissions

**Scan workflows:**
```yaml
permissions:
  contents: read          # Read repository code
  security-events: write  # Upload SARIF to Security tab (not needed for the artifact workflow)
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `GITHUB_WORKSPACE` | Repository root (auto-set by GitHub) |

---

## Viewing Results

1. Go to your repository on GitHub
2. Click **Security** tab
3. Click **Code scanning alerts**
4. Filter by tool: "EasyAudit"

![GitHub Code Scanning](../images/scanning-alert-terrible-module.png)

---

## See Also

- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to CI/CD Overview](../ci-cd.md) | [Back to README](../../README.md)