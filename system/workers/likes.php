<?php
// Simple public likes for Datenstrom Yellow.

class YellowLikes {
    const VERSION = "1.0.0";
    public $yellow;
    private $dataDirectory;
    private $dataFile;
    private $endpoint = "/yellow-likes/";

    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->dataDirectory = $yellow->system->get("coreExtensionDirectory")."yellow-likes/";
        $this->dataFile = $this->dataDirectory."votes.php";
        if (!is_dir($this->dataDirectory)) {
            @mkdir($this->dataDirectory, 0755, true);
        }
    }

    // Intercept the small JSON endpoint before Yellow tries to resolve it as a page.
    public function onRequest($scheme, $address, $base, $location, $fileName) {
        if (rtrim($location, "/")."/" != $this->endpoint) return 0;
        $this->sendJsonHeaders();
        if ($_SERVER["REQUEST_METHOD"] != "POST") {
            $this->sendJson(array("error" => "method_not_allowed"), 405);
            return 405;
        }
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? strtolower(trim(explode(";", $_SERVER["CONTENT_TYPE"])[0])) : "";
        if ($contentType != "application/json") {
            $this->sendJson(array("error" => "unsupported_media_type"), 415);
            return 415;
        }
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            $originHost = parse_url($_SERVER["HTTP_ORIGIN"], PHP_URL_HOST);
            $requestHost = isset($_SERVER["HTTP_HOST"]) ? strtolower(preg_replace("/:\\d+$/", "", $_SERVER["HTTP_HOST"])) : "";
            if (!$originHost || strtolower($originHost) != $requestHost) {
                $this->sendJson(array("error" => "origin_not_allowed"), 403);
                return 403;
            }
        }
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input) || !isset($input["action"]) || !isset($input["page"])) {
            $this->sendJson(array("error" => "invalid_request"), 400);
            return 400;
        }
        $action = $input["action"];
        $page = $this->normalisePage($input["page"]);
        if (!$page || !in_array($action, array("status", "like"), true)) {
            $this->sendJson(array("error" => "invalid_request"), 400);
            return 400;
        }
        $visitor = $this->getVisitorId();
        if ($visitor === false) {
            $this->sendJson(array("error" => "storage_unavailable"), 503);
            return 503;
        }
        $result = $this->updateVotes($page, $visitor, $action);
        if ($result === false) {
            $this->sendJson(array("error" => "storage_unavailable"), 503);
            return 503;
        }
        $this->sendJson($result, 200);
        return 200;
    }

    // Add a compact heart counter to individual blog posts only.
    public function onParsePageOutput($page, $output) {
        if ($page->get("layout") != "blog" || !is_string($output) || strpos($output, 'class="yellow-likes"') !== false) return null;
        $widget = '<div class="yellow-likes" data-yellow-likes><button class="yellow-likes__button" type="button" aria-label="Поставить лайк" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/></svg><span class="yellow-likes__count">0</span></button></div>';
        if (strpos($output, "<!--yellow-likes-slot-->") !== false) {
            $output = str_replace("<!--yellow-likes-slot-->", $widget, $output);
        } elseif (stripos($output, "</article>") !== false) {
            $output = preg_replace("/<\\/article>/i", $widget."</article>", $output, 1);
        } else {
            $output .= $widget;
        }
        $assets = '<link rel="stylesheet" href="/media/yellow-likes/likes.css"><script src="/media/yellow-likes/likes.js" defer></script>';
        if (stripos($output, "</head>") !== false) {
            $output = preg_replace("/<\\/head>/i", $assets."</head>", $output, 1);
        } else {
            $output = $assets.$output;
        }
        return $output;
    }

    private function normalisePage($page) {
        if (!is_string($page) || strlen($page) > 500 || strpos($page, "\\") !== false) return false;
        $path = parse_url($page, PHP_URL_PATH);
        if (!is_string($path) || $path === "" || $path[0] != "/" || strpos($path, "/../") !== false || strpos($path, "\0") !== false) return false;
        return rtrim($path, "/")."/";
    }

    private function getVisitorId() {
        $name = "yellow_likes_visitor";
        if (isset($_COOKIE[$name]) && preg_match("/^[a-f0-9]{64}$/", $_COOKIE[$name])) return hash("sha256", $_COOKIE[$name]);
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return false;
        }
        $secure = (!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) != "off") ||
            (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"]) == "https");
        setcookie($name, $token, array(
            "expires" => time() + 31536000,
            "path" => "/",
            "secure" => $secure,
            "httponly" => true,
            "samesite" => "Lax"
        ));
        $_COOKIE[$name] = $token;
        return hash("sha256", $token);
    }

    private function updateVotes($page, $visitor, $action) {
        $handle = @fopen($this->dataFile, "c+");
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            return false;
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $prefix = "<?php http_response_code(404); exit; ?>\\n";
        if (strpos($raw, $prefix) === 0) $raw = substr($raw, strlen($prefix));
        $data = $raw ? json_decode($raw, true) : array();
        if (!is_array($data)) $data = array();
        if (!isset($data[$page]) || !is_array($data[$page])) $data[$page] = array();
        $alreadyLiked = in_array($visitor, $data[$page], true);
        if ($action == "like" && !$alreadyLiked) {
            $data[$page][] = $visitor;
            $alreadyLiked = true;
        }
        $count = count($data[$page]);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $prefix.json_encode($data, JSON_UNESCAPED_SLASHES)) === false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return array("count" => $count, "liked" => $alreadyLiked);
    }

    private function sendJsonHeaders() {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("X-Content-Type-Options: nosniff");
        header("Vary: Cookie");
    }

    private function sendJson($data, $status) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
