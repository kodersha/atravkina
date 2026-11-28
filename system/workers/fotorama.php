<?php
// Fotorama extension for Datenstrom Yellow 1.x
// https://github.com/kodersha/yellow-fotorama

class YellowFotorama {
    const VERSION = "2.1.0";

    public $yellow;
    private $assetsRequired = false;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
    }

    // Yellow 1.x parses shortcuts through onParseContentElement().
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        if ($name!="fotorama" || ($type!="inline" && $type!="block")) {
            return null;
        }
        $this->assetsRequired = true;
        return $this->getGalleryHtml($page, $text);
    }

    private function getGalleryHtml($page, $text) {
        $names = preg_split('/\\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($names)) {
            return "";
        }

        // Resolve image names against Yellow's configured public image directory.
        $imageDirectory = trim(str_replace("\\", "/", $this->yellow->system->get("coreImageLocation")), "/");
        $imagePrefix = "/".$imageDirectory."/";
        $filesByName = array();

        foreach ($this->yellow->media->index() as $mediaFile) {
            $location = "/".ltrim(str_replace("\\", "/", $mediaFile->location), "/");
            if (strpos($location, $imagePrefix)!==0) continue;
            $filesByName[basename($location)] = $mediaFile;
        }

        // A missing image must not turn an otherwise valid post into an HTTP 404.
        // Keep the requested order and render every image that is available.
        $selected = array();
        foreach ($names as $name) {
            if ($name!==basename(str_replace("\\", "/", $name)) ||
                $name=="." || $name==".." ||
                strpos($name, "/")!==false || strpos($name, "\\")!==false) {
                continue;
            }
            if (!isset($filesByName[$name])) continue;

            $mediaFile = $filesByName[$name];
            if (!is_file($mediaFile->fileName)) continue;
            $selected[] = $mediaFile;
        }

        // If none of the requested files exists, omit the gallery but preserve the page.
        if (empty($selected)) {
            return "";
        }

        $serverBase = rtrim($this->yellow->system->get("coreServerBase"), "/");
        $html = '<div class="fotorama-gallery"><div class="fotorama" data-allowfullscreen="true" data-fit="contain">';
        $lastModified = 0;

        foreach ($selected as $mediaFile) {
            $diskPath = $mediaFile->fileName;
            $publicLocation = "/".ltrim(str_replace("\\", "/", $mediaFile->location), "/");
            $src = $serverBase.$publicLocation;
            $imageInfo = @getimagesize($diskPath);
            $width = is_array($imageInfo) ? $imageInfo[0] : 0;
            $height = is_array($imageInfo) ? $imageInfo[1] : 0;

            $alt = $mediaFile->get("title");
            if (trim($alt)=="") $alt = basename($diskPath);

            $html .= '<img src="'.htmlspecialchars($src, ENT_QUOTES, "UTF-8").'"';
            if ($width>0 && $height>0) {
                $html .= ' width="'.$width.'" height="'.$height.'"';
            }
            $html .= ' alt="'.htmlspecialchars($alt, ENT_QUOTES, "UTF-8").'" />';
            $modified = $this->yellow->toolbox->getFileModified($diskPath);
            if ($modified>$lastModified) $lastModified = $modified;
        }

        $html .= '</div></div>';
        if ($lastModified>0) $page->setLastModified($lastModified);
        return $html;
    }

    // Add local Fotorama assets to pages that contain the shortcut,
    // including blog listings where shortcuts are inside individual posts.
    public function onParsePageExtra($page, $name) {
        if ($name!="header") return null;

        // Check the current page first.
        if (!$this->assetsRequired && method_exists($page, "getContentRaw")) {
            $this->assetsRequired = preg_match('/\\[fotorama(?:\\s+[^\\]]*)?\\]/i', $page->getContentRaw())==1;
        }

        // Blog posts are rendered after the listing page header, so their
        // shortcuts must be detected before the header assets are generated.
        if (!$this->assetsRequired && $page->get("layout")=="blog-start") {
            foreach ($this->yellow->page->getPages("blog") as $blogPage) {
                if (method_exists($blogPage, "getContentRaw") &&
                    preg_match('/\\[fotorama(?:\\s+[^\\]]*)?\\]/i', $blogPage->getContentRaw())) {
                    $this->assetsRequired = true;
                    break;
                }
            }
        }

        if (!$this->assetsRequired) return null;

        $base = rtrim($this->yellow->system->get("coreServerBase"), "/");
        $assetDirectory = trim($this->yellow->system->get("coreAssetLocation"), "/");
        $assetUrl = $base."/".$assetDirectory."/";

        $html = '<link rel="stylesheet" href="'.htmlspecialchars($assetUrl."fotorama.css", ENT_QUOTES, "UTF-8").'" />'."\\n";
        $html .= '<script defer src="'.htmlspecialchars($assetUrl."jquery.min.js", ENT_QUOTES, "UTF-8").'"></script>'."\\n";
        $html .= '<script defer src="'.htmlspecialchars($assetUrl."fotorama.js", ENT_QUOTES, "UTF-8").'"></script>'."\\n";
        return $html;
    }
}