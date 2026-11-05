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

    // Convert image references in blog posts immediately after Yellow saves the content file.
    public function onSaveContentFile($page)
    {
        if (!is_object($page) || !method_exists($page, "get") || $page->get("layout") !== "blog" ||
            !isset($page->rawData) || !is_string($page->rawData) || !$this->basic_checks()) {
            return;
        }

        $rawData = $page->rawData;

        // Yellow's native [image filename] content element stores paths relative to media/images.
        $rawData = preg_replace_callback('/(\[image\s+)([^\]\s]+)(?=[\s\]])/i',
            function ($matches) {
                return $matches[1].$this->convertPostImageReference($matches[2], true);
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

    // Rename a source image to a stable Unix timestamp filename in media/images.
    // Windows exposes creation time through filectime(); on Unix PHP exposes inode-change time instead.
    public function rename_source_to_timestamp($url)
    {
        $sourceInfo = $this->resolve_source($url);
        if ($sourceInfo === false) return $url;

        $imageDirectory = $this->get_image_directory();
        if ($imageDirectory === false) return $url;

        $extension = strtolower(pathinfo($sourceInfo["file"], PATHINFO_EXTENSION));
        if (!in_array($extension, array("jpg", "jpeg", "png"), true)) return $url;

        $basename = pathinfo($sourceInfo["file"], PATHINFO_FILENAME);
        if (preg_match('/^([0-9]+)(?:-[0-9]+)?$/', $basename)) {
            $renamedUrl = $this->get_image_url_path().$this->encode_url_path($sourceInfo["relative"]);
            $parts = parse_url($url);
            if (isset($parts["query"])) $renamedUrl .= "?".$parts["query"];
            if (isset($parts["fragment"])) $renamedUrl .= "#".$parts["fragment"];
            return $renamedUrl;
        }

        clearstatcache(true, $sourceInfo["file"]);
        $timestamp = @filectime($sourceInfo["file"]);
        if ($timestamp === false || $timestamp <= 0) $timestamp = @filemtime($sourceInfo["file"]);
        if ($timestamp === false || $timestamp <= 0) return $url;

        $targetName = (string)$timestamp.".".$extension;
        $targetFile = $imageDirectory.DIRECTORY_SEPARATOR.$targetName;
        $counter = 1;
        while (file_exists($targetFile) && realpath($targetFile) !== realpath($sourceInfo["file"])) {
            $targetName = (string)$timestamp."-".$counter.".".$extension;
            $targetFile = $imageDirectory.DIRECTORY_SEPARATOR.$targetName;
            ++$counter;
        }

        if (realpath($targetFile) !== realpath($sourceInfo["file"]) &&
            !@rename($sourceInfo["file"], $targetFile)) return $url;

        $renamedUrl = $this->get_image_url_path().$this->encode_url_path($targetName);
        $parts = parse_url($url);
        if (isset($parts["query"])) $renamedUrl .= "?".$parts["query"];
        if (isset($parts["fragment"])) $renamedUrl .= "#".$parts["fragment"];
        return $renamedUrl;
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

        $url = $this->rename_source_to_timestamp($url);
        $converted = $this->convert($url);
        if ($relativeToImageDirectory && strpos($converted, $this->get_image_url_path()) === 0) {
            $converted = substr($converted, strlen($this->get_image_url_path()));
        }
        return $converted;
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

        $webpRelative = $targetName;
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
