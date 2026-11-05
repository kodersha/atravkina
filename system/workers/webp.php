<?php

// WebP extension for Yellow, https://github.com/kodersha/atravkina

class YellowWebp
{
    const VERSION = "0.9.0";
    public $yellow;

    public function onLoad($yellow)
    {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("webpExcludeContentType", "xml");
        $this->yellow->system->setDefault("webpDirectory", "webp");
        $this->yellow->system->setDefault("webpSupportType", "jpeg,png");
    }

    // Convert eligible image URLs in generated page output.
    public function onParsePageOutput($page, $text)
    {
        if (!is_string($text) || $text === "" || !$this->acceptsWebp() || !$this->basic_checks()) {
            return $text;
        }

        $exclude = $this->yellow->system->getHtml("webpExcludeContentType");
        if ($exclude !== "" && @preg_match("/".$exclude."/i", $page->getRequest("page"))) {
            return $text;
        }

        if (!class_exists("DOMDocument") || !function_exists("mb_convert_encoding")) {
            return $text;
        }

        $previousErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument("1.0", "UTF-8");
        $html = mb_convert_encoding($text, "HTML-ENTITIES", "UTF-8");
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded) {
            return $text;
        }

        foreach ($dom->getElementsByTagName("img") as $node) {
            $this->serve_node($node, "src");
            $this->serve_node($node, "data-src");
            $this->serve_srcset($node);
        }
        foreach ($dom->getElementsByTagName("a") as $node) {
            $this->serve_node($node, "href");
        }
        foreach ($dom->getElementsByTagName("video") as $node) {
            $this->serve_node($node, "poster");
        }
        foreach ($dom->getElementsByTagName("source") as $node) {
            $this->serve_node($node, "src");
            $this->serve_srcset($node);
        }

        $result = $dom->saveHTML();
        return $result !== false ? $result : $text;
    }

    public function acceptsWebp()
    {
        if (!isset($_SERVER["HTTP_ACCEPT"])) {
            return false;
        }
        return (bool)preg_match(
            "/(?:^|,)\s*image\/webp\s*(?:;q=(?!0(?:\.0*)?)[0-9.]+)?\s*(?:,|$)/i",
            $_SERVER["HTTP_ACCEPT"]
        );
    }

    public function basic_checks()
    {
        return function_exists("imagewebp") &&
            function_exists("imagecreatefromjpeg") &&
            function_exists("imagecreatefrompng") &&
            function_exists("imagecreatetruecolor");
    }

    public function is_excluded($node)
    {
        return in_array(
            strtolower($node->getAttribute("data-webpconverter-exclude")),
            array("1", "on", "yes", "true"),
            true
        );
    }

    public function serve_node($node, $attribute)
    {
        if ($this->is_excluded($node) || !$node->hasAttribute($attribute)) {
            return;
        }
        $src = trim($node->getAttribute($attribute));
        if ($src !== "") {
            $node->setAttribute($attribute, $this->convert($src));
        }
    }

    // Rewrite each URL candidate independently; keep its width/density descriptor.
    public function serve_srcset($node)
    {
        if ($this->is_excluded($node) || !$node->hasAttribute("srcset")) {
            return;
        }

        $srcset = trim($node->getAttribute("srcset"));
        if ($srcset === "" || stripos($srcset, "data:") !== false) {
            // A data URI contains a comma and makes simple candidate splitting ambiguous.
            return;
        }

        $candidates = preg_split("/\s*,\s*/", $srcset);
        foreach ($candidates as &$candidate) {
            $candidate = trim($candidate);
            if ($candidate === "") {
                continue;
            }
            $parts = preg_split("/\s+/", $candidate, 2);
            $parts[0] = $this->convert($parts[0]);
            $candidate = isset($parts[1]) ? $parts[0]." ".$parts[1] : $parts[0];
        }
        unset($candidate);
        $node->setAttribute("srcset", implode(", ", $candidates));
    }

    // Return the filesystem directory used by Yellow for core images.
    public function get_image_directory()
    {
        if (!isset($this->yellow->lookup) ||
            !method_exists($this->yellow->lookup, "findMediaDirectory")) {
            return false;
        }
        $path = $this->yellow->lookup->findMediaDirectory("coreImageLocation");
        if (!is_string($path) || $path === "") {
            return false;
        }
        $realPath = realpath($path);
        return $realPath !== false && is_dir($realPath) ? $realPath : false;
    }

    // Return the URL path corresponding to Yellow's core image directory.
    public function get_image_url_path()
    {
        $serverBase = (string)$this->yellow->system->get("coreServerBase");
        $imageLocation = (string)$this->yellow->system->get("coreImageLocation");
        $url = rtrim($serverBase, "/")."/".ltrim($imageLocation, "/");
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === "") {
            $path = "/".trim($imageLocation, "/");
        }
        return rtrim("/".trim($path, "/"), "/")."/";
    }

    public function get_cache_directory()
    {
        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) {
            return false;
        }

        $cacheName = trim((string)$this->yellow->system->get("webpDirectory"), "/\\");
        if ($cacheName === "" || strpos($cacheName, "..") !== false ||
            strpos($cacheName, ":") !== false) {
            return false;
        }

        return $imageDirectory.DIRECTORY_SEPARATOR.str_replace(
            array("/", "\\"),
            DIRECTORY_SEPARATOR,
            $cacheName
        );
    }

    // Resolve a local image URL to a file under Yellow's configured image directory.
    public function resolve_source($srcIn)
    {
        $parts = parse_url($srcIn);
        if ($parts === false || !isset($parts["path"]) || $parts["path"] === "") {
            return false;
        }

        // Permit absolute URLs only when they point back to this Yellow site.
        if (isset($parts["host"])) {
            $baseParts = parse_url((string)$this->yellow->system->get("coreServerBase"));
            if ($baseParts === false || empty($baseParts["host"]) ||
                strtolower($parts["host"]) !== strtolower($baseParts["host"]) ||
                (isset($parts["port"]) ? $parts["port"] : null) !==
                (isset($baseParts["port"]) ? $baseParts["port"] : null)) {
                return false;
            }
        }
        if (isset($parts["scheme"]) && !in_array(strtolower($parts["scheme"]), array("http", "https"), true)) {
            return false;
        }

        $urlPath = rawurldecode($parts["path"]);
        $urlPath = "/".ltrim($urlPath, "/");
        if (strpos($urlPath, "\0") !== false) {
            return false;
        }

        $imageUrlPath = $this->get_image_url_path();
        if (strpos($urlPath, $imageUrlPath) !== 0) {
            return false;
        }

        $relative = substr($urlPath, strlen($imageUrlPath));
        $relative = str_replace("\\", "/", $relative);
        if ($relative === "" || preg_match("#(^|/)\.\.?(/|$)#", $relative)) {
            return false;
        }

        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) {
            return false;
        }

        $source = realpath($imageDirectory.DIRECTORY_SEPARATOR.str_replace(
            "/",
            DIRECTORY_SEPARATOR,
            $relative
        ));
        $rootPrefix = rtrim($imageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($source === false || strpos($source, $rootPrefix) !== 0 || !is_file($source) || !is_readable($source)) {
            return false;
        }

        return array(
            "file" => $source,
            "relative" => str_replace("\\", "/", substr($source, strlen($rootPrefix)))
        );
    }

    public function get_supported_mime_types()
    {
        $types = explode(",", strtolower((string)$this->yellow->system->get("webpSupportType")));
        $supported = array();
        foreach ($types as $type) {
            $type = trim($type);
            if ($type === "jpg") {
                $type = "jpeg";
            }
            if ($type === "jpeg") {
                $supported[] = "image/jpeg";
            } elseif ($type === "png") {
                $supported[] = "image/png";
            }
        }
        return array_unique($supported);
    }

    // Convert a local JPEG/PNG only when the WebP file is valid and smaller.
    public function convert($srcIn)
    {
        $parts = parse_url($srcIn);
        $sourceInfo = $this->resolve_source($srcIn);
        if ($sourceInfo === false) {
            return $srcIn;
        }

        $mime = "";
        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $sourceInfo["file"]);
                finfo_close($finfo);
            }
        }
        if ($mime === "" && function_exists("mime_content_type")) {
            $mime = mime_content_type($sourceInfo["file"]);
        }
        if ($mime === "" || !in_array($mime, $this->get_supported_mime_types(), true)) {
            return $srcIn;
        }

        $cacheDirectory = $this->get_cache_directory();
        if ($cacheDirectory === false) {
            return $srcIn;
        }

        $relativeDirectory = dirname($sourceInfo["relative"]);
        $targetDirectory = $cacheDirectory;
        if ($relativeDirectory !== "." && $relativeDirectory !== "") {
            $targetDirectory .= DIRECTORY_SEPARATOR.str_replace(
                "/",
                DIRECTORY_SEPARATOR,
                $relativeDirectory
            );
        }
        $targetName = basename($sourceInfo["relative"]).".webp";
        $targetFile = $targetDirectory.DIRECTORY_SEPARATOR.$targetName;

        $sourceMtime = filemtime($sourceInfo["file"]);
        $sourceSize = filesize($sourceInfo["file"]);
        if ($sourceMtime === false || $sourceSize === false) {
            return $srcIn;
        }

        if (!is_file($targetFile) || filemtime($targetFile) < $sourceMtime || filesize($targetFile) <= 0) {
            if (!is_dir($targetDirectory) &&
                !@mkdir($targetDirectory, 0775, true) &&
                !is_dir($targetDirectory)) {
                return $srcIn;
            }

            $image = false;
            if ($mime === "image/jpeg") {
                $image = @imagecreatefromjpeg($sourceInfo["file"]);
            } elseif ($mime === "image/png") {
                $image = @imagecreatefrompng($sourceInfo["file"]);
            }
            if ($image === false) {
                return $srcIn;
            }

            if ($mime === "image/png") {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            } elseif (function_exists("imageistruecolor") &&
                !imageistruecolor($image) &&
                function_exists("imagepalettetotruecolor")) {
                imagepalettetotruecolor($image);
            }

            $temporaryFile = @tempnam($targetDirectory, "webp_");
            if ($temporaryFile === false) {
                imagedestroy($image);
                return $srcIn;
            }

            $converted = @imagewebp($image, $temporaryFile);
            imagedestroy($image);

            if (!$converted || !is_file($temporaryFile) || filesize($temporaryFile) <= 0) {
                @unlink($temporaryFile);
                return $srcIn;
            }

            // Replace the cached file only after a complete successful conversion.
            if (!@rename($temporaryFile, $targetFile)) {
                // Windows does not replace an existing destination with rename().
                if (is_file($targetFile)) {
                    @unlink($targetFile);
                }
                if (!@rename($temporaryFile, $targetFile)) {
                    @unlink($temporaryFile);
                    return $srcIn;
                }
            }
            @touch($targetFile, $sourceMtime);
        }

        clearstatcache(true, $targetFile);
        $targetSize = is_file($targetFile) ? filesize($targetFile) : false;
        if ($targetSize === false || $targetSize <= 0 || $targetSize >= $sourceSize) {
            return $srcIn;
        }

        $webpUrl = $this->get_image_url_path().
            trim((string)$this->yellow->system->get("webpDirectory"), "/\\")."/".
            ($relativeDirectory !== "." && $relativeDirectory !== "" ? $relativeDirectory."/" : "").
            rawurlencode($targetName);

        // Encode each path segment while preserving directory separators.
        $segments = explode("/", $webpUrl);
        foreach ($segments as &$segment) {
            if ($segment !== "" && strpos($segment, ":") === false) {
                $segment = rawurlencode(rawurldecode($segment));
            }
        }
        unset($segment);
        $webpUrl = implode("/", $segments);

        if (isset($parts["query"])) {
            $webpUrl .= "?".$parts["query"];
        }
        if (isset($parts["fragment"])) {
            $webpUrl .= "#".$parts["fragment"];
        }
        return $webpUrl;
    }
}
