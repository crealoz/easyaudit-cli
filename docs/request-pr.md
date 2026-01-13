# EasyAudit — Paid PR Request (GitHub Actions)

This guide explains how to install and use the **EasyAudit – Automated PR (paid)** workflow in your repository.  
With one click, it runs `easyaudit-cli`, calls `easyaudit-api` for billing/authorization, and creates a Pull Request with the generated changes.

> **Billing notice**  
> Running this workflow will call the paid EasyAudit service and may incur charges. Make sure you have purchased credits before use.  
> 👉 Purchase credits: https://shop.crealoz.fr/shop/credits-for-easyaudit-fixer/

---

## Prerequisites

- **GitHub permissions**: the workflow needs `contents: write` and `pull-requests: write`. (It is set in the sample workflow below.)
- **Secret**: add a repository or organization secret named **`EASYAUDIT_AUTH`** with the exact format:
    - `Bearer <key>:<hash>`
- **Optional environment**: create an environment named **`billable-pr`** and add *Required reviewers* if you want manual approval before billing/PR creation.
- **Runner**: `ubuntu-latest`.

---

## Installation

1. In your repository, create the file **`.github/workflows/easyaudit.yml`** with the content below.
2. Add the secret **`EASYAUDIT_AUTH`** in **Settings → Secrets and variables → Actions → New repository secret**.
3. (Optional) In **Settings → Environments**, create **`billable-pr`**, add a description/warning text and required reviewers.

### Workflow file (`.github/workflows/easyaudit.yml`)

```yaml
name: "EasyAudit - Automated PR (paid)"

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
  easyaudit-pr:
    name: "Create PR via EasyAudit (paid)"
    runs-on: ubuntu-latest
    environment: billable-pr

    steps:
      - name: Guardrails (confirmation & cost warning)
        run: |
          echo "### Billing notice" >> $GITHUB_STEP_SUMMARY
          echo "- This run will call easyaudit-api and may incur charges." >> $GITHUB_STEP_SUMMARY
          echo "- Triggered by: ${{ github.actor }}" >> $GITHUB_STEP_SUMMARY
          echo "- Repo: ${{ github.repository }}" >> $GITHUB_STEP_SUMMARY
          [ "${{ inputs.ack_paid }}" = "true" ] || { echo "Rejected: ack_paid=false"; exit 1; }

      - name: Checkout
        uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Run easyaudit-cli (prepare payload + call easyaudit-api)
        id: cli
        uses: docker://ghcr.io/crealoz/easyaudit:latest
        env:
          # Must be exactly "Bearer <key>:<hash>"
          EASYAUDIT_AUTH: ${{ secrets.EASYAUDIT_AUTH }}
          # If the CLI needs GitHub API access, it can read this token
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          args: >
            apply-fix
            --type "git"
            --out result.json
            "$GITHUB_WORKSPACE"

      - name: Verify CLI output and install jq
        run: |
          test -f result.json || { echo "result.json not found (easyaudit-cli output)"; exit 1; }
          sudo apt-get update -y && sudo apt-get install -y jq >/dev/null
          jq -e . result.json >/dev/null || { echo "Invalid JSON in result.json"; cat result.json; exit 1; }

      - name: Extract PR metadata
        id: meta
        run: |
          echo "pr_title=$(jq -r '.pr.title // "EasyAudit security fix"' result.json)" >> $GITHUB_OUTPUT
          echo "pr_body=$(jq -r '.pr.body  // "PR generated via EasyAudit (paid)."' result.json)" >> $GITHUB_OUTPUT
          echo "commit_message=$(jq -r '.pr.commit_message // .pr.title // "chore(easyaudit): security fixes"' result.json)" >> $GITHUB_OUTPUT
          echo "branch=$(jq -r '.pr.branch // empty' result.json)" >> $GITHUB_OUTPUT

      - name: Apply changes and push branch (if working tree changed)
        id: push
        run: |
          BRANCH="${{ steps.meta.outputs.branch }}"
          [ -n "$BRANCH" ] || BRANCH="sec-fix/easyaudit-$(date +%s)"
          echo "BRANCH=$BRANCH" >> $GITHUB_ENV

          if [ -n "$(git status --porcelain)" ]; then
            git checkout -b "$BRANCH"
            git add -A
            git -c user.name="github-actions[bot]" -c user.email="41898282+github-actions[bot]@users.noreply.github.com"               commit -m "${{ steps.meta.outputs.commit_message }}"
            git push -u origin "$BRANCH"
            echo "pushed=true" >> $GITHUB_OUTPUT
          else
            echo "No local changes detected."
            echo "pushed=false" >> $GITHUB_OUTPUT
          fi

      - name: Create Pull Request
        uses: actions/github-script@v7
        env:
          PR_TITLE: ${{ steps.meta.outputs.pr_title }}
          PR_BODY: ${{ steps.meta.outputs.pr_body }}
          BASE: ${{ github.ref_name }}
          BRANCH: ${{ env.BRANCH }}
        with:
          github-token: ${{ secrets.GITHUB_TOKEN }}
          script: |
            const title = process.env.PR_TITLE || "EasyAudit security fix";
            const body  = process.env.PR_BODY  || "PR generated via EasyAudit (paid).";
            const base  = process.env.BASE     || "main";
            const head  = process.env.BRANCH;

            if (!head) {
              core.setFailed("Missing branch name. Ensure the CLI returns .pr.branch or local changes were pushed.");
              return;
            }

            try {
              const { data } = await github.rest.pulls.create({
                owner: context.repo.owner,
                repo: context.repo.repo,
                title,
                head,
                base,
                body
              });
              core.info(`PR created: #${data.number} ${data.html_url}`);
              core.summary.addHeading('PR created');
              core.summary.addRaw(`URL: ${data.html_url}`).write();
            } catch (e) {
              if (e.status === 422) {
                core.warning("PR already exists or no diff between branches.");
              } else {
                core.setFailed(`Failed to create PR: ${e.message}`);
              }
            }
