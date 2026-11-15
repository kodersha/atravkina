# Yellow Poem

Preserves intentional line breaks in Markdown poetry blocks marked with `poem`.

## Files

- `system/workers/poem.php` — Yellow worker bootstrap.
- `system/extensions/yellow-poem/poem.php` — rendering and stylesheet endpoint.
- `system/extensions/yellow-poem/poem.css` — poem styling.

The worker loads the extension implementation, which removes the `<code>` wrapper from `<pre class="poem"><code>…</code></pre>` and serves the stylesheet at `/yellow-poem/poem.css`.
