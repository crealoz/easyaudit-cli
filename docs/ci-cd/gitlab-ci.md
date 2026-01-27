# GitLab CI Integration

EasyAudit integrates with GitLab CI/CD for automated code scanning. Results can be viewed as pipeline artifacts or integrated with GitLab's Security Dashboard.

---

## Quick Start

Create `.gitlab-ci.yml` in your repository root:

```yaml
stages:
  - scan

easyaudit:
  stage: scan
  image: ghcr.io/crealoz/easyaudit:latest
  script:
    - mkdir -p report
    - easyaudit scan
        --format=sarif
        --output=report/easyaudit.sarif
        --exclude="vendor,generated,var,pub/static,pub/media"
        "$CI_PROJECT_DIR"
  artifacts:
    paths:
      - report/easyaudit.sarif
    reports:
      sast: report/easyaudit.sarif
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
    - if: $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH
```

---

## Workflow Variants

### Scan on Merge Requests Only

```yaml
stages:
  - scan

easyaudit:
  stage: scan
  image: ghcr.io/crealoz/easyaudit:latest
  script:
    - mkdir -p report
    - easyaudit scan
        --format=sarif
        --output=report/easyaudit.sarif
        "$CI_PROJECT_DIR/app/code"
  artifacts:
    paths:
      - report/easyaudit.sarif
    reports:
      sast: report/easyaudit.sarif
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
```

### Fail on Errors

```yaml
stages:
  - scan

easyaudit:
  stage: scan
  image: ghcr.io/crealoz/easyaudit:latest
  script:
    - mkdir -p report
    - |
      easyaudit scan \
        --format=sarif \
        --output=report/easyaudit.sarif \
        --exclude="vendor,generated,var" \
        "$CI_PROJECT_DIR"
      EXIT_CODE=$?
      if [ $EXIT_CODE -eq 2 ]; then
        echo "EasyAudit found critical issues"
        exit 1
      fi
  artifacts:
    paths:
      - report/easyaudit.sarif
    reports:
      sast: report/easyaudit.sarif
    when: always
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
    - if: $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH
```

### JSON Artifact

```yaml
stages:
  - scan

easyaudit:
  stage: scan
  image: ghcr.io/crealoz/easyaudit:latest
  script:
    - mkdir -p report
    - easyaudit scan
        --format=json
        --output=report/easyaudit.json
        "$CI_PROJECT_DIR"
  artifacts:
    paths:
      - report/easyaudit.json
    expire_in: 1 week
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `CI_PROJECT_DIR` | Repository root (auto-set) |
| `GITLAB_CI` | Set to `true` in GitLab CI (auto-detected) |
| `CI_PROJECT_PATH` | Project path with namespace |
| `CI_PIPELINE_ID` | Unique pipeline ID |
| `CI_JOB_ID` | Unique job ID |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

---

## Viewing Results

### Pipeline Artifacts

1. Go to your project in GitLab
2. Navigate to **Build > Pipelines**
3. Click on the pipeline
4. Click **Download artifacts** or browse the job artifacts

### Security Dashboard (Ultimate tier)

If you use the `reports: sast` artifact type, results appear in:

1. **Security & Compliance > Vulnerability Report**
2. Merge request security widget

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to README](../../README.md)
