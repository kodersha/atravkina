# Yellow Likes

A small public like button for individual blog posts in Datenstrom Yellow.

## Files

- `system/workers/likes.php` — Yellow worker bootstrap (loads the extension class).
- `system/extensions/yellow-likes/likes.php` — implementation and HTTP asset endpoint.
- `system/extensions/yellow-likes/likes.js` and `likes.css` — browser assets.
- `system/extensions/yellow-likes/votes.php` / `votes.json` — private vote storage.
- `system/extensions/yellow-likes/.htaccess` — protects the storage directory on Apache.

The worker automatically adds a heart and counter to pages with the `blog` layout. No template edits or visitor accounts are required.

## Storage and visitor recognition

Vote data is stored in this directory. The visitor receives a random, HttpOnly, SameSite=Lax cookie valid for one year. Only a SHA-256 hash of the cookie value is stored with each vote. No IP address or user-agent is collected.

The cookie is an anti-duplicate convenience, not strong identity verification. Visitors can vote again after clearing cookies or using another browser/device. The endpoint checks the request method, JSON content type and Origin when provided.

## Endpoint

The worker handles `POST /yellow-likes/` with JSON:

- `{"action":"status","page":"/path/to/post/"}`
- `{"action":"like","page":"/path/to/post/"}`

The response contains `count` and `liked`.

Keep the vote store private and ensure PHP can write to this directory.
