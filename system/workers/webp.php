<?php

// WebP extension for Yellow, https://github.com/kodersha/atravkina

class YellowWebp
{
    const VERSION = "0.9.3";
    public $yellow;
    public $renamedSources = array();

    public function onLoad($yellow)
    {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("webpExcludeContentType", "xml");
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

    // Convert image references in blog posts immediately after Yellow saves the content file.
    public function onSaveContentFile($page)
    {
        if (!is_object($page) || !method_exists($page, "get") || $page->get("layout") !== "blog" ||
            !isset($page->rawData) || !is_string($page->rawData)) {
            return;
        }

        $rawData = $page->rawData;

        // Yellow's native [image filename] content element stores paths relative to media/images.
        // Migrate references from the old converter (webp/name.jpg.webp) via the original image.
        $rawData = preg_replace_callback('/(\[image\s+)([^\]\s]+)(?=[\s\]])/i',
            function ($matches) {
                $reference = $matches[2];
                if (preg_match('#^webp/(.+\.(?:jpe?g|png))\.webp$#i', $reference, $legacy)) {
                    $reference = $legacy[1];
                }
                return $matches[1].$this->convertPostImageReference($reference, true);
            }, $rawData);

        // Markdown image syntax and HTML image tags.
        $rawData = preg_replace_callback('/(!\[[^\]]*\]\()([^\s)]+)(\))/u',
            function ($matches) {
                return $matches[1].$this->convertPostImageReference($matches[2]).$matches[3];
            }, $rawData);
        $rawData = preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/is',
            function ($matches) {
                return $matches[1].$matches[2].$this->convertPostImageReference($matches[3]).$matches[2];
            }, $rawData);

        // Yellow preview metadata is also resolved relative to the image directory.
        $rawData = preg_replace_callback('/^(Preview:\s*)([^\r\n]+)$/mi',
            function ($matches) {
                return $matches[1].$this->convertPostImageReference(trim($matches[2]), true);
            }, $rawData);

        if ($rawData !== $page->rawData &&
            $this->yellow->toolbox->writeFile($page->fileName, $rawData)) {
            $page->rawData = $rawData;
            $this->yellow->toolbox->log("info", "Convert blog post images to WebP: ".$page->location);
        }
    }

    // Resolve a post image reference against Yellow's configured image directory.
    public function convertPostImageReference($source, $relativeToImageDirectory = false)
    {
        $source = trim(html_entity_decode($source, ENT_QUOTES, "UTF-8"));
        if ($source === "" || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i', $source) ||
            stripos($source, "data:") === 0 || preg_match('/\.webp(?:[?#]|$)/i', $source)) {
            return $source;
        }

        $parts = parse_url($source);
        if ($parts === false || empty($parts["path"])) {
            return $source;
        }
        $path = $parts["path"];
        if (substr($path, 0, 1) !== "/") {
            $path = $this->get_image_url_path().ltrim($path, "/");
        }
        $url = $path;
        if (isset($parts["query"])) $url .= "?".$parts["query"];
        if (isset($parts["fragment"])) $url .= "#".$parts["fragment"];

        // Keep the timestamp-named original if WebP conversion is unavailable or fails.
        $url = $this->rename_source_to_timestamp($url);
        $converted = $this->convert($url);
        if ($relativeToImageDirectory && strpos($converted, $this->get_image_url_path()) === 0) {
            $converted = substr($converted, strlen($this->get_image_url_path()));
        }
        return $converted;
    }


    // Rename a local source image to its creation timestamp. Add a suffix on collisions.
    public function rename_source_to_timestamp($url)
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts["path"])) {
            return $url;
        }

        $pathKey = $parts["path"];
        if (isset($this->renamedSources[$pathKey])) {
            $result = $this->renamedSources[$pathKey];
            if (isset($parts["query"])) $result .= "?".$parts["query"];
            if (isset($parts["fragment"])) $result .= "#".$parts["fragment"];
            return $result;
        }

        $sourceInfo = $this->resolve_source($url);
        if ($sourceInfo === false) {
            return $url;
        }

        $extension = strtolower(pathinfo($sourceInfo["file"], PATHINFO_EXTENSION));
        if (!preg_match('/^(?:jpe?g|png)$/i', $extension)) {
            return $url;
        }

        $basename = pathinfo($sourceInfo["file"], PATHINFO_FILENAME);
        if (preg_match('/^\\d+(?:-\\d+)?$/', $basename)) {
            return $url;
        }

        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false || !is_writable(dirname($sourceInfo["file"]))) {
            return $url;
        }

        $timestamp = $this->get_source_timestamp($sourceInfo["file"]);
        $directory = dirname($sourceInfo["file"]);
        $targetBase = (string)$timestamp;
        $targetFile = $directory.DIRECTORY_SEPARATOR.$targetBase.".".$extension;
        $suffix = 1;

        while (file_exists($targetFile) &&
            realpath($targetFile) !== realpath($sourceInfo["file"])) {
            $targetBase = (string)$timestamp."-".$suffix++;
            $targetFile = $directory.DIRECTORY_SEPARATOR.$targetBase.".".$extension;
        }

        if (realpath($targetFile) !== realpath($sourceInfo["file"]) &&
            !@rename($sourceInfo["file"], $targetFile)) {
            return $url;
        }

        $rootPrefix = rtrim($imageDirectory, "/\\").DIRECTORY_SEPARATOR;
        $relative = str_replace("\\", "/", substr($targetFile, strlen($rootPrefix)));
        $renamedPath = $this->get_image_url_path().$this->encode_url_path($relative);
        $this->renamedSources[$pathKey] = $renamedPath;

        if (isset($parts["query"])) $renamedPath .= "?".$parts["query"];
        if (isset($parts["fragment"])) $renamedPath .= "#".$parts["fragment"];
        return $renamedPath;
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
        // Image decoders are checked per MIME type in convert().
        return function_exists("imagewebp");
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
        if (!is_string($mediaDirectory) || trim($mediaDirectory) === "") {
            return false;
        }

        $installDirectory = (string)$this->yellow->system->get("coreServerInstallDirectory");
        if ($installDirectory === "") {
            return false;
        }

        if ($this->is_absolute_path($mediaDirectory)) {
            $candidate = $mediaDirectory;
        } else {
            $candidate = rtrim($installDirectory, "/\\").DIRECTORY_SEPARATOR.
                ltrim($mediaDirectory, "/\\");
        }

        $realPath = realpath($candidate);
        return $realPath !== false && is_dir($realPath) ? $realPath : false;
    }

    // Return the public URL path corresponding to Yellow's configured image directory.
    public function get_image_url_path()
    {
        $basePath = "";
        $serverBase = (string)$this->yellow->system->get("coreServerBase");
        if ($serverBase !== "") {
            $parsedPath = parse_url($serverBase, PHP_URL_PATH);
            $basePath = is_string($parsedPath) ? $parsedPath : $serverBase;
        }
        if ($basePath === "" && isset($this->yellow->page->base)) {
            $parsedPath = parse_url((string)$this->yellow->page->base, PHP_URL_PATH);
            $basePath = is_string($parsedPath) ? $parsedPath : "";
        }

        $imageLocation = (string)$this->yellow->system->get("coreImageLocation");
        $path = rtrim($basePath, "/")."/".ltrim($imageLocation, "/");
        $normalised = $this->normalise_url_path($path);
        if ($normalised === false) {
            return "/media/images/";
        }
        return rtrim($normalised, "/")."/";
    }

    // WebP files are stored directly in Yellow's configured image directory.
    public function get_cache_directory()
    {
        // Yellow's image WebP files are stored directly in coreImageLocation.
        return $this->get_image_directory();
    }

    // Resolve a local image URL to a file contained in Yellow's configured image directory.
    public function resolve_source($srcIn)
    {
        $parts = parse_url($srcIn);
        if ($parts === false || !isset($parts["path"]) || $parts["path"] === "") {
            return false;
        }

        if (isset($parts["scheme"])) {
            $scheme = strtolower($parts["scheme"]);
            if (!in_array($scheme, array("http", "https"), true) || empty($parts["host"])) {
                return false;
            }
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

        $basePath = "";
        if (isset($this->yellow->page->base)) {
            $parsedBasePath = parse_url((string)$this->yellow->page->base, PHP_URL_PATH);
            $basePath = is_string($parsedBasePath) ? $parsedBasePath : "";
        }

        if (substr($urlPath, 0, 1) !== "/") {
            $pageLocation = isset($this->yellow->page->location) ?
                (string)$this->yellow->page->location : "/";
            $urlPath = rtrim($basePath, "/")."/".trim($pageLocation, "/")."/".$urlPath;
        } elseif ($basePath !== "" && $basePath !== "/" &&
            strpos($urlPath, rtrim((string)$this->yellow->system->get("coreImageLocation"), "/")) === 0 &&
            strpos($urlPath, $this->get_image_url_path()) !== 0) {
            // Accept root-relative media URLs on installations mounted in a subdirectory.
            $urlPath = rtrim($basePath, "/").$urlPath;
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
        if ($relative === "" || preg_match("#(^|/)\\.\\.?(/|$)#", $relative)) {
            return false;
        }

        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) {
            return false;
        }

        $source = realpath($imageDirectory.DIRECTORY_SEPARATOR.str_replace(
            "/", DIRECTORY_SEPARATOR, $relative
        ));
        if ($source === false || !$this->is_path_inside($source, $imageDirectory) ||
            !is_file($source) || !is_readable($source)) {
            return false;
        }

        return array(
            "file" => $source,
            "relative" => str_replace("\\", "/", substr(
                $source, strlen(rtrim($imageDirectory, "/\\").DIRECTORY_SEPARATOR)
            ))
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

    // Return the best portable approximation of the source file creation time.
    public function get_source_timestamp($file)
    {
        clearstatcache(true, $file);
        $timestamp = false;
        if (defined("PHP_OS_FAMILY") && PHP_OS_FAMILY === "Windows") {
            $timestamp = @filectime($file);
        }
        if ($timestamp === false || $timestamp <= 0) {
            $timestamp = @filemtime($file);
        }
        return $timestamp !== false && $timestamp > 0 ? (int)$timestamp : time();
    }


    // Detect absolute filesystem paths without relying on the current working directory.
    public function is_absolute_path($path)
    {
        return substr($path, 0, 1) === "/" ||
            preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1 ||
            substr($path, 0, 2) === "\\\\";
    }

    // Check whether a resolved filesystem path is inside a resolved root directory.
    public function is_path_inside($path, $root)
    {
        $path = str_replace("\\", "/", rtrim($path, "/\\"));
        $root = str_replace("\\", "/", rtrim($root, "/\\"));
        if ($root === "") {
            $root = "/";
        }
        $prefix = rtrim($root, "/")."/";
        if ($root === "/") {
            $prefix = "/";
        }
        $caseInsensitive = (defined("PHP_OS_FAMILY") && PHP_OS_FAMILY === "Windows") ||
            stripos(PHP_OS, "WIN") === 0;
        return $caseInsensitive ? stripos($path, $prefix) === 0 : strpos($path, $prefix) === 0;
    }

    // Create a private fingerprint directory under Yellow's configured cache directory.
    public function get_cache_manifest_directory()
    {
        $installDirectory = (string)$this->yellow->system->get("coreServerInstallDirectory");
        $cacheLocation = str_replace("\\", "/", trim(
            (string)$this->yellow->system->get("coreCacheDirectory"), "/\\"
        ));
        if ($installDirectory === "" || $cacheLocation === "" ||
            $this->is_absolute_path($cacheLocation) ||
            preg_match("#(^|/)\\.\\.?(/|$)#", $cacheLocation) ||
            strpos($cacheLocation, ":") !== false || strpos($cacheLocation, "\0") !== false) {
            return false;
        }

        $installReal = realpath($installDirectory);
        if ($installReal === false || !is_dir($installReal)) {
            return false;
        }

        $cachePath = $installReal.DIRECTORY_SEPARATOR.str_replace(
            "/", DIRECTORY_SEPARATOR, $cacheLocation
        );
        if (!is_dir($cachePath) && !@mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
            return false;
        }
        $cacheReal = realpath($cachePath);
        if ($cacheReal === false || !$this->is_path_inside($cacheReal, $installReal)) {
            return false;
        }

        $manifestPath = $cacheReal.DIRECTORY_SEPARATOR."webp";
        if (!is_dir($manifestPath) && !@mkdir($manifestPath, 0775, true) && !is_dir($manifestPath)) {
            return false;
        }
        $manifestReal = realpath($manifestPath);
        if ($manifestReal === false || !$this->is_path_inside($manifestReal, $cacheReal) ||
            !is_writable($manifestReal)) {
            return false;
        }
        return $manifestReal;
    }

    public function get_cache_manifest_file($directory, $relative)
    {
        return $directory.DIRECTORY_SEPARATOR.sha1(str_replace("\\", "/", $relative)).".cache";
    }

    // Prefer a content hash; use stat signature only if hashing is unavailable.
    public function get_source_fingerprint($file)
    {
        if (function_exists("hash_file")) {
            $hash = @hash_file("sha256", $file);
            if (is_string($hash) && $hash !== "") {
                return "sha256:".$hash;
            }
        }
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        $ctime = @filectime($file);
        $size = @filesize($file);
        if ($mtime === false || $ctime === false || $size === false) {
            return false;
        }
        return "stat:".$mtime."|".$ctime."|".$size;
    }

    public function read_cache_manifest($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $data = @file_get_contents($file);
        if (!is_string($data) || $data === "") {
            return false;
        }
        $lines = explode("\n", trim($data));
        if (count($lines) < 2 || !preg_match('/^[a-zA-Z0-9_-]+$/', $lines[1])) {
            return false;
        }
        return array("fingerprint" => $lines[0], "targetBase" => $lines[1]);
    }

    public function write_cache_manifest($file, $fingerprint, $targetBase)
    {
        $directory = dirname($file);
        $temporaryFile = @tempnam($directory, "webp_meta_");
        if ($temporaryFile === false) {
            return false;
        }
        $data = $fingerprint."\n".$targetBase."\n";
        if (@file_put_contents($temporaryFile, $data, LOCK_EX) === false ||
            !$this->replace_file($temporaryFile, $file)) {
            @unlink($temporaryFile);
            return false;
        }
        return true;
    }

    // Replace an existing file safely on POSIX and Windows filesystems.
    public function replace_file($source, $destination)
    {
        if (@rename($source, $destination)) {
            return true;
        }
        if (!is_file($destination)) {
            return false;
        }

        $backup = @tempnam(dirname($destination), "webp_old_");
        if ($backup === false) {
            return false;
        }
        @unlink($backup);

        if (!@rename($destination, $backup)) {
            @unlink($backup);
            return false;
        }
        if (@rename($source, $destination)) {
            @unlink($backup);
            return true;
        }

        @rename($backup, $destination);
        @unlink($source);
        return false;
    }

    public function is_valid_webp($file)
    {
        clearstatcache(true, $file);
        if (!is_file($file) || @filesize($file) <= 0) {
            return false;
        }
        if (function_exists("getimagesize")) {
            $imageInfo = @getimagesize($file);
            if ($imageInfo === false) {
                return false;
            }
            if (isset($imageInfo["mime"])) {
                return strtolower($imageInfo["mime"]) === "image/webp";
            }
            if (defined("IMAGETYPE_WEBP") && isset($imageInfo[2])) {
                return $imageInfo[2] === IMAGETYPE_WEBP;
            }
        }
        return true;
    }

    // Convert a local JPEG/PNG to a timestamp-named WebP in media/images.
    // The original image is kept as a fallback if conversion fails.
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
                @finfo_close($finfo);
            }
        }
        if ($mime === "" && function_exists("mime_content_type")) {
            $detectedMime = @mime_content_type($sourceInfo["file"]);
            if (is_string($detectedMime)) {
                $mime = $detectedMime;
            }
        }

        if ($mime === "" || !in_array($mime, $this->get_supported_mime_types(), true) ||
            !function_exists("imagewebp")) {
            return $srcIn;
        }
        if (($mime === "image/jpeg" && !function_exists("imagecreatefromjpeg")) ||
            ($mime === "image/png" && !function_exists("imagecreatefrompng"))) {
            return $srcIn;
        }

        $imageDirectory = $this->get_cache_directory();
        if ($imageDirectory === false || !is_writable($imageDirectory)) {
            return $srcIn;
        }

        clearstatcache(true, $sourceInfo["file"]);
        $sourceSize = @filesize($sourceInfo["file"]);
        $sourceMtime = @filemtime($sourceInfo["file"]);
        if ($sourceSize === false || $sourceSize <= 0 || $sourceMtime === false) {
            return $srcIn;
        }

        $fingerprint = $this->get_source_fingerprint($sourceInfo["file"]);
        if ($fingerprint === false) {
            return $srcIn;
        }

        $sourceBase = pathinfo($sourceInfo["file"], PATHINFO_FILENAME);
        $relativeDirectory = dirname($sourceInfo["relative"]);
        $hasSubdirectory = $relativeDirectory !== "." && $relativeDirectory !== "";
        if (preg_match('/^\\d+(?:-\\d+)?$/', $sourceBase)) {
            $targetBase = $hasSubdirectory ?
                $sourceBase."-".substr(sha1($sourceInfo["relative"]), 0, 8) : $sourceBase;
        } else {
            $targetBase = (string)$this->get_source_timestamp($sourceInfo["file"])."-".
                substr(sha1($sourceInfo["relative"]), 0, 8);
        }

        $manifestDirectory = $this->get_cache_manifest_directory();
        $manifestFile = $manifestDirectory !== false ?
            $this->get_cache_manifest_file($manifestDirectory, $sourceInfo["relative"]) : false;
        $manifest = $manifestFile !== false ? $this->read_cache_manifest($manifestFile) : false;

        // Keep the first generated timestamp-based target name stable for this source path.
        if (!preg_match('/^\\d+(?:-\\d+)?$/', $sourceBase) && is_array($manifest) &&
            !empty($manifest["targetBase"]) &&
            preg_match('/^[a-zA-Z0-9_-]+$/', $manifest["targetBase"])) {
            $targetBase = $manifest["targetBase"];
        }

        $targetFile = $imageDirectory.DIRECTORY_SEPARATOR.$targetBase.".webp";
        clearstatcache(true, $targetFile);
        $targetSize = @filesize($targetFile);
        $cacheValid = is_file($targetFile) && $targetSize !== false && $targetSize > 0 &&
            $this->is_valid_webp($targetFile) &&
            is_array($manifest) && isset($manifest["fingerprint"], $manifest["targetBase"]) &&
            $manifest["fingerprint"] === $fingerprint && $manifest["targetBase"] === $targetBase;

        if (!$cacheValid) {
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
                if (function_exists("imagepalettetotruecolor")) {
                    @imagepalettetotruecolor($image);
                }
                if (function_exists("imagealphablending")) {
                    @imagealphablending($image, false);
                }
                if (function_exists("imagesavealpha")) {
                    @imagesavealpha($image, true);
                }
            }

            $temporaryFile = @tempnam($imageDirectory, "webp_");
            if ($temporaryFile === false) {
                @imagedestroy($image);
                return $srcIn;
            }

            $converted = @imagewebp($image, $temporaryFile);
            @imagedestroy($image);
            clearstatcache(true, $temporaryFile);

            if (!$converted || !$this->is_valid_webp($temporaryFile)) {
                @unlink($temporaryFile);
                return $srcIn;
            }

            $fingerprintAfterEncode = $this->get_source_fingerprint($sourceInfo["file"]);
            if ($fingerprintAfterEncode === false || $fingerprintAfterEncode !== $fingerprint) {
                @unlink($temporaryFile);
                return $srcIn;
            }

            if (!$this->replace_file($temporaryFile, $targetFile)) {
                @unlink($temporaryFile);
                return $srcIn;
            }

            @touch($targetFile, $sourceMtime);

            $fingerprintAfterWrite = $this->get_source_fingerprint($sourceInfo["file"]);
            if ($fingerprintAfterWrite === false || $fingerprintAfterWrite !== $fingerprint) {
                if ($manifestFile !== false && is_file($manifestFile)) {
                    @unlink($manifestFile);
                }
                return $srcIn;
            }

            if ($manifestFile !== false) {
                $this->write_cache_manifest($manifestFile, $fingerprint, $targetBase);
            }
        }

        clearstatcache(true, $targetFile);
        $targetSize = is_file($targetFile) ? @filesize($targetFile) : false;
        if ($targetSize === false || $targetSize <= 0 || $targetSize >= $sourceSize) {
            return $srcIn;
        }

        $webpUrl = $this->get_image_url_path().$this->encode_url_path($targetBase).".webp";
        if (isset($parts["query"])) {
            $webpUrl .= "?".$parts["query"];
        }
        if (isset($parts["fragment"])) {
            $webpUrl .= "#".$parts["fragment"];
        }
        return $webpUrl;
    }
}
