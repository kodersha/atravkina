# Yellow Post Type

Automatically assigns a semantic CSS class to a blog article based on its rendered content.

Supported classes:

- `post-text` — text-only post.
- `post-image` — post with one image. Text accompanying the image remains ordinary post content and is not moved into `figcaption`.
- `post-gallery` — post with multiple images.
- `post-poetry` — post containing a fenced ```poetry code block.
- `post-video` — post containing a video without images or other content.

Standalone image paragraphs are wrapped in `figure`. The extension does not create or modify `figcaption` elements.

The extension is loaded by `system/layouts/blog.html`. The layout keeps the common `emerge` class and appends the detected post class.
