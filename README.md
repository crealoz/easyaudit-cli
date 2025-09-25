# EasyAudit

Static analysis tool for PHP projects to detect Magento plugins and overrides.

## Requirements

- PHP 8.1+
- Docker (optional but recommended for CI/CD integration)

## Installation

Clone the repository:

```bash
git clone git@github.com:crealoz/easyaudit-cli.git
```

## Local Usage

For full usage information, run:

```bash
bin/easyaudit scan --help
```

### Options

- `--format` : Output format (`json`, `sarif`). Default: `text`.
- `--output` : Output file path. Default: `report/easyaudit-report.(json|sarif)`.
- `--exclude` : Comma-separated list of directories to exclude. Default: none.
- `--exclude-ext` : Comma-separated list of file extensions to exclude. Default: none.

Reports are usually written to the `report/` folder.

## Output Formats

- **JSON** – structured output for tooling and scripting.
- **SARIF** – standardized format for GitHub Code Scanning integration.

### SARIF Severity Levels

EasyAudit uses SARIF’s standard levels:

- `error` – critical violation, should block merges.
- `warning` – important issues, but non-blocking.
- `note` – informational findings.
- `none` – explicitly no severity.

Each finding is mapped to a SARIF `ruleId`. Rules are declared in the SARIF output under `tool.driver.rules` (e.g., `no-proxy-in-commands`, `same-module-plugins`).

## Docker Image

EasyAudit provides a ready-to-use Docker image hosted on GitHub Container Registry.

Pull the latest image:

```bash
docker pull ghcr.io/crealoz/easyaudit:latest
```

Run a scan inside a project:

```bash
docker run --rm -it   -v $PWD:/workspace   ghcr.io/crealoz/easyaudit:latest   scan --format=sarif --output=/workspace/report/easyaudit-report.sarif /workspace
```

## GitHub Actions Integration

To automatically scan your codebase on each push or pull request, add this workflow file at `/.github/workflows/scan.yml`:

```yaml
name: EasyAudit – Scan

on:
  push:
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
          easyaudit scan             --format=sarif             --output=report/easyaudit-report.sarif             "$GITHUB_WORKSPACE"             --exclude="vendor,generated,var,pub/static,pub/media"

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: report/easyaudit-report.sarif
```

## Example Output in GitHub Code Scanning
![scanning-alert-terrible-module.png](images/scanning-alert-terrible-module.png)