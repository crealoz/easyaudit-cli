# Security & Privacy

EasyAudit CLI runs entirely offline. It never communicates with external servers.

## Scanning is 100% local

When you run `easyaudit scan`, **all analysis happens on your machine**. No source code, file paths, scan results, or any other data leaves your environment. The tool:

- Reads files from disk
- Runs all 20 processors locally
- Writes the SARIF report to your local filesystem

There is **zero network activity** during a scan, and `scan` is the only command. You can verify this by running the tool with network access disabled.

## PHAR integrity — Build attestation

Every `easyaudit.phar` attached to a GitHub release is signed using [GitHub Artifact Attestations](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations/using-artifact-attestations-to-establish-provenance-for-builds). This provides cryptographic proof that the PHAR was built by the official CI workflow from the source code in this repository.

Verify a downloaded PHAR:

```bash
gh attestation verify easyaudit.phar --owner crealoz
```

This confirms the binary has not been tampered with after build.

---

[Back to README](../README.md)