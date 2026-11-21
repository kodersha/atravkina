<?php
// Assign a semantic class to blog posts based on their rendered content.

class YellowPostType {
    const VERSION = "1.1.0";

    /**
     * Normalise rendered content and return its semantic post class.
     *
     * @return array{content:string,class:string}
     */
    public static function process($contentHtml) {
        $contentHtml = self::normaliseContent((string)$contentHtml);

        $imageCount = self::countTags($contentHtml, "img");
        $videoCount = self::countTags($contentHtml, "video|iframe");
        $hasCode = preg_match('~<pre\b[^>]*>\s*<code\b~i', $contentHtml) === 1;
        $hasPoetry = self::hasLanguageClass($contentHtml, "porty");
        $hasLeadingImage = preg_match(
            '~^\s*<figure\b[^>]*>\s*(?:<a\b[^>]*>\s*)?<img\b~i',
            $contentHtml
        ) === 1;
        $hasText = self::hasReadableText($contentHtml);

        return array(
            "content" => $contentHtml,
            "class" => self::detectClass(
                $imageCount,
                $videoCount,
                $hasCode,
                $hasPoetry,
                $hasLeadingImage,
                $hasText
            )
        );
    }

    /** Remove empty paragraphs and wrap standalone image paragraphs in figures. */
    private static function normaliseContent($contentHtml) {
        $contentHtml = preg_replace('~<p(?:\s[^>]*)?>\s*</p>~i', "", $contentHtml);
        return preg_replace(
            '~<p\b[^>]*>\s*((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)\s*</p>~i',
            '<figure>$1</figure>',
            $contentHtml
        );
    }

    private static function countTags($contentHtml, $tagPattern) {
        return preg_match_all('~<(?:' . $tagPattern . ')\b~i', $contentHtml, $matches);
    }

    /**
     * Detect a fenced block whose language/class is exactly "porty".
     * Yellow places the fenced-code class on either <pre> or <code>.
     */
    private static function hasLanguageClass($contentHtml, $language) {
        if (!preg_match_all('~<(?:pre|code)\b([^>]*)>~i', $contentHtml, $elements)) {
            return false;
        }

        foreach ($elements[1] as $attributes) {
            if (!preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~i', $attributes, $classMatch)) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($classMatch[2]));
            foreach ($classes as $class) {
                if ($class === $language || $class === "language-" . $language) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Text inside figures (including figcaption), code blocks and media is
     * intentionally excluded from the text classification.
     */
    private static function hasReadableText($contentHtml) {
        $textContent = preg_replace('~<(?:pre|figure|script|style)\b[^>]*>.*?</(?:pre|figure|script|style)>~is', "", $contentHtml);
        $textContent = preg_replace('~<(?:img|video|iframe)\b[^>]*>~is', "", $textContent);
        $textContent = preg_replace('~</(?:video|iframe)>~is', "", $textContent);
        $textContent = html_entity_decode(
            strip_tags($textContent),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8"
        );
        return trim(preg_replace('/\s+/u', ' ', $textContent)) !== "";
    }

    private static function detectClass($imageCount, $videoCount, $hasCode, $hasPoetry, $hasLeadingImage, $hasText) {
        if ($hasPoetry) {
            return "class-poety";
        }

        if ($imageCount === 1) {
            if ($hasLeadingImage && $hasText) {
                return "class-textnote";
            }
            if (!$hasText) {
                return "class-picture";
            }
            return "post-image";
        }

        if ($imageCount > 1 && $hasLeadingImage) {
            return "post-gallery";
        }

        if ($videoCount > 0 && $imageCount === 0) {
            return "post-video";
        }

        if ($hasCode && $imageCount === 0 && $videoCount === 0) {
            return "post-code";
        }

        if ($imageCount === 0 && $videoCount === 0 && $hasText) {
            return "class-note";
        }

        return "";
    }
}
