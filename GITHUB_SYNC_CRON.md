# GitHub Sync: nightly synchronization

GitHub synchronization must run from the command line, not during a website request.
This keeps post saves and media uploads independent of GitHub API response times.

## Command

Run from the website installation directory:

```sh
php yellow.php github-sync all
```

The command synchronizes the contents of `content/` and `media/`, including
subdirectories. It uses the repository, token and branch configured in
`system/extensions/yellow-github-sync.ini`.

You can also run only one scope:

```sh
php yellow.php github-sync content
php yellow.php github-sync media
```

## Linux cron example

In the hosting control panel, create a daily cron task for 03:00 server time.
Use the website's absolute installation path and the absolute path to PHP:

```cron
0 3 * * * cd /ABSOLUTE/PATH/TO/SITE && /usr/bin/php yellow.php github-sync all >> system/logs/githubsync-cron.log 2>&1
```

Replace `/ABSOLUTE/PATH/TO/SITE` and `/usr/bin/php` with the actual paths
provided by the host. Ensure the PHP CLI version has cURL and can read the
configuration file. The cron task runs independently of visitor traffic.

## Important

- Web requests never call the GitHub API. Uploads and post saves finish locally.
- Failed files remain different from the saved synchronization state and are
  retried on the next scheduled run.
- The local state file is stored at
  `system/extensions/.yellow-github-sync-state.json` and is excluded from Git.
- The `AutoSync` setting no longer enables request-time synchronization.
