# GitHub Sync for Datenstrom Yellow

This extension sends Yellow Markdown content and selected media files to a GitHub repository using the GitHub Contents API.

## Install

1. Copy `system/workers/github-sync.php` into your Yellow installation's `system/workers/`.
2. Copy `system/extensions/yellow-github-sync.ini` into `system/extensions/`.
3. Edit the INI file. Set `Repository`, `Branch`, and `Token`.
4. Create a fine-grained GitHub personal access token restricted to the target repository with **Contents: Read and write** permission. No token is required for other permissions.
5. Ensure PHP has cURL enabled (recommended), or allow outbound HTTPS through PHP streams.

The token is a secret. Protect the settings file from web access and do not commit a populated token.

## Synchronize manually

Run from the Yellow installation root:

```sh
php yellow.php github-sync
php yellow.php github-sync content
php yellow.php github-sync media
```

- `all` syncs Markdown under `content/` and the configured media extensions under `media/`.
- `content` syncs Markdown files only.
- `media` syncs configured media extensions only.

Existing files are updated and new files are added. The extension does not remove repository files when local files are deleted. Each file is committed separately by GitHub's Contents API.

## Automatic synchronization

Set `AutoSync: 1` to push a content or media file when it is created, edited, or restored in Yellow. Auto-sync is disabled by default.

## Configuration

- `Repository`: GitHub repository in `owner/name` form.
- `Branch`: existing target branch.
- `Token`: fine-grained personal access token.
- `AutoSync`: `0` (off) or `1` (on).
- `Extensions`: comma-separated media extensions to synchronize.
- `MaxFileSizeMB`: maximum file size per upload.

## Security and limits

The token is sent only to `api.github.com` over HTTPS. Use a repository-specific token, grant only Contents read/write, and secure the INI settings file. GitHub's Contents API has file-size and request-rate limits; very large files may not be suitable for this extension.
