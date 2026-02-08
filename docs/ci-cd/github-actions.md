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

**For private repos**, use the JSON Artifact workflow below, or rely on exit codes to fail your CI pipeline.

---

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
      - uses: actions/checkout@v6

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

### HTML Artifact (visual dashboard)

Upload a self-contained HTML report as a downloadable artifact. Useful for code reviews and sharing with non-technical stakeholders.

```yaml
name: EasyAudit HTML Report

on: [push, pull_request]

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
            --format=html \
            --output=report/easyaudit.html \
            --exclude="vendor,generated,var,pub/static,pub/media" \
            "$GITHUB_WORKSPACE"

      - name: Upload HTML report
        uses: actions/upload-artifact@v4
        with:
          name: easyaudit-html-report
          path: report/easyaudit.html
```

### Scan, Fix & Create PR (paid)

One-click workflow that scans the codebase, calls the paid EasyAudit API to generate fixes, applies them as patches, and opens a PR.

> Requires the `EASYAUDIT_AUTH` secret. See [Automated PR docs](../request-pr.md) for setup.

```yaml
name: "EasyAudit - fix & PR (paid)"

on:
  workflow_dispatch:
    inputs:
      ack_paid:
        description: "I confirm this action is PAID and a PR will be billed"
        required: true
        type: boolean
        default: false

permissions:
  contents: write
  pull-requests: write

jobs:
  fix-and-pr:
    if: ${{ inputs.ack_paid == true }}
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/crealoz/easyaudit:latest

    steps:
      - uses: actions/checkout@v6
        with:
          fetch-depth: 0

      - name: Scan (JSON)
        run: |
          mkdir -p report
          easyaudit scan \
            --format=json \
            --output=report/easyaudit-report.json \
            --exclude="vendor,generated,var,pub/static,pub/media" \
            "$GITHUB_WORKSPACE"
          test -s report/easyaudit-report.json

      - name: Apply fixes (paid)
        env:
          EASYAUDIT_AUTH: ${{ secrets.EASYAUDIT_AUTH }}
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: easyaudit fix-apply report/easyaudit-report.json --confirm

      - name: Apply patches individually
        working-directory: ${{ github.workspace }}
        run: |
          git config --global --add safe.directory "$GITHUB_WORKSPACE"
          APPLIED=0
          for PATCH in $(find patches/ -type f | sort); do
            grep -v '^# ' "$PATCH" \
              | sed "s|a/$GITHUB_WORKSPACE/|a/|g; s|b/$GITHUB_WORKSPACE/|b/|g" \
              > "$PATCH.clean" || true
            mv "$PATCH.clean" "$PATCH"
            if git apply --index -p1 "$PATCH"; then
              APPLIED=$((APPLIED + 1))
            else
              echo "Warning: failed to apply $PATCH"
            fi
          done
          echo "Applied $APPLIED patch(es)"
          [ "$APPLIED" -gt 0 ] || exit 1

      - name: Create Pull Request
        uses: peter-evans/create-pull-request@v8
        with:
          token: ${{ secrets.GITHUB_TOKEN }}
          branch: ${{ github.ref_name }}-easyaudit-fix
          commit-message: "Apply EasyAudit fixes"
          title: "EasyAudit automatic fixes"
          body: |
            Automatically generated fixes from branch `${{ github.ref_name }}`.
```

---

## Required Permissions

**Scan workflows (SARIF / JSON / HTML):**
```yaml
permissions:
  contents: read          # Read repository code
  security-events: write  # Upload SARIF to Security tab (SARIF only)
```

**Fix & PR workflow (paid):**
```yaml
permissions:
  contents: write         # Push fix branch
  pull-requests: write    # Create PR
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `GITHUB_WORKSPACE` | Repository root (auto-set by GitHub) |
| `GITHUB_TOKEN` | GitHub API token (auto-set, used by `create-pull-request`) |
| `EASYAUDIT_AUTH` | API credentials for paid fix-apply (format: `Bearer <key>:<hash>`) |

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

[Back to CI/CD Overview](../ci-cd.md) | [Back to README](../../README.md)