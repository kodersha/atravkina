<?php

class YellowWebp
{
    const VERSION = "0.8.22";
    public $yellow;

    public function onLoad($yellow)
    {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("webpExcludeContentType", "xml");
        $this->yellow->system->setDefault("webpDirectory", "webp");
        $this->yellow->system->setDefault("webpSupportType", "jpeg,png");
    }

    public function onParsePageOutput($page, $text)
    {
        if (!is_string($text) || $text === '') {
            return $text;
        }

        if (
            !isset($_SERVER['HTTP_ACCEPT']) ||
            strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') === false ||
            !$this->basic_checks()
        ) {
            return $text;
        }

        if (preg_match(
            "/{$this->yellow->system->getHtml('webpExcludeContentType')}/i",
            $page->getRequest("page")
        )) {
            return $text;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');

        $html = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');

        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        if ($loaded === false) {
            return $text;
        }

        foreach ($dom->getElementsByTagName('img') as $node) {
            $this->serve_node($node, 'src');
            $this->serve_node($node, 'data-src');
        }

        foreach ($dom->getElementsByTagName('a') as $node) {
            $this->serve_node($node, 'href');
        }

        foreach ($dom->getElementsByTagName('video') as $node) {
            $this->serve_node($node, 'poster');
        }

        foreach ($dom->getElementsByTagName('source') as $node) {
            $this->serve_node($node, 'srcset');
        }

        $result = $dom->saveHTML();

        return $result !== false ? $result : $text;
    }

    public function basic_checks()
    {
        if (!function_exists('mime_content_type')) {
            return false;
        }
        if (!function_exists('imagewebp')) {
            return false;
        }
        return true;
    }

    public function serve_node($node, $attribute)
    {
        if (
            in_array(
                $node->getAttribute('data-webpconverter-exclude'),
                ['1', 'on', 'yes'],
                true
            )
        ) {
            return;
        }

        if ($node->hasAttribute($attribute)) {
            $src = $node->getAttribute($attribute);
            $node->setAttribute($attribute, $this->convert($src));
        }
    }

    public function get_absolute_path($path, $URI)
    {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }

        $parts = explode('/', $URI);
        array_pop($parts);

        return implode('/', $parts) . '/' . $path;
    }

    public function get_webp_filename($fileName)
    {
        return $fileName . '.webp';
    }

    public function get_webp_path($path)
    {
        $path = str_replace('media', '', $path);
        $parts = explode('/', $path);

        $base = $this->yellow->system->get("CoreMediaLocation")
              . $this->yellow->system->get("webpDirectory");

        foreach ($parts as $part) {
            if ($part !== '') {
                $base .= '/' . $part;
            }
        }

        return $base;
    }

    public function check_cache_folder()
    {
        $path = "."
            . $this->yellow->system->get("CoreMediaLocation")
            . $this->yellow->system->get("webpDirectory");

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public function convert($srcIn)
    {
        $this->check_cache_folder();

        $src = rawurldecode($srcIn);

        if (!isset($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = substr($_SERVER['PHP_SELF'], 1);
        }

        $src = $this->get_absolute_path($src, $_SERVER['REQUEST_URI']);
        $srcServerFile = './' . ltrim($src, '/');

        if (!is_file($srcServerFile)) {
            return $srcIn;
        }

        $mime = mime_content_type($srcServerFile);

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $srcIn;
        }

        $info = pathinfo($src);
        $webpPath = $this->get_webp_path($info['dirname']);
        $webpFile = $webpPath . '/' . $info['basename'] . '.webp';
        $webpServer = './' . ltrim($webpFile, '/');

        if (!file_exists($webpServer) || filectime($webpServer) < filectime($srcServerFile)) {
            if (!is_dir(dirname($webpServer))) {
                mkdir(dirname($webpServer), 0777, true);
            }

            $image = $mime === 'image/jpeg'
                ? imagecreatefromjpeg($srcServerFile)
                : imagecreatefrompng($srcServerFile);

            imagepalettetotruecolor($image);
            imagewebp($image, $webpServer);
            imagedestroy($image);
        }

        return filesize($webpServer) < filesize($srcServerFile)
            ? $webpFile
            : $srcIn;
    }
}