```

> **Tip**: The base branch is taken from the “Use workflow from → Branch” selector in the Actions UI (`github.ref_name`). There is no separate input for base.

---

## How to run

1. Go to **Actions → EasyAudit - Automated PR (paid)**.
2. At the top, pick **Use workflow from → Branch** (this becomes the PR’s base).
3. Check **“I confirm this action is PAID and a PR will be billed”**.
4. Click **Run workflow**.

The job summary will show a billing notice and, once complete, a link to the created PR.

---

## Expected outputs

- A new branch is pushed (or reused if provided by the CLI).
- A PR is created against the base branch you selected in the UI.
- The job summary includes the PR URL.

---

## Troubleshooting

- **Rejected: ack_paid=false**  
  You didn’t tick the confirmation checkbox.

- **`result.json not found` or `Invalid JSON in result.json`**  
  Ensure `easyaudit-cli` produced a valid `result.json` at repository root.

- **`No local changes detected.`**  
  The CLI didn’t modify the working tree (e.g., nothing to fix or the mode is “patch”). Make sure your CLI applies changes or returns a branch name with pre-pushed changes.

- **PR already exists or no diff** (`HTTP 422`)  
  The branch already has an open PR, or there is no content difference. Edit and rerun, or delete the existing PR/branch.

- **`EASYAUDIT_AUTH` missing/invalid**  
  Add/validate the secret and keep the value **exactly** `Bearer <key>:<hash>`.

---

## Credits & Billing

**No-change policy:** If the workflow produces **no file modifications**, **no cost is applied**.

Buy EasyAudit fixer credits here:  
**https://shop.crealoz.fr/shop/credits-for-easyaudit-fixer/**

For security, do not expose secrets in logs. Use repository/organization secrets and (optionally) an approval **Environment** (e.g., `billable-pr`) for an extra safety gate.

---

## TLS / self-signed certificates

easyaudit-cli talks to easyaudit-api over HTTPS.

### Default behavior

On GitHub Actions / CI (`GITHUB_ACTIONS=true` or `CI=true`): self-signed certificates are rejected (strict).

Local development (no CI env vars): self-signed certificates are allowed (developer convenience).

### Override (explicit)

Set `EASYAUDIT_SELF_SIGNED=true` to force allow self-signed certs.

Set `EASYAUDIT_SELF_SIGNED=false` to force reject self-signed certs.

If unset, the auto-detection above applies.

Recommendation: only allow self-signed certificates for local testing; keep strict verification on CI/GitHub Actions.

---

[Back to README](../README.md)