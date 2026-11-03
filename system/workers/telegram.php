<?php
// Publish public blog posts to a Telegram channel after saving or on first page view.

class YellowTelegram {
    const VERSION = "1.0.0";
    public $yellow;
    private $directory;
    private $configFile;
    private $stateFile;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->directory = $yellow->system->get("coreExtensionDirectory")."yellow-telegram/";
        $this->configFile = $this->directory."config.php";
        $this->stateFile = $this->directory."sent.php";
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
        $imageUrl = $this->getFirstImageUrl($content);
        if ($imageUrl !== false) {
            $caption = $this->buildCaption($content, $url);
            $sent = $this->sendPhoto($config["bot_token"], $config["channel"], $imageUrl, $caption);
            if ($sent === false) {
                $this->yellow->toolbox->log("error", "Telegram: photo failed; falling back to text for ".$location);
                $sent = $this->sendMessage($config["bot_token"], $config["channel"], $this->buildMessage($content, $url), $url);
            }
        } else {
            $sent = $this->sendMessage($config["bot_token"], $config["channel"], $this->buildMessage($content, $url), $url);
        }
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
        else {
            $this->yellow->toolbox->log("info", "Telegram: published ".$location);
        }
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
            // If there is no paragraph markup, treat the extracted text as one block.
            $plain = html_entity_decode(strip_tags($content), ENT_QUOTES, "UTF-8");
            $plain = preg_replace("/\\s+/u", " ", trim($plain));
            if ($plain !== "") $paragraphs[] = $plain;
        }

        $fullText = implode("\n\n", $paragraphs);
        $imageCount = preg_match_all("/<img\\b/i", $content);
        $needsSiteLink = $imageCount > 1 || $this->telegramUtf16Length($fullText) > 4096;
        $linkLabel = "Читать на сайте →";
        $separator = $fullText !== "" ? "\n\n" : "";
        $bodyLimit = 4096;
        if ($needsSiteLink) $bodyLimit -= $this->telegramUtf16Length($separator.$linkLabel);

        $body = "";
        foreach ($paragraphs as $paragraph) {
            $candidate = $body === "" ? $paragraph : $body."\n\n".$paragraph;
            if ($this->telegramUtf16Length($candidate) > $bodyLimit) break;
            $body = $candidate;
        }

        // Keep a useful excerpt even when the first paragraph alone is too long.
        if ($body === "" && !empty($paragraphs) && $needsSiteLink) {
            $body = $this->truncateTelegramText($paragraphs[0], max(0, $bodyLimit - 1))."…";
        }

        $message = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        if ($needsSiteLink && is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\\/\\//i', $url)) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
            $message .= ($body !== "" ? "\n\n" : "").'<a href="'.$safeUrl.'">'.$linkLabel.'</a>';
        }

        return $message;
    }

    private function buildCaption($content, $url) {
        $paragraphs = array();
        if (preg_match_all("/<p\\b[^>]*>(.*?)<\\/p>/is", $content, $matches)) {
            foreach ($matches[1] as $paragraphHtml) {
                $paragraph = html_entity_decode(strip_tags($paragraphHtml), ENT_QUOTES, "UTF-8");
                $paragraph = preg_replace("/\\s+/u", " ", trim($paragraph));
                if ($paragraph !== "") $paragraphs[] = $paragraph;
            }
        } else {
            $plain = html_entity_decode(strip_tags($content), ENT_QUOTES, "UTF-8");
            $plain = preg_replace("/\\s+/u", " ", trim($plain));
            if ($plain !== "") $paragraphs[] = $plain;
        }

        $imageCount = preg_match_all("/<img\\b/i", $content);
        $accepted = array();
        $omitted = $imageCount > 1;
        $linkLabel = "Читать на сайте →";
        $linkValid = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\\/\\//i', $url);
        $linkLength = $linkValid ? $this->telegramUtf16Length("\\n\\n".$linkLabel) : 0;
        $limit = 1024;

        foreach ($paragraphs as $paragraph) {
            $candidate = implode("\\n\\n", array_merge($accepted, array($paragraph)));
            if ($this->telegramUtf16Length($candidate) > $limit) {
                $omitted = true;
                break;
            }
            $accepted[] = $paragraph;
        }

        while ($omitted && $linkValid && !empty($accepted) &&
            $this->telegramUtf16Length(implode("\\n\\n", $accepted)) + $linkLength > $limit) {
            array_pop($accepted);
        }

        $body = implode("\\n\\n", $accepted);
        $caption = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        if ($omitted && $linkValid) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
            $caption .= ($body !== "" ? "\\n\\n" : "").'<a href="'.$safeUrl.'">'.$linkLabel.'</a>';
        }
        return $caption;
    }

    private function truncateTelegramText($text, $maxLength) {
        if ($maxLength <= 0) return "";
        $characters = preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) return substr($text, 0, $maxLength);
        $result = "";
        $length = 0;
        foreach ($characters as $character) {
            $characterLength = strlen($character) > 3 ? 2 : 1;
            if ($length + $characterLength > $maxLength) break;
            $result .= $character;
            $length += $characterLength;
        }
        return rtrim($result);
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

    private function getFirstImageUrl($content) {
        if (!preg_match("~<img\\b[^>]*\\bsrc\\s*=\\s*([\"'])(.*?)\\1~i", $content, $matches)) return false;
        $url = html_entity_decode(trim($matches[2]), ENT_QUOTES, "UTF-8");
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\\/\\//i', $url)) return false;
        return $url;
    }

    private function sendPhoto($token, $channel, $photoUrl, $caption = "") {
        $apiUrl = "https://api.telegram.org/bot".rawurlencode($token)."/sendPhoto";
        $data = array("chat_id" => $channel, "photo" => $photoUrl, "caption" => $caption, "parse_mode" => "HTML");
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
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true
            ));
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($response === false) $transportError = curl_error($curl);
            curl_close($curl);
        } else {
            $options = array("http" => array(
                "method" => "POST",
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
                "content" => http_build_query($data),
                "timeout" => 15,
                "ignore_errors" => true
            ));
            $response = @file_get_contents($apiUrl, false, stream_context_create($options));
            $status = isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $matches) ? intval($matches[1]) : 0;
            if ($response === false) $transportError = "HTTP request failed (allow_url_fopen or TLS/network configuration)";
        }
        $result = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || !is_array($result) || empty($result["ok"])) {
            $description = is_array($result) && isset($result["description"]) ? $result["description"] : $transportError;
            $description = str_replace($token, "[redacted]", $description);
            $this->yellow->toolbox->log("error", "Telegram: sendPhoto failed (HTTP ".$status.": ".$description.")");
            return false;
        }
        return isset($result["result"]["message_id"]) ? intval($result["result"]["message_id"]) : false;
    }

    private function sendMessage($token, $channel, $message, $postUrl) {
        $apiUrl = "https://api.telegram.org/bot".rawurlencode($token)."/sendMessage";
        if (!is_string($postUrl) || !filter_var($postUrl, FILTER_VALIDATE_URL) ||
            !preg_match('/^https?:\\/\\//i', $postUrl)) {
            $this->yellow->toolbox->log("error", "Telegram: invalid absolute post URL");
            return false;
        }

        $data = array(
            "chat_id" => $channel,
            "text" => $message,
            "parse_mode" => "HTML",
            "disable_web_page_preview" => true
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
                    "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
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
