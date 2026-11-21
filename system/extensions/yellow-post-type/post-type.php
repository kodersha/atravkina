<?php
// Detect semantic post types and normalize image-plus-text posts.

class YellowPostType {
    const VERSION = "1.0.0";

    public static function process($contentHtml) {
        $contentHtml = (string)$contentHtml;

        // Remove empty paragraphs and turn standalone images into figures.
        $contentHtml = preg_replace('~<p(?:\s[^>]*)?>\s*</p>~i', "", $contentHtml);
        $contentHtml = preg_replace(
            '~<p\b[^>]*>\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)\s*</p>~i',
            '<figure>$1</figure>',
            $contentHtml
        );

        // A single image followed only by text becomes one figure with a caption.
        if (preg_match('~^\s*(<figure\b[^>]*>.*?</figure>)\s*((?:<p\b[^>]*>.*?</p>\s*)+)$~is', $contentHtml, $matches)) {
            $paragraphs = $matches[2];
            $caption = preg_replace('~</?p\b[^>]*>~i', '', $paragraphs);
            $caption = trim($caption);
            if ($caption !== '') {
                $contentHtml = preg_replace(
                    '~</figure>\s*(?:<p\b[^>]*>.*?</p>\s*)+$~is',
                    '<figcaption>'.$caption.'</figcaption></figure>',
                    $contentHtml
                );
            }
        }

        $imageCount = preg_match_all('~<img\b~i', $contentHtml, $unused);
        $videoCount = preg_match_all('~<(?:video|iframe)\b~i', $contentHtml, $unused);
        $hasPoetry = preg_match('~<pre\b[^>]*>.*?(?:language-)?poetry.*?</pre>~is', $contentHtml) === 1;

        $withoutMedia = $contentHtml;
        if ($hasPoetry) {
            $withoutMedia = preg_replace('~<pre\b[^>]*>.*?</pre>~is', '', $withoutMedia);
        }
        $withoutMedia = preg_replace('~<figure\b[^>]*>.*?</figure>~is', '', $withoutMedia);
        $withoutMedia = preg_replace('~<(?:img|video|iframe)\b[^>]*>~is', '', $withoutMedia);
        $withoutMedia = preg_replace('~</(?:video|iframe)>~is', '', $withoutMedia);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($withoutMedia), ENT_QUOTES | ENT_HTML5, "UTF-8")));

        $hasCaption = preg_match("~<figcaption\\b~i", $contentHtml) === 1;
        $type = "";
        if ($imageCount > 1) {
            $type = "post-gallery";
        } elseif ($imageCount === 1 && $videoCount === 0) {
            $type = ($hasCaption || $text !== "") ? "post-image-text" : "post-image";
        } elseif ($videoCount === 1 && $imageCount === 0 && $text === "") {
            $type = "post-video";
        } elseif ($hasPoetry && $imageCount === 0 && $videoCount === 0 && $text === "") {
            $type = "post-poetry";
        } elseif ($imageCount === 0 && $videoCount === 0 && !$hasPoetry && $text !== "") {
            $type = "post-text";
        }

        return array("content" => $contentHtml, "class" => $type);
    }
}
