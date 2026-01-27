# Bitbucket Pipelines Integration

EasyAudit integrates with Bitbucket Pipelines for automated code scanning. Results are available as downloadable pipeline artifacts.

---

## Quick Start

Create `bitbucket-pipelines.yml` in your repository root:

```yaml
image: ghcr.io/crealoz/easyaudit:latest

pipelines:
  default:
    - step:
        name: EasyAudit Scan
        script:
          - mkdir -p report
          - easyaudit scan
              --format=sarif
              --output=report/easyaudit.sarif
              --exclude="vendor,generated,var,pub/static,pub/media"
              "$BITBUCKET_CLONE_DIR"
        artifacts:
          - report/easyaudit.sarif
```

---

## Workflow Variants

### Scan on Pull Requests Only

```yaml
image: ghcr.io/crealoz/easyaudit:latest

pipelines:
  pull-requests:
    '**':
      - step:
          name: EasyAudit Scan
          script:
            - mkdir -p report
            - easyaudit scan
                --format=sarif
                --output=report/easyaudit.sarif
                "$BITBUCKET_CLONE_DIR/app/code"
          artifacts:
            - report/easyaudit.sarif
```

### Fail on Errors

```yaml
image: ghcr.io/crealoz/easyaudit:latest

pipelines:
  default:
    - step:
        name: EasyAudit Scan
        script:
          - mkdir -p report
          - |
            easyaudit scan \
              --format=sarif \
              --output=report/easyaudit.sarif \
              --exclude="vendor,generated,var" \
              "$BITBUCKET_CLONE_DIR"
            EXIT_CODE=$?
            if [ $EXIT_CODE -eq 2 ]; then
              echo "EasyAudit found critical issues"
              exit 1
            fi
        artifacts:
          - report/easyaudit.sarif
```

### JSON Artifact

```yaml
image: ghcr.io/crealoz/easyaudit:latest

pipelines:
  default:
    - step:
        name: EasyAudit Scan
        script:
          - mkdir -p report
          - easyaudit scan
              --format=json
              --output=report/easyaudit.json
              "$BITBUCKET_CLONE_DIR"
        artifacts:
          - report/easyaudit.json
```

### Branch-Specific Scanning

```yaml
image: ghcr.io/crealoz/easyaudit:latest

pipelines:
  branches:
    main:
      - step:
          name: Full EasyAudit Scan
          script:
            - mkdir -p report
            - easyaudit scan
                --format=sarif
                --output=report/easyaudit.sarif
                --exclude="vendor,generated,var,pub/static,pub/media,dev,setup"
                "$BITBUCKET_CLONE_DIR"
          artifacts:
            - report/easyaudit.sarif
    develop:
      - step:
          name: EasyAudit Scan
          script:
            - mkdir -p report
            - easyaudit scan
                --format=sarif
                --output=report/easyaudit.sarif
                "$BITBUCKET_CLONE_DIR/app/code"
          artifacts:
            - report/easyaudit.sarif
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `BITBUCKET_CLONE_DIR` | Repository root (auto-set) |
| `BITBUCKET_PIPELINE_UUID` | Unique pipeline identifier (auto-detected) |
| `BITBUCKET_REPO_FULL_NAME` | Repository full name (workspace/repo) |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

---

## Viewing Results

1. Go to your repository in Bitbucket
2. Navigate to **Pipelines**
3. Click on the completed pipeline
4. Click on the step name
5. Click **Artifacts** tab
6. Download `report/easyaudit.sarif` or `report/easyaudit.json`

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to README](../../README.md)
