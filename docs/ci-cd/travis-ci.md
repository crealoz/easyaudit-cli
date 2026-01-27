# Travis CI Integration

EasyAudit integrates with Travis CI for automated code scanning. Results are available through build logs or can be deployed as artifacts.

---

## Quick Start

Create `.travis.yml` in your repository root:

```yaml
language: minimal

services:
  - docker

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=sarif
      --output=/workspace/report/easyaudit.sarif
      --exclude="vendor,generated,var,pub/static,pub/media"
      /workspace

after_success:
  - cat report/easyaudit.sarif
```

---

## Workflow Variants

### Scan on Pull Requests Only

```yaml
language: minimal

services:
  - docker

branches:
  only:
    - main
    - develop

if: type = pull_request

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=sarif
      --output=/workspace/report/easyaudit.sarif
      /workspace/app/code
```

### Fail on Errors

```yaml
language: minimal

services:
  - docker

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - |
    docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan \
      --format=sarif \
      --output=/workspace/report/easyaudit.sarif \
      --exclude="vendor,generated,var" \
      /workspace
    EXIT_CODE=$?
    if [ $EXIT_CODE -eq 2 ]; then
      echo "EasyAudit found critical issues"
      exit 1
    fi
```

### JSON Output

```yaml
language: minimal

services:
  - docker

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=json
      --output=/workspace/report/easyaudit.json
      /workspace

after_success:
  - cat report/easyaudit.json
```

### Deploy Report to GitHub Releases

```yaml
language: minimal

services:
  - docker

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=sarif
      --output=/workspace/report/easyaudit.sarif
      --exclude="vendor,generated,var,pub/static,pub/media"
      /workspace

deploy:
  provider: releases
  api_key: $GITHUB_TOKEN
  file: report/easyaudit.sarif
  skip_cleanup: true
  on:
    tags: true
```

### Scheduled Weekly Scan

```yaml
language: minimal

services:
  - docker

if: type = cron

before_install:
  - docker pull ghcr.io/crealoz/easyaudit:latest

script:
  - mkdir -p report
  - docker run --rm -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=sarif
      --output=/workspace/report/easyaudit.sarif
      --exclude="vendor,generated,var,pub/static,pub/media,dev,setup"
      /workspace
```

Configure cron in Travis CI settings:
1. Go to your repository settings in Travis CI
2. Under **Cron Jobs**, add a weekly build on the `main` branch

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `TRAVIS_BUILD_DIR` | Repository root (auto-set) |
| `TRAVIS` | Set to `true` in Travis CI (auto-detected) |
| `TRAVIS_REPO_SLUG` | Repository slug (owner/repo) |
| `TRAVIS_BUILD_ID` | Unique build ID |
| `EASYAUDIT_AUTH` | API credentials for paid features (optional) |

Set `EASYAUDIT_AUTH` in Travis CI:
1. Go to your repository settings in Travis CI
2. Under **Environment Variables**, add `EASYAUDIT_AUTH`
3. Keep **Display value in build log** OFF for security

To use in Docker:

```yaml
script:
  - docker run --rm -e EASYAUDIT_AUTH="$EASYAUDIT_AUTH" -v "$TRAVIS_BUILD_DIR:/workspace" ghcr.io/crealoz/easyaudit:latest scan
      --format=sarif
      --output=/workspace/report/easyaudit.sarif
      /workspace
```

---

## Viewing Results

Travis CI doesn't have native artifact storage. Options:

1. **Build logs**: Use `cat report/easyaudit.sarif` in `after_success`
2. **GitHub Releases**: Deploy artifacts on tagged builds
3. **External storage**: Upload to S3, GitHub Gist, or other services

### Upload to S3

```yaml
after_success:
  - pip install awscli
  - aws s3 cp report/easyaudit.sarif s3://my-bucket/easyaudit/$TRAVIS_BUILD_ID/
```

---

## See Also

- [Automated PR (paid)](../request-pr.md) - Auto-fix issues via API
- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to README](../../README.md)
