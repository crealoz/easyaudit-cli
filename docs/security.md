# Security & Privacy

EasyAudit CLI is designed to run entirely offline. This page explains exactly when and how the tool communicates with external servers.

## Scanning is 100% local

When you run `easyaudit scan`, **all analysis happens on your machine**. No source code, file paths, scan results, or any other data leaves your environment. The tool:

- Reads files from disk
- Runs all 20 processors locally
- Writes reports (JSON, SARIF, HTML) to your local filesystem

There is **zero network activity** during a scan. You can verify this by running the tool with network access disabled.

## When does the tool contact Crealoz servers?

Only the **fix-apply** command (paid fixer) communicates with `api.crealoz.fr`. This is an explicit, opt-in action that:

1. Requires authentication (`easyaudit auth` or `EASYAUDIT_AUTH` environment variable)
2. Requires confirmation before sending data
3. Sends only the specific file contents and rule identifiers needed to generate patches
4. Uses HTTPS with strict certificate verification in CI environments
5. **Source code is immediately deleted** from the server once the patch is returned (or on failure)

No other command makes any network request.

## Summary

| Command            | Network activity          |
|--------------------|---------------------------|
| `scan`             | None                      |
| `fix-apply`        | `api.crealoz.fr` (paid)   |
| `auth`             | `api.crealoz.fr` (login)  |
| All other commands | None                      |

## PHAR integrity — Build attestation

Every `easyaudit.phar` attached to a GitHub release is signed using [GitHub Artifact Attestations](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations/using-artifact-attestations-to-establish-provenance-for-builds). This provides cryptographic proof that the PHAR was built by the official CI workflow from the source code in this repository.

Verify a downloaded PHAR:

```bash
gh attestation verify easyaudit.phar --owner crealoz
```

This confirms the binary has not been tampered with after build.

## TLS & Certificates

See [Automated PR docs — TLS / self-signed certificates](request-pr.md#tls--self-signed-certificates) for details on certificate handling in CI vs local environments.

---

[Back to README](../README.md)