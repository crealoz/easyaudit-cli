# CircleCI Integration

EasyAudit integrates with CircleCI for automated code scanning. Results are available as workflow artifacts.

---

## Quick Start

Create `.circleci/config.yml` in your repository root:

```yaml
version: 2.1

jobs:
  easyaudit:
    docker:
      - image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - checkout
      - run:
          name: Run EasyAudit
          command: |
            mkdir -p report
            easyaudit scan \
              --format=sarif \
              --output=report/easyaudit.sarif \
              --exclude="vendor,generated,var,pub/static,pub/media" \
              "$PWD"
      - store_artifacts:
          path: report
          destination: easyaudit-report

workflows:
  scan:
    jobs:
      - easyaudit
```

---

## Workflow Variants

### Scan on Pull Requests Only

```yaml
version: 2.1

jobs:
  easyaudit:
    docker:
      - image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - checkout
      - run:
          name: Run EasyAudit
          command: |
            mkdir -p report
            easyaudit scan \
              --format=sarif \
              --output=report/easyaudit.sarif \
              "$PWD/app/code"
      - store_artifacts:
          path: report
          destination: easyaudit-report

workflows:
  pr-scan:
    jobs:
      - easyaudit:
          filters:
            branches:
              ignore: main
```

### Fail on Errors

```yaml
version: 2.1

jobs:
  easyaudit:
    docker:
      - image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - checkout
      - run:
          name: Run EasyAudit (fail on errors)
          command: |
            mkdir -p report
            easyaudit scan \
              --format=sarif \
              --output=report/easyaudit.sarif \
              --exclude="vendor,generated,var" \
              "$PWD"
            EXIT_CODE=$?
            if [ $EXIT_CODE -eq 2 ]; then
              echo "EasyAudit found critical issues"
              exit 1
            fi
      - store_artifacts:
          path: report
          destination: easyaudit-report
          when: always

workflows:
  strict-scan:
    jobs:
      - easyaudit
```

### JSON Artifact

```yaml
version: 2.1

jobs:
  easyaudit:
    docker:
      - image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - checkout
      - run:
          name: Run EasyAudit
          command: |
            mkdir -p report
            easyaudit scan \
              --format=json \
              --output=report/easyaudit.json \
              "$PWD"
      - store_artifacts:
          path: report
          destination: easyaudit-report

workflows:
  scan:
    jobs:
      - easyaudit
```

### Scheduled Weekly Scan

```yaml
version: 2.1

jobs:
  easyaudit:
    docker:
      - image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - checkout
      - run:
          name: Full EasyAudit Scan
          command: |
            mkdir -p report
            easyaudit scan \
              --format=sarif \
              --output=report/easyaudit.sarif \
              --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
              "$PWD"
      - store_artifacts:
          path: report
          destination: easyaudit-report

workflows:
  weekly-scan:
    triggers:
      - schedule:
          cron: '0 6 * * 1'
          filters:
            branches:
              only: main
    jobs:
      - easyaudit
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `PWD` | Working directory / repository root |
| `CIRCLECI` | Set to `true` in CircleCI (auto-detected) |
| `CIRCLE_PROJECT_REPONAME` | Repository name |
| `CIRCLE_WORKFLOW_ID` | Unique workflow ID |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

Set `EASYAUDIT_AUTH` in CircleCI:
1. Go to **Project Settings > Environment Variables**
2. Add `EASYAUDIT_AUTH` with your API key

---

## Viewing Results

1. Go to your project in CircleCI
2. Click on the completed workflow
3. Click on the `easyaudit` job
4. Click **Artifacts** tab
5. Download from `easyaudit-report/`

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to README](../../README.md)
