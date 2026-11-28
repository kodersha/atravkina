<?php
// Fotorama extension for Datenstrom Yellow 1.x
// https://github.com/kodersha/yellow-fotorama

class YellowFotorama {
    const VERSION = "2.3.0";

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

        // Resolve image files directly from Yellow's configured filesystem paths.
        // YellowMedia::find() performs a directory scan and can fail on custom media paths.
        $imageLocation = str_replace("\\", "/", $this->yellow->system->get("coreImageLocation"));
        $mediaLocation = str_replace("\\", "/", $this->yellow->system->get("coreMediaLocation"));
        $mediaDirectory = $this->yellow->system->get("coreMediaDirectory");
        $imageRelative = ltrim($imageLocation, "/");

        if ($mediaLocation!="" && strpos($imageLocation, $mediaLocation)===0) {
            $imageRelative = substr($imageLocation, strlen($mediaLocation));
        }

        $imageDirectory = rtrim($mediaDirectory, "/\\\\").DIRECTORY_SEPARATOR.
            str_replace("/", DIRECTORY_SEPARATOR, trim($imageRelative, "/"));
        $selected = array();

        foreach ($names as $name) {
            $name = str_replace("\\", "/", $name);
            if ($name!==basename($name) || $name=="." || $name==".." || $name=="") continue;

            $diskPath = $imageDirectory.DIRECTORY_SEPARATOR.$name;
            if (is_file($diskPath) && is_readable($diskPath)) {
                $selected[] = array("path"=>$diskPath, "name"=>$name);
            }
        }

        // A missing image must never make the containing post unavailable.
        if (empty($selected)) return "";

        $serverBase = rtrim($this->yellow->system->get("coreServerBase"), "/");
        $imageUrl = rtrim($imageLocation, "/")."/";
        $html = '<div class="fotorama-gallery"><div class="fotorama" data-allowfullscreen="true" data-fit="contain">';
        $lastModified = 0;

        foreach ($selected as $image) {
            $diskPath = $image["path"];
            $src = $serverBase.$imageUrl.rawurlencode($image["name"]);
            $imageInfo = @getimagesize($diskPath);
            $width = is_array($imageInfo) ? (int)$imageInfo[0] : 0;
            $height = is_array($imageInfo) ? (int)$imageInfo[1] : 0;

            $html .= '<img src="'.htmlspecialchars($src, ENT_QUOTES, "UTF-8").'"';
            if ($width>0 && $height>0) {
                $html .= ' width="'.$width.'" height="'.$height.'"';
            }
            $html .= ' alt="'.htmlspecialchars($image["name"], ENT_QUOTES, "UTF-8").'" />';

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
