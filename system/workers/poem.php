<?php
// Preserve intentional line breaks in poetry blocks.

class YellowPoem {
    const VERSION = "1.0.1";
    public $yellow;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
    }

    // Convert fenced Markdown code blocks marked with the "poem" language.
    public function onParseContentHtml($page, $output) {
        if (!is_string($output) || stripos($output, "poem") === false) return null;

        $pattern = '/<pre\\b[^>]*>\\s*<code\\b([^>]*)>(.*?)<\\/code>\\s*<\\/pre>/is';
        return preg_replace_callback($pattern, function ($matches) {
            if (!preg_match('/class="([^"]*)"/', $matches[1], $classMatch)) return $matches[0];
            $classes = preg_split('/\\s+/', trim($classMatch[1]));
            if (!in_array("language-poem", $classes, true) &&
                !in_array("poem", $classes, true) &&
                !in_array("code-poem", $classes, true)) return $matches[0];

            $text = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, "UTF-8");
            $text = str_replace(array("\\r\\n", "\\r"), "\\n", $text);
            $text = preg_replace("/\\n+$/", "", $text);
            return '<div class="yellow-poem">'.nl2br(htmlspecialchars($text, ENT_QUOTES, "UTF-8"), false).'</div>';
        }, $output);
    }

    // Load poem styling only on pages that contain a poem.
    public function onParsePageOutput($page, $output) {
        if (!is_string($output) || strpos($output, 'class="yellow-poem"') === false) return null;
        $stylesheet = '<link rel="stylesheet" href="/media/yellow-poem/poem.css">';
        if (stripos($output, "</head>") !== false) {
            return preg_replace("/<\\/head>/i", $stylesheet."</head>", $output, 1);
        }
        return $stylesheet.$output;
    }
}
