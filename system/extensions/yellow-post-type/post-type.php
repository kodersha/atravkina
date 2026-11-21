<?php
// Detect semantic post types in rendered Yellow content.

class YellowPostType {
    const VERSION = "1.0.2";

    public static function process($contentHtml) {
        $contentHtml = (string)$contentHtml;

        // Remove empty paragraphs and wrap standalone images in figure elements.
        $contentHtml = preg_replace('~<p(?:\s[^>]*)?>\s*</p>~i', "", $contentHtml);
        $contentHtml = preg_replace(
            '~<p\b[^>]*>\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)\s*</p>~i',
            '<figure>$1</figure>',
            $contentHtml
        );

        // Image-based post classes apply only when the first content element is an image figure.
        $leadingImageFigure = preg_match('~^\s*<figure\b[^>]*>\s*(?:<a\b[^>]*>\s*)?<img\b~i', $contentHtml) === 1;

        $imageCount = preg_match_all('~<img\b~i', $contentHtml, $unused);
        $videoCount = preg_match_all('~<(?:video|iframe)\b~i', $contentHtml, $unused);

        // Fenced Markdown blocks are rendered as <pre><code>...</code></pre>.
        $hasPoetry = preg_match(
            '~<pre\b[^>]*>\s*<code\b[^>]*class=["\'][^"\']*\b(?:language-)?poetry\b[^"\']*["\']~is',
            $contentHtml
        ) === 1;
        $hasCode = preg_match('~<pre\b[^>]*>\s*<code\b~is', $contentHtml) === 1;

        $withoutMedia = preg_replace('~<pre\b[^>]*>.*?</pre>~is', '', $contentHtml);
        $withoutMedia = preg_replace('~<figure\b[^>]*>.*?</figure>~is', '', $withoutMedia);
        $withoutMedia = preg_replace('~<(?:img|video|iframe)\b[^>]*>~is', '', $withoutMedia);
        $withoutMedia = preg_replace('~</(?:video|iframe)>~is', '', $withoutMedia);
        $text = trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($withoutMedia), ENT_QUOTES | ENT_HTML5, "UTF-8")
        ));

        $type = "";
        if ($leadingImageFigure && $imageCount > 1) {
            $type = "post-gallery";
        } elseif ($hasPoetry && $imageCount === 0 && $videoCount === 0 && $text === "") {
            $type = "post-poetry";
        } elseif ($hasCode && !$hasPoetry && $imageCount === 0 && $videoCount === 0 && $text === "") {
            $type = "post-code";
        } elseif ($videoCount > 0 && $imageCount === 0 && !$hasPoetry && !$hasCode && $text === "") {
            $type = "post-video";
        } elseif ($leadingImageFigure && $imageCount === 1) {
            // A single leading image defines the post type even when text accompanies it.
            $type = "post-image";
        } elseif ($imageCount === 0 && $videoCount === 0 && !$hasPoetry && !$hasCode && $text !== "") {
            $type = "post-text";
        }

        return array("content" => $contentHtml, "class" => $type);
    }
}
