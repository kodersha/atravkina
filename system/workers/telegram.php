<?php
// Publish each public blog post to a Telegram channel on its first page view.

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

    public function onParsePageOutput($page, $output) {
        if ($page->get("layout") != "blog") return null;
        if (strtolower(trim($page->get("status"))) == "draft") return null;
        if (!is_file($this->configFile) || !is_readable($this->configFile)) return null;

        $config = include $this->configFile;
        if (!is_array($config) || empty($config["bot_token"]) || empty($config["channel"])) return null;

        $location = $page->getLocation();
        if (!is_string($location) || $location == "") return null;

        $this->publishOnce($page, $config, $location);
        return null;
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
        $url = $page->getLocation(true);
        $message = $this->buildMessage($content, $url);

        $sent = $this->sendMessage($config["bot_token"], $config["channel"], $message, $url);
        $handle = @fopen($this->stateFile, "c+");
        if ($handle && flock($handle, LOCK_EX)) {
            rewind($handle);
            $raw = stream_get_contents($handle);
            if (strpos($raw, $prefix) === 0) $raw = substr($raw, strlen($prefix));
            $state = $raw ? json_decode($raw, true) : array();
            if (!is_array($state)) $state = array();
            if ($sent) {
                $state[$key] = array("status" => "sent", "time" => time());
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

        $safeUrl = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
        $link = '<a href="'.$safeUrl.'">Читать на сайте →</a>';
        $body = "";

        foreach ($paragraphs as $paragraph) {

            $candidate = $body === "" ? $paragraph : $body."\n\n".$paragraph;
            if ($this->telegramTextLength($candidate."\n\n".$link) > 4096) break;
            $body = $candidate;
        }

        return ($body !== "" ? $body."\n\n" : "").$link;
    }

    private function telegramTextLength($html) {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, "UTF-8");
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
        $url = "https://api.telegram.org/bot".rawurlencode($token)."/sendMessage";
        $linkText = "Читать на сайте →";
        $linkOffset = strpos($message, $linkText);
        $entities = array();
        if ($linkOffset !== false) {
            $entities[] = array(
                "type" => "text_link",
                "offset" => $this->telegramUtf16Length(substr($message, 0, $linkOffset)),
                "length" => $this->telegramUtf16Length($linkText),
                "url" => $postUrl
            );
        }
        $data = array(
            "chat_id" => $channel,
            "text" => $message,
            "entities" => json_encode($entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "disable_web_page_preview" => false
        );
        if (function_exists("curl_init")) {
            $curl = curl_init($url);
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
            $response = @file_get_contents($url, false, stream_context_create($options));
            $status = isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $matches) ? intval($matches[1]) : 0;
        }
        if ($response === false || $status < 200 || $status >= 300) return false;
        $result = json_decode($response, true);
        return is_array($result) && !empty($result["ok"]);
    }
}
