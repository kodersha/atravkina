# Yellow Likes

A small public like button for individual blog posts in Datenstrom Yellow.

## Installation

Copy these files into the matching paths:

- `system/workers/likes.php`
- `media/yellow-likes/likes.js`
- `media/yellow-likes/likes.css`
- `system/extensions/yellow-likes/.htaccess`

The worker automatically adds a heart and counter to pages with the `blog` layout. No template edits or visitor accounts are required.

## Storage and visitor recognition

Vote data is stored in `system/extensions/yellow-likes/votes.php`. The directory is protected by an `.htaccess` rule on Apache. The visitor receives a random, HttpOnly, SameSite=Lax cookie valid for one year. Only a SHA-256 hash of the cookie value is stored with each vote. No IP address or user-agent is collected.

The cookie is an anti-duplicate convenience, not strong identity verification. Visitors can vote again after clearing cookies or using another browser/device. The endpoint also checks the request method, JSON content type and Origin when provided.

## Endpoint

The worker handles `POST /yellow-likes/` with JSON:

- `{"action":"status","page":"/path/to/post/"}`
- `{"action":"like","page":"/path/to/post/"}`

The response contains `count` and `liked`.

## Notes

The first request creates the private vote store. The data file begins with a PHP guard that immediately returns 404 if requested directly. Ensure PHP can write to `system/extensions/yellow-likes/`. Keep the `.htaccess` rule as an additional layer on Apache.
