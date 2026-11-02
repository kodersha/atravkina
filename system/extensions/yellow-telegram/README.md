# Yellow Telegram

Automatically publishes a blog post to Telegram the first time its public page is viewed.

## Setup

1. Create a bot with [@BotFather](https://t.me/BotFather).
2. Add the bot to the `@atravkina` channel as an administrator with permission to post messages.
3. Copy `config.example.php` to `config.php`.
4. Put the bot token into `config.php`. Do not commit this file.
5. Ensure PHP can write to this directory so the sent-publication state can be stored.

Only pages with the `blog` layout are considered. Pages with `Status: draft` are skipped. A post is sent once, on its first public page view. Failed sends are not marked as sent and will be retried on a later view.

The extension omits the post title and sends the body as plain text. Paragraphs are included whole, in order, while they fit within Telegram's 4096-character message limit together with the final **Читать на сайте →** link. The footer label is linked to the post URL using Telegram's `text_link` message entity (not HTML parsing). The first paragraph that does not fit, and all following paragraphs, are omitted. Telegram generates the link preview from the post URL. To control the preview image, provide suitable Open Graph metadata (`og:image`) on the post page.
