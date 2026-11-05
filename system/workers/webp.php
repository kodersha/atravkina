<?php

// WebP extension for Yellow, https://github.com/kodersha/atravkina

class YellowWebp
{
    const VERSION = "0.9.1";
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
        foreach (explode(",", $_SERVER["HTTP_ACCEPT"]) as $mediaRange) {
            $parts = array_map("trim", explode(";", $mediaRange));
            if (strtolower($parts[0]) !== "image/webp") {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match("/^q\s*=\s*([0-9.]+)$/i", $parameter, $matches)) {
                    $quality = (float)$matches[1];
                    break;
                }
            }
            return $quality > 0.0 && $quality <= 1.0;
        }
        return false;
    }

    public function basic_checks()
    {
        return function_exists("imagewebp") &&
            function_exists("imagecreatefromjpeg") &&
            function_exists("imagecreatefrompng");
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

    // Parse srcset candidates without splitting commas that belong to a URL.
    public function serve_srcset($node)
    {
        if ($this->is_excluded($node) || !$node->hasAttribute("srcset")) {
            return;
        }

        $srcset = trim($node->getAttribute("srcset"));
        if ($srcset === "") {
            return;
        }

        $length = strlen($srcset);
        $offset = 0;
        $candidates = array();
        while ($offset < $length) {
            while ($offset < $length && (ctype_space($srcset[$offset]) || $srcset[$offset] === ",")) {
                ++$offset;
            }
            if ($offset >= $length) {
                break;
            }

            $urlStart = $offset;
            while ($offset < $length && !ctype_space($srcset[$offset])) {
                ++$offset;
            }
            $url = substr($srcset, $urlStart, $offset - $urlStart);
            $trailingCommas = strlen($url) - strlen(rtrim($url, ","));
            if ($trailingCommas > 0) {
                $url = substr($url, 0, -$trailingCommas);
                $descriptor = "";
            } else {
                while ($offset < $length && ctype_space($srcset[$offset])) {
                    ++$offset;
                }
                $descriptorStart = $offset;
                while ($offset < $length && $srcset[$offset] !== ",") {
                    ++$offset;
                }
                $descriptor = trim(substr($srcset, $descriptorStart, $offset - $descriptorStart));
            }

            if ($url !== "") {
                $url = $this->convert($url);
                $candidates[] = $url.($descriptor !== "" ? " ".$descriptor : "");
            }
            if ($offset < $length && $srcset[$offset] === ",") {
                ++$offset;
            }
        }

        if (!empty($candidates)) {
            $node->setAttribute("srcset", implode(", ", $candidates));
        }
    }

    // Return the absolute filesystem directory used by Yellow for core images.
    public function get_image_directory()
    {
        if (!isset($this->yellow->lookup) ||
            !method_exists($this->yellow->lookup, "findMediaDirectory")) {
            return false;
        }

        $mediaDirectory = $this->yellow->lookup->findMediaDirectory("coreImageLocation");
        if (!is_string($mediaDirectory) || $mediaDirectory === "") {
            return false;
        }

        $installDirectory = $this->yellow->system->get("coreServerInstallDirectory");
        $candidate = rtrim((string)$installDirectory, "/\\").DIRECTORY_SEPARATOR.
            ltrim($mediaDirectory, "/\\");
        $realPath = realpath($candidate);
        if ($realPath === false) {
            // Some Yellow installations return an already absolute media path.
            $realPath = realpath($mediaDirectory);
        }
        return $realPath !== false && is_dir($realPath) ? $realPath : false;
    }

    // Return the public URL path corresponding to Yellow's configured image directory.
    public function get_image_url_path()
    {
        $imageLocation = (string)$this->yellow->system->get("coreImageLocation");
        $base = isset($this->yellow->page->base) ? (string)$this->yellow->page->base : "";
        $basePath = parse_url($base, PHP_URL_PATH);
        if (!is_string($basePath)) {
            $basePath = "";
        }

        $path = rtrim($basePath, "/")."/".ltrim($imageLocation, "/");
        $path = "/".trim($path, "/");
        return rtrim(rawurldecode($path), "/")."/";
    }

    public function get_cache_directory()
    {
        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) {
            return false;
        }

        $cacheName = trim((string)$this->yellow->system->get("webpDirectory"), "/\\");
        if ($cacheName === "" || preg_match("#(^|[\\/])\.\.?([\\/]|$)#", $cacheName) ||
            strpos($cacheName, ":") !== false || strpos($cacheName, "\0") !== false) {
            return false;
        }

        $cachePath = $imageDirectory.DIRECTORY_SEPARATOR.str_replace(
            array("/", "\\"),
            DIRECTORY_SEPARATOR, $cacheName
        );
        return $cachePath;
    }

    // Resolve a local image URL to a file contained in Yellow's configured image directory.
    public function resolve_source($srcIn)
    {
        $parts = parse_url($srcIn);
        if ($parts === false || !isset($parts["path"]) || $parts["path"] === "") {
            return false;
        }
        if (isset($parts["scheme"]) && !in_array(strtolower($parts["scheme"]), array("http", "https"), true)) {
            return false;
        }

        if (isset($parts["host"])) {
            $base = isset($this->yellow->page->base) ? (string)$this->yellow->page->base : "";
            $baseParts = parse_url($base);
            if ($baseParts === false || empty($baseParts["host"]) ||
                strtolower($parts["host"]) !== strtolower($baseParts["host"]) ||
                (isset($parts["port"]) ? (int)$parts["port"] : null) !==
                (isset($baseParts["port"]) ? (int)$baseParts["port"] : null)) {
                return false;
            }
        }

        $urlPath = rawurldecode($parts["path"]);
        if (strpos($urlPath, "\0") !== false || strpos($urlPath, "\\") !== false) {
            return false;
        }
        if (substr($urlPath, 0, 1) !== "/") {
            $base = isset($this->yellow->page->base) ? (string)$this->yellow->page->base : "";
            $basePath = parse_url($base, PHP_URL_PATH);
            if (!is_string($basePath)) {
                $basePath = "";
            }
            $pageLocation = isset($this->yellow->page->location) ? (string)$this->yellow->page->location : "/";
            $urlPath = rtrim($basePath, "/")."/".trim($pageLocation, "/")."/".$urlPath;
        }
        $urlPath = $this->normalise_url_path($urlPath);
        if ($urlPath === false) {
            return false;
        }
        $imageUrlPath = $this->get_image_url_path();
        if (strpos($urlPath, $imageUrlPath) !== 0) {
            return false;
        }

        $relative = substr($urlPath, strlen($imageUrlPath));
        if ($relative === "" || preg_match("#(^|/)\.\.?(/|$)#", $relative)) {
            return false;
        }

        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) {
            return false;
        }
        $source = realpath($imageDirectory.DIRECTORY_SEPARATOR.str_replace(
            "/", DIRECTORY_SEPARATOR, $relative
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

    // Resolve dot segments in a URL path without allowing traversal above its root.
    public function normalise_url_path($path)
    {
        $segments = explode("/", str_replace("\\", "/", $path));
        $normalised = array();
        foreach ($segments as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }
            if ($segment === "..") {
                if (empty($normalised)) {
                    return false;
                }
                array_pop($normalised);
            } else {
                $normalised[] = $segment;
            }
        }
        return "/".implode("/", $normalised);
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

    // Encode URL path segments while preserving directory separators.
    public function encode_url_path($path)
    {
        $segments = explode("/", $path);
        foreach ($segments as &$segment) {
            $segment = rawurlencode(rawurldecode($segment));
        }
        unset($segment);
        return implode("/", $segments);
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
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = @finfo_file($finfo, $sourceInfo["file"]);
                if (is_string($detectedMime)) {
                    $mime = $detectedMime;
                }
                finfo_close($finfo);
            }
        }
        if ($mime === "" && function_exists("mime_content_type")) {
            $detectedMime = @mime_content_type($sourceInfo["file"]);
            if (is_string($detectedMime)) {
                $mime = $detectedMime;
            }
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
            $targetDirectory .= DIRECTORY_SEPARATOR.str_replace("/", DIRECTORY_SEPARATOR, $relativeDirectory);
        }
        $targetName = basename($sourceInfo["relative"]).".webp";
        if (is_dir($targetDirectory)) {
            $realTargetDirectory = realpath($targetDirectory);
            $imageDirectory = $this->get_image_directory();
            $imageRootPrefix = $imageDirectory !== false ? rtrim($imageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : "";
            if ($realTargetDirectory === false || $imageRootPrefix === "" ||
                strpos($realTargetDirectory.DIRECTORY_SEPARATOR, $imageRootPrefix) !== 0) {
                return $srcIn;
            }
            $targetDirectory = $realTargetDirectory;
        }
        $targetFile = $targetDirectory.DIRECTORY_SEPARATOR.$targetName;
        $metadataFile = $targetFile.".meta";

        clearstatcache(true, $sourceInfo["file"]);
        $sourceMtime = @filemtime($sourceInfo["file"]);
        $sourceCtime = @filectime($sourceInfo["file"]);
        $sourceSize = @filesize($sourceInfo["file"]);
        if ($sourceMtime === false || $sourceCtime === false || $sourceSize === false) {
            return $srcIn;
        }
        $sourceSignature = $sourceMtime."|".$sourceCtime."|".$sourceSize;

        clearstatcache(true, $targetFile);
        $cacheValid = false;
        if (is_file($targetFile) && @filesize($targetFile) > 0 && is_file($metadataFile)) {
            $cachedSignature = @file_get_contents($metadataFile);
            $cacheValid = is_string($cachedSignature) && trim($cachedSignature) === $sourceSignature;
        }

        if (!$cacheValid) {
            if (!is_dir($targetDirectory) &&
                !@mkdir($targetDirectory, 0775, true) &&
                !is_dir($targetDirectory)) {
                return $srcIn;
            }
            $realTargetDirectory = realpath($targetDirectory);
            $imageDirectory = $this->get_image_directory();
            $imageRootPrefix = $imageDirectory !== false ? rtrim($imageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : "";
            if ($realTargetDirectory === false || $imageRootPrefix === "" ||
                strpos($realTargetDirectory.DIRECTORY_SEPARATOR, $imageRootPrefix) !== 0) {
                return $srcIn;
            }
            $targetDirectory = $realTargetDirectory;
            $targetFile = $targetDirectory.DIRECTORY_SEPARATOR.$targetName;
            $metadataFile = $targetFile.".meta";

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
                if (function_exists("imagealphablending")) {
                    imagealphablending($image, false);
                }
                if (function_exists("imagesavealpha")) {
                    imagesavealpha($image, true);
                }
            }

            $temporaryFile = @tempnam($targetDirectory, "webp_");
            if ($temporaryFile === false) {
                imagedestroy($image);
                return $srcIn;
            }

            $converted = @imagewebp($image, $temporaryFile);
            imagedestroy($image);
            clearstatcache(true, $temporaryFile);
            if (!$converted || !is_file($temporaryFile) || @filesize($temporaryFile) <= 0) {
                @unlink($temporaryFile);
                return $srcIn;
            }

            // Replace the cached file only after a complete successful conversion.
            if (!@rename($temporaryFile, $targetFile)) {
                // Windows cannot rename over an existing destination.
                if (is_file($targetFile)) {
                    @unlink($targetFile);
                }
                if (!@rename($temporaryFile, $targetFile)) {
                    @unlink($temporaryFile);
                    return $srcIn;
                }
            }
            @file_put_contents($metadataFile, $sourceSignature, LOCK_EX);
            @touch($targetFile, $sourceMtime);
        }

        clearstatcache(true, $targetFile);
        $targetSize = is_file($targetFile) ? @filesize($targetFile) : false;
        if ($targetSize === false || $targetSize <= 0 || $targetSize >= $sourceSize) {
            return $srcIn;
        }

        $cacheName = trim((string)$this->yellow->system->get("webpDirectory"), "/\\");
        $webpRelative = $cacheName."/".
            ($relativeDirectory !== "." && $relativeDirectory !== "" ? $relativeDirectory."/" : "").
            $targetName;
        $webpUrl = $this->get_image_url_path().$this->encode_url_path($webpRelative);

        if (isset($parts["query"])) {
            $webpUrl .= "?".$parts["query"];
        }
        if (isset($parts["fragment"])) {
            $webpUrl .= "#".$parts["fragment"];
        }
        return $webpUrl;
    }
}
