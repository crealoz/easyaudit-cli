# Azure DevOps Integration

EasyAudit integrates with Azure Pipelines for automated code scanning. Results can be published as pipeline artifacts or attached to build summaries.

---

## Quick Start

Create `azure-pipelines.yml` in your repository root:

```yaml
trigger:
  branches:
    include:
      - main
      - develop

pr:
  branches:
    include:
      - main

pool:
  vmImage: 'ubuntu-latest'

container:
  image: ghcr.io/crealoz/easyaudit:latest

steps:
  - checkout: self

  - script: |
      mkdir -p $(Build.ArtifactStagingDirectory)/report
      easyaudit scan \
        --format=sarif \
        --output=$(Build.ArtifactStagingDirectory)/report/easyaudit.sarif \
        --exclude="vendor,generated,var,pub/static,pub/media" \
        "$(Build.SourcesDirectory)"
    displayName: 'Run EasyAudit'

  - publish: $(Build.ArtifactStagingDirectory)/report
    artifact: easyaudit-report
    displayName: 'Publish EasyAudit Report'
```

---

## Workflow Variants

### Scan on Pull Requests Only

```yaml
trigger: none

pr:
  branches:
    include:
      - main
      - develop

pool:
  vmImage: 'ubuntu-latest'

container:
  image: ghcr.io/crealoz/easyaudit:latest

steps:
  - checkout: self

  - script: |
      mkdir -p $(Build.ArtifactStagingDirectory)/report
      easyaudit scan \
        --format=sarif \
        --output=$(Build.ArtifactStagingDirectory)/report/easyaudit.sarif \
        "$(Build.SourcesDirectory)/app/code"
    displayName: 'Run EasyAudit'

  - publish: $(Build.ArtifactStagingDirectory)/report
    artifact: easyaudit-report
```

### Fail on Errors

```yaml
trigger:
  - main

pool:
  vmImage: 'ubuntu-latest'

container:
  image: ghcr.io/crealoz/easyaudit:latest

steps:
  - checkout: self

  - script: |
      mkdir -p $(Build.ArtifactStagingDirectory)/report
      EXIT_CODE=0
      easyaudit scan \
        --format=sarif \
        --output=$(Build.ArtifactStagingDirectory)/report/easyaudit.sarif \
        --exclude="vendor,generated,var" \
        "$(Build.SourcesDirectory)" || EXIT_CODE=$?
      if [ $EXIT_CODE -eq 2 ]; then
        echo "##vso[task.logissue type=error]EasyAudit found critical issues"
        exit 1
      fi
    displayName: 'Run EasyAudit (fail on errors)'

  - publish: $(Build.ArtifactStagingDirectory)/report
    artifact: easyaudit-report
    condition: always()
```

### JSON Artifact

```yaml
trigger:
  - main

pool:
  vmImage: 'ubuntu-latest'

container:
  image: ghcr.io/crealoz/easyaudit:latest

steps:
  - checkout: self

  - script: |
      mkdir -p $(Build.ArtifactStagingDirectory)/report
      easyaudit scan \
        --format=json \
        --output=$(Build.ArtifactStagingDirectory)/report/easyaudit.json \
        "$(Build.SourcesDirectory)"
    displayName: 'Run EasyAudit'

  - publish: $(Build.ArtifactStagingDirectory)/report
    artifact: easyaudit-report
```

### Scheduled Weekly Scan

```yaml
trigger: none

schedules:
  - cron: '0 6 * * 1'
    displayName: 'Weekly Monday 6am UTC'
    branches:
      include:
        - main
    always: true

pool:
  vmImage: 'ubuntu-latest'

container:
  image: ghcr.io/crealoz/easyaudit:latest

steps:
  - checkout: self

  - script: |
      mkdir -p $(Build.ArtifactStagingDirectory)/report
      easyaudit scan \
        --format=sarif \
        --output=$(Build.ArtifactStagingDirectory)/report/easyaudit.sarif \
        --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
        "$(Build.SourcesDirectory)"
    displayName: 'Full EasyAudit Scan'

  - publish: $(Build.ArtifactStagingDirectory)/report
    artifact: easyaudit-report
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `Build.SourcesDirectory` | Repository root (auto-set) |
| `Build.ArtifactStagingDirectory` | Artifact staging path (auto-set) |
| `TF_BUILD` | Set to `True` in Azure Pipelines (auto-detected) |
| `BUILD_REPOSITORY_NAME` | Repository name |
| `BUILD_BUILDID` | Unique build ID |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

---

## Viewing Results

1. Go to your project in Azure DevOps
2. Navigate to **Pipelines > Runs**
3. Click on the completed run
4. Click **Artifacts** (or the artifact count in the summary)
5. Download `easyaudit-report`

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to CI/CD Overview](../ci-cd.md) | [Back to README](../../README.md)
