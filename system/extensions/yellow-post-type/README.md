# Yellow Post Type

Automatically assigns a semantic CSS class to blog articles based on their rendered content.

Supported classes:

- `post-image` — one image without text.
- `post-image-text` — one image with accompanying text; the text is moved into a `figcaption` below the image.
- `post-gallery` — multiple images.
- `post-video` — one video without other content.
- `post-poetry` — a single fenced poetry block.
- `post-text` — text-only post.

The extension is loaded by `system/layouts/blog.html`. The common `emerge` class is retained by the layout.
