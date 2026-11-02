# Yellow Telegram

Automatically publishes a blog post to Telegram the first time its public page is viewed.

## Setup

1. Create a bot with [@BotFather](https://t.me/BotFather).
2. Add the bot to the `@atravkina` channel as an administrator with permission to post messages.
3. Copy `config.example.php` to `config.php`.
4. Put the bot token into `config.php`. Do not commit this file.
5. Ensure PHP can write to this directory so the sent-publication state can be stored.

Only pages with the `blog` layout are considered. Pages with `Status: draft` are skipped. A post is sent once, on its first public page view. Failed sends are not marked as sent and will be retried on a later view.

The extension sends the post title, plain-text content and a link to the original page. It does not send cover images.
