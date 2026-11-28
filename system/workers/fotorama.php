<?php
// Fotorama extension for Datenstrom Yellow 1.x
// https://github.com/kodersha/yellow-fotorama

class YellowFotorama {
    const VERSION = "2.2.0";

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

        // A gallery must never prevent Yellow from rendering the containing page.
        try {
            return $this->getGalleryHtml($page, $text);
        } catch (Throwable $e) {
            if ($this->yellow->system->get("coreDebugMode")>=1) {
                $this->yellow->toolbox->log("error", "Fotorama: ".$e->getMessage());
            }
            return "";
        }
    }

    private function getGalleryHtml($page, $text) {
        $names = preg_split('/\\s+/', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($names)) return "";

        $imageDirectory = trim(str_replace("\\", "/", $this->yellow->system->get("coreImageLocation")), "/");
        if ($imageDirectory=="") return "";
        $imagePrefix = "/".$imageDirectory."/";
        $selected = array();

        // Resolve only the requested files instead of scanning the entire media tree.
        foreach ($names as $name) {
            $name = str_replace("\\", "/", $name);
            if ($name!==basename($name) || $name=="." || $name==".." || $name=="") continue;

            $mediaFile = $this->yellow->media->find($imagePrefix.$name);
            if (!$mediaFile || empty($mediaFile->fileName) || !is_file($mediaFile->fileName) || !is_readable($mediaFile->fileName)) {
                continue;
            }
            $selected[] = $mediaFile;
        }

        // Missing or unreadable images should only be omitted, not turn the post into a 404.
        if (empty($selected)) return "";

        $serverBase = rtrim($this->yellow->system->get("coreServerBase"), "/");
        $html = '<div class="fotorama-gallery"><div class="fotorama" data-allowfullscreen="true" data-fit="contain">';
        $lastModified = 0;

        foreach ($selected as $mediaFile) {
            $diskPath = $mediaFile->fileName;
            $publicLocation = "/".ltrim(str_replace("\\", "/", $mediaFile->location), "/");
            $src = $serverBase.$publicLocation;
            $imageInfo = @getimagesize($diskPath);
            $width = is_array($imageInfo) ? (int)$imageInfo[0] : 0;
            $height = is_array($imageInfo) ? (int)$imageInfo[1] : 0;

            $alt = $mediaFile->get("title");
            if (!is_string($alt) || trim($alt)=="") $alt = basename($diskPath);

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

        if (!$this->assetsRequired && method_exists($page, "getContentRaw")) {
            $this->assetsRequired = preg_match('/\\[fotorama(?:\\s+[^\\]]*)?\\]/i', $page->getContentRaw())==1;
        }

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
