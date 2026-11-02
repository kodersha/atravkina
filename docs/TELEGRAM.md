# Telegram publishing

The Telegram worker publishes public blog posts to a channel. Each post gets a **Читать на сайте →** inline button. Once Telegram delivers the channel's automatic forward to its linked discussion group, the worker adds a **Комментировать** button that opens that exact discussion thread.

## Configuration

Create `system/extensions/yellow-telegram/config.php` on the server. Do not commit this file or expose the bot token.

```php
<?php
return array(
    "bot_token" => "YOUR_BOT_TOKEN",
    "channel" => "@YOUR_PUBLIC_CHANNEL",
    "channel_username" => "YOUR_PUBLIC_CHANNEL",
    "webhook_secret" => "GENERATE_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS_LONG"
);
```

Use the public channel username without the leading `@` for `channel_username`. The bot must be an administrator in the channel and in its linked discussion group. It must be able to publish/edit channel messages and receive group messages.

## Register the webhook

The webhook endpoint is:

`https://atravkina.ru/.well-known/telegram-webhook`

After uploading the updated worker and creating the configuration file, run this from the Yellow installation directory:

```sh
php yellow.php telegram-webhook
```

The command registers the endpoint with Telegram and configures the secret-token header. The endpoint accepts only POST requests with the matching `X-Telegram-Bot-Api-Secret-Token` header.

## Behaviour

1. The worker publishes the post with the website inline button.
2. Telegram automatically forwards the channel post to the linked discussion group.
3. The webhook receives the automatic-forward update, matches its source channel message ID to the stored publication, and adds the discussion button to the channel post.
4. The comment button opens the thread for that specific forwarded message.

The first post published after deployment should be used to verify the complete flow. Posts already published before this feature was installed are not backfilled automatically.
