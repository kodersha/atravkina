# Yellow Telegram

Publishes a blog post to the configured Telegram channel only after a successful save in the editor.

Only pages with the `blog` layout and without `Status: draft` or `Status: hide` are considered. Each post location is tracked in the private `sent.php` state file. A post marked `sent` is never sent again, including after later edits or page views. A concurrent or interrupted `sending` attempt is also blocked to avoid duplicate delivery.

When the post contains an image, the extension sends the first image with `sendPhoto` and places the text in its caption. It does not silently replace a failed photo publication with a text-only message. If delivery fails or its result is uncertain, the state remains reserved; inspect the Telegram log and reset that post's entry in `sent.php` manually only after confirming that Telegram did not receive it.

Posts without images are sent with `sendMessage`. The title is omitted. Keep `config.php` and `sent.php` private; both are excluded by this directory's `.gitignore`.
