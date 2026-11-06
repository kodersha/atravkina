<?php
// Preserve intentional line breaks in poetry blocks.

class YellowPoem {
    const VERSION = "1.0.0";
    public $yellow;

    public function onLoad($yellow) {
        $this->yellow = $yellow;
    }

    // Render fenced Markdown blocks marked as "poem".
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        if ($name !== "poem" || $type !== "code") return null;

        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace("/\n+$/", "", $text);
        $lines = explode("\n", $text);
        $output = '<div class="yellow-poem" role="figure">';
        foreach ($lines as $index => $line) {
            if ($index > 0) $output .= "<br>";
            $output .= htmlspecialchars($line, ENT_QUOTES, "UTF-8");
        }
        $output .= "</div>";
        return $output;
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
