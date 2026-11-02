<?php
// Publish public blog posts to a Telegram channel after saving or on first page view.

class YellowTelegram {
    const VERSION = "1.0.0";
    public $yellow;
    private $directory;
    private $configFile;
    private $stateFile;
    private $webhookPath = "/.well-known/telegram-webhook";
    private $webhookUrl = "https://atravkina.ru/.well-known/telegram-webhook";

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->directory = $yellow->system->get("coreExtensionDirectory")."yellow-telegram/";
        $this->configFile = $this->directory."config.php";
        $this->stateFile = $this->directory."sent.php";
    }


    // Handle Telegram webhook before normal page routing.
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        if (rtrim($location, "/") != $this->webhookPath) return 0;
        if ($_SERVER["REQUEST_METHOD"] != "POST") return $this->webhookResponse(405, array("ok" => false));
        if (!is_file($this->configFile) || !is_readable($this->configFile)) return $this->webhookResponse(503, array("ok" => false));
        $config = include $this->configFile;
        $secret = is_array($config) && isset($config["webhook_secret"]) ? $config["webhook_secret"] : "";
        $provided = isset($_SERVER["HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN"]) ? $_SERVER["HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN"] : "";
        if (!is_string($secret) || strlen($secret) < 32 || !hash_equals($secret, $provided)) return $this->webhookResponse(403, array("ok" => false));
        $update = json_decode(file_get_contents("php://input"), true);
        if (is_array($update)) $this->handleTelegramUpdate($update, $config);
        return $this->webhookResponse(200, array("ok" => true));
    }

    public function onCommand($command, $text) {
        if ($command != "telegram-webhook") return 0;
        if (!is_file($this->configFile) || !is_readable($this->configFile)) return 400;
        $config = include $this->configFile;
        if (!is_array($config) || empty($config["bot_token"]) || empty($config["webhook_secret"]) || strlen($config["webhook_secret"]) < 32) {
            echo "Telegram: configure bot_token and webhook_secret (at least 32 characters)\n";
            return 400;
        }
        $result = $this->telegramApi($config["bot_token"], "setWebhook", array(
            "url" => $this->webhookUrl,
            "secret_token" => $config["webhook_secret"],
            "allowed_updates" => json_encode(array("message")),
            "drop_pending_updates" => false
        ));
        if (is_array($result) && !empty($result["ok"])) {
            echo "Telegram webhook registered: ".$this->webhookUrl."\n";
            return 200;
        }
        return 500;
    }

    public function onCommandHelp() {
        return "telegram-webhook";
    }

    private function webhookResponse($status, $data) {
        if (!headers_sent()) {
            http_response_code($status);
            header("Content-Type: application/json; charset=utf-8");
            header("Cache-Control: no-store");
        }
        echo json_encode($data);
        return $status;
    }

    private function handleTelegramUpdate($update, $config) {
        if (empty($update["message"]["is_automatic_forward"])) return;
        $message = $update["message"];
        $origin = isset($message["forward_origin"]) ? $message["forward_origin"] : array();
        if (!isset($origin["type"]) || $origin["type"] != "channel" || empty($origin["message_id"]) || empty($origin["chat"]["username"])) return;
        if (empty($message["message_id"]) || empty($message["chat"]["id"])) return;
        $username = isset($config["channel_username"]) ? ltrim($config["channel_username"], "@") : (isset($config["channel"]) && substr($config["channel"], 0, 1) == "@" ? substr($config["channel"], 1) : "");
        if ($username == "" || strcasecmp($username, $origin["chat"]["username"]) != 0) return;

        $handle = @fopen($this->stateFile, "c+");
        if (!$handle || !flock($handle, LOCK_EX)) { if ($handle) fclose($handle); return; }
        $prefix = "<?php http_response_code(404); exit; ?>\n";
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (strpos($raw, $prefix) === 0) $raw = substr($raw, strlen($prefix));
        $state = $raw ? json_decode($raw, true) : array();
        if (!is_array($state)) $state = array();
        $matchedKey = null;
        foreach ($state as $key => $item) {
            if (isset($item["status"], $item["channel_message_id"]) && $item["status"] == "sent" && intval($item["channel_message_id"]) == intval($origin["message_id"])) {
                $matchedKey = $key;
                break;
            }
        }
        if ($matchedKey === null) { flock($handle, LOCK_UN); fclose($handle); return; }
        $state[$matchedKey]["discussion_message_id"] = intval($message["message_id"]);
        $state[$matchedKey]["discussion_chat_id"] = $message["chat"]["id"];
        $postUrl = isset($state[$matchedKey]["post_url"]) ? $state[$matchedKey]["post_url"] : "";
        $channelMessageId = intval($state[$matchedKey]["channel_message_id"]);
        $this->writeState($handle, $prefix, $state);
        flock($handle, LOCK_UN);
        fclose($handle);

        $commentUrl = "https://t.me/".$username."/".$channelMessageId."?comment=".intval($message["message_id"]);
        $keyboard = array("inline_keyboard" => array(array(
            array("text" => "Читать на сайте →", "url" => $postUrl),
            array("text" => "Комментировать", "url" => $commentUrl)
        )));
        $result = $this->telegramApi($config["bot_token"], "editMessageReplyMarkup", array(
            "chat_id" => $config["channel"],
            "message_id" => $channelMessageId,
            "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
        if (!is_array($result) || empty($result["ok"])) $this->yellow->toolbox->log("error", "Telegram: cannot add discussion button to post ".$channelMessageId);
    }

    private function telegramApi($token, $method, $data) {
        $apiUrl = "https://api.telegram.org/bot".rawurlencode($token)."/".$method;
        $curl = curl_init($apiUrl);
        curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true));
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($curl) : "";
        curl_close($curl);
        $result = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($result) || empty($result["ok"])) {
            $description = is_array($result) && isset($result["description"]) ? str_replace($token, "[redacted]", $result["description"]) : $error;
            $this->yellow->toolbox->log("error", "Telegram: ".$method." failed (HTTP ".$status."): ".$description);
            return false;
        }
        return $result;
    }

    // Publish immediately after the editor has successfully saved a page.
    public function onSaveContentFile($page) {
        $this->publishPage($page);
    }

    // Keep the page-view hook as a fallback for posts created outside the editor.
    public function onParsePageOutput($page, $output) {
        $this->publishPage($page);
        return null;
    }

    private function publishPage($page) {
        if ($page->get("layout") != "blog") return;
        if (strtolower(trim($page->get("status"))) == "draft") return;
        if (!is_file($this->configFile) || !is_readable($this->configFile)) return;

        $config = include $this->configFile;
        if (!is_array($config) || empty($config["bot_token"]) || empty($config["channel"])) return;

        $location = $page->getLocation();
        if (!is_string($location) || $location == "") return;

        $this->publishOnce($page, $config, $location);
    }

    private function publishOnce($page, $config, $location) {
        $handle = @fopen($this->stateFile, "c+");
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            $this->yellow->toolbox->log("error", "Telegram: cannot lock publication state");
            return;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $prefix = "<?php http_response_code(404); exit; ?>\n";
        if (strpos($raw, $prefix) === 0) $raw = substr($raw, strlen($prefix));
        $state = $raw ? json_decode($raw, true) : array();
        if (!is_array($state)) $state = array();

        $key = hash("sha256", $location);
        if (isset($state[$key]) && (
            (isset($state[$key]["status"]) && $state[$key]["status"] == "sent") ||
            (isset($state[$key]["status"]) && $state[$key]["status"] == "sending" && time() - intval($state[$key]["time"]) < 300)
        )) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $state[$key] = array("status" => "sending", "time" => time());
        $this->writeState($handle, $prefix, $state);
        flock($handle, LOCK_UN);
        fclose($handle);

        $content = method_exists($page, "getContentHtml") ? $page->getContentHtml() : "";
        $url = method_exists($page, "getUrl") ? $page->getUrl(true) : "";
        $message = $this->buildMessage($content, $url);
        $sent = $this->sendMessage($config["bot_token"], $config["channel"], $message, $url);
        $handle = @fopen($this->stateFile, "c+");
        if ($handle && flock($handle, LOCK_EX)) {
            rewind($handle);
            $raw = stream_get_contents($handle);
            if (strpos($raw, $prefix) === 0) $raw = substr($raw, strlen($prefix));
            $state = $raw ? json_decode($raw, true) : array();
            if (!is_array($state)) $state = array();
            if ($sent !== false) {
                $state[$key] = array("status" => "sent", "time" => time(), "channel_message_id" => intval($sent), "post_url" => $url);
            } else {
                unset($state[$key]);
            }
            $this->writeState($handle, $prefix, $state);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!$sent) $this->yellow->toolbox->log("error", "Telegram: publication failed for ".$location);
        else $this->yellow->toolbox->log("info", "Telegram: published ".$location);
    }

    private function buildMessage($content, $url) {
        $paragraphs = array();
        if (preg_match_all("/<p\\b[^>]*>(.*?)<\\/p>/is", $content, $matches)) {
            foreach ($matches[1] as $paragraphHtml) {
                $paragraph = html_entity_decode(strip_tags($paragraphHtml), ENT_QUOTES, "UTF-8");
                $paragraph = preg_replace("/\\s+/u", " ", trim($paragraph));
                if ($paragraph !== "") $paragraphs[] = $paragraph;
            }
        } else {
            // If the page has no paragraph markup, treat its plain text as one indivisible block.
            $plain = html_entity_decode(strip_tags($content), ENT_QUOTES, "UTF-8");
            $plain = preg_replace("/\\s+/u", " ", trim($plain));
            if ($plain !== "") $paragraphs[] = $plain;
        }

        $body = "";

        foreach ($paragraphs as $paragraph) {

            $candidate = $body === "" ? $paragraph : $body."\n\n".$paragraph;
            if ($this->telegramTextLength($candidate) > 4096) break;
            $body = $candidate;
        }

        return $body;
    }

    private function telegramTextLength($text) {
        if (function_exists("mb_strlen")) return mb_strlen($text, "UTF-8");
        if (preg_match_all("/./us", $text, $matches)) return count($matches[0]);
        return strlen($text);
    }

    private function writeState($handle, $prefix, $state) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $prefix.json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    private function telegramUtf16Length($text) {
        if (function_exists("mb_convert_encoding")) {
            return strlen(mb_convert_encoding($text, "UTF-16LE", "UTF-8")) / 2;
        }
        if (preg_match_all("/./us", $text, $matches)) {
            $length = 0;
            foreach ($matches[0] as $character) {
                $length += strlen($character) > 3 ? 2 : 1;
            }
            return $length;
        }
        return strlen($text);
    }

    private function sendMessage($token, $channel, $message, $postUrl) {
        $apiUrl = "https://api.telegram.org/bot".rawurlencode($token)."/sendMessage";
        if (!is_string($postUrl) || !filter_var($postUrl, FILTER_VALIDATE_URL) ||
            !preg_match('/^https?:\\/\\//i', $postUrl)) {
            $this->yellow->toolbox->log("error", "Telegram: invalid absolute post URL");
            return false;
        }

        $keyboard = array("inline_keyboard" => array(array(
            array("text" => "Читать на сайте →", "url" => $postUrl)
        )));
        $data = array(
            "chat_id" => $channel,
            "text" => $message,
            "reply_markup" => json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "disable_web_page_preview" => false
        );

        $response = false;
        $status = 0;
        $transportError = "";
        if (function_exists("curl_init")) {
            $curl = curl_init($apiUrl);
            curl_setopt_array($curl, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true
            ));
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($response === false) $transportError = curl_error($curl);
            curl_close($curl);
        } else {
            $options = array(
                "http" => array(
                    "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\\r\\n",
                    "content" => http_build_query($data),
                    "timeout" => 8,
                    "ignore_errors" => true
                )
            );
            $response = @file_get_contents($apiUrl, false, stream_context_create($options));
            $status = isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $matches) ? intval($matches[1]) : 0;
            if ($response === false) $transportError = "HTTP request failed (allow_url_fopen or TLS/network configuration)";
        }

        $result = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || !is_array($result) || empty($result["ok"])) {
            $details = "";
            if (is_array($result)) {
                $errorCode = isset($result["error_code"]) ? intval($result["error_code"]) : $status;
                $description = isset($result["description"]) ? $result["description"] : "Telegram API rejected the request";
                $description = str_replace($token, "[redacted]", $description);
                $details = " (HTTP ".$status.", API ".$errorCode.": ".$description.")";
            } elseif ($transportError !== "") {
                $details = " (HTTP ".$status.", ".$transportError.")";
            } else {
                $details = " (HTTP ".$status.", invalid Telegram API response)";
            }
            $this->yellow->toolbox->log("error", "Telegram: sendMessage failed".$details);
            return false;
        }
        return isset($result["result"]["message_id"]) ? intval($result["result"]["message_id"]) : false;
    }
}
