# CI/CD Integration

EasyAudit integrates with all major CI/CD platforms for automated code scanning. Results can be viewed as artifacts or integrated with platform-specific security dashboards.

---

## Supported Platforms

| Platform | Config File | Documentation |
|----------|-------------|---------------|
| GitHub Actions | `.github/workflows/*.yml` | [github-actions.md](ci-cd/github-actions.md) |
| GitLab CI | `.gitlab-ci.yml` | [gitlab-ci.md](ci-cd/gitlab-ci.md) |
| Bitbucket Pipelines | `bitbucket-pipelines.yml` | [bitbucket-pipelines.md](ci-cd/bitbucket-pipelines.md) |
| Azure DevOps | `azure-pipelines.yml` | [azure-devops.md](ci-cd/azure-devops.md) |
| CircleCI | `.circleci/config.yml` | [circleci.md](ci-cd/circleci.md) |
| Jenkins | `Jenkinsfile` | [jenkins.md](ci-cd/jenkins.md) |
| Travis CI | `.travis.yml` | [travis-ci.md](ci-cd/travis-ci.md) |

---

## Quick Example (GitHub Actions)

```yaml
name: EasyAudit

on: [push, pull_request]

jobs:
  scan:
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/crealoz/easyaudit:latest
    steps:
      - uses: actions/checkout@v6
      - run: easyaudit scan --format=sarif --output=report.sarif .
      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report.sarif
```

> **💡 Want automatic fixes?** See [Automated PR workflow](request-pr.md)

---

## Output Formats

| Format | Use Case |
|--------|----------|
| `sarif` | GitHub Code Scanning, GitLab SAST |
| `json` | Custom tooling, artifacts |
| `text` | Console output, logs |

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | No issues found |
| 1 | Warnings found |
| 2 | Errors found |

Use exit codes to fail builds on critical issues.

---

## Auto-Detection

EasyAudit automatically detects CI environments and adds metadata to API requests. Supported detection:

- `GITHUB_ACTIONS` → GitHub
- `GITLAB_CI` → GitLab
- `BITBUCKET_PIPELINE_UUID` → Bitbucket
- `TF_BUILD` → Azure DevOps
- `CIRCLECI` → CircleCI
- `JENKINS_URL` → Jenkins
- `TRAVIS` → Travis CI

---

## See Also

- [CLI Usage](cli-usage.md) - Command-line options
- [Processors](processors.md) - Available checks
- [Automated PR (paid)](request-pr.md) - Auto-fix via API

---

[Back to README](../README.md)
