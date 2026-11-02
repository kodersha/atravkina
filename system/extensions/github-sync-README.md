# GitHub Sync for Datenstrom Yellow

This extension synchronizes the complete `content/` and `media/` directory trees with a GitHub repository.

## Install

1. Copy `system/workers/github-sync.php` into your Yellow installation's `system/workers/`.
2. Copy `system/extensions/yellow-github-sync.ini` into `system/extensions/`.
3. Edit the INI file. Set `Repository`, `Branch`, and `Token`.
4. Create a fine-grained GitHub personal access token restricted to the target repository with **Contents: Read and write** permission.
5. Ensure PHP has cURL enabled (recommended), or allow outbound HTTPS through PHP streams.

The token is a secret. Protect the settings file from web access and do not commit a populated token.

## Automatic synchronization

Automatic synchronization is enabled by default (`AutoSync: 1`).

On each Yellow request, the extension recursively scans both directory trees and compares file fingerprints with a local state file. New and changed files are uploaded; files removed locally are deleted from the GitHub branch. The Yellow content/media edit hooks also trigger a scan immediately after editor changes.

All regular files are synchronized, not only Markdown or images. Subdirectories and their relative paths are preserved. Symbolic links and the `.git` directory are skipped.

This request-based check also catches filesystem changes made outside the Yellow editor, including file deletions. It is not a background daemon: a change is pushed on the next site request (or when the manual command is run).

## Manual synchronization

Run from the Yellow installation root:

```sh
php yellow.php github-sync
php yellow.php github-sync content
php yellow.php github-sync media
```

The optional scope limits the scan to one directory tree.

## Configuration

- `Repository`: GitHub repository in `owner/name` form.
- `Branch`: existing target branch.
- `Token`: fine-grained personal access token.
- `AutoSync`: `1` enables automatic synchronization (default); `0` disables it.
- `MaxFileSizeMB`: maximum file size per upload (default configuration: 90 MB).

## State and deletion behavior

The extension stores file fingerprints in `system/extensions/.yellow-github-sync-state.json`. Do not delete this file unless you intend to perform a fresh initial synchronization. Remote files are deleted only when they were previously tracked by this extension and are no longer present locally.

GitHub Contents API creates one commit per changed/deleted file. GitHub API limits and request latency therefore depend on the number of changed files. The configured size limit is 90 MB; files exceeding it are logged and skipped.
