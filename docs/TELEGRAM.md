# Telegram publishing

The Telegram worker publishes public blog posts to a channel as text messages.

The message includes the first paragraphs that fit within Telegram's 4096-character limit. If any paragraph does not fit, or the post contains images that are not sent to Telegram, a clickable **Читать на сайте →** text link is appended. If all text fits and there are no images, no website link is added.

## Configuration

Create `system/extensions/yellow-telegram/config.php` on the server. Do not commit this file or expose the bot token.

```php
<?php
return array(
    "bot_token" => "YOUR_BOT_TOKEN",
    "channel" => "@YOUR_PUBLIC_CHANNEL"
);
```

The bot must be an administrator in the channel with permission to publish messages. It no longer needs access to the linked discussion group, and no webhook or webhook secret is required.

If you previously registered the discussion webhook, remove it once. In PowerShell, run:

```powershell
$token = Read-Host "Bot token"
Invoke-RestMethod -Uri "https://api.telegram.org/bot$token/deleteWebhook?drop_pending_updates=true"
```

The current worker does not use or register a webhook.

## Behaviour

1. The worker extracts paragraphs from the blog post.
2. It checks whether all paragraphs fit in a Telegram message and whether the HTML contains images.
3. It sends the text that fits. If text was omitted or images are present, it appends the clickable **Читать на сайте →** link.
4. If the full text fits and there are no images, the post is sent without a website link.

The link is formatted as a regular HTML text link, not an inline keyboard button. Telegram's HTML parser is used, with post text and the destination URL escaped before sending.
