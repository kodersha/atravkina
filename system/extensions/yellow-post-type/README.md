# Yellow Post Type

Automatically assigns a semantic CSS class to a blog article based on its rendered content.

Supported classes:

- `post-text` — text-only post.
- `post-singlemage` — post containing exactly one image and no text.\n- `post-image` — post with one image accompanied by text.
- `post-gallery` — post with multiple images.
- `post-poetry` — post containing a fenced ```poetry code block.
- `post-code` — post containing a regular fenced code block.
- `post-video` — post containing a video without images or other content.

Standalone image paragraphs are wrapped in `figure`. The extension does not create or modify `figcaption` elements.

The extension is loaded by `system/layouts/blog.html`. The layout keeps the common `emerge` class and appends the detected post class.
