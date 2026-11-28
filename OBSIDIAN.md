# Obsidian Vault

This repository is the single source of truth for the Outer Haven site and the Obsidian vault.

## Open the vault

Open the repository root (`kodersha/atravkina`) as an Obsidian vault. Do not create a second vault or copy posts into another folder.

## Posts

Create and edit posts in `content/1-blog/`.

1. Create a Markdown note from the `templates/Post.md` template.
2. Set the filename to the same Unix timestamp shown in the `Title` field, followed by `.md` (for example, `1795318945.md`).
3. Keep the Yellow frontmatter fields: `Title`, `Layout: blog`, and `Status`.
4. New notes start as `Status: draft`. Change it to `Status: published` when the post is ready to appear on the site.
5. Keep the timestamp title convention; do not replace it with an editorial title.

## Images

Paste or add images to the vault. Obsidian is configured to place new attachments in `media/images/`. Markdown links are enabled so image embeds are stored as ordinary Markdown rather than Obsidian-only wikilinks.

Keep images in `media/images/`; do not store them in the post folder or in a separate vault attachment directory.

## Publishing

Install and enable the **Obsidian Git** community plugin. Configure it to commit and push to the `main` branch. A push to `main` starts GitHub Actions, which compiles SCSS and deploys the repository to the hosting account over SSH.

The repository's GitHub Actions deployment includes `content/` and `media/`. The scheduled hosting-to-GitHub sync has been removed: GitHub is now the canonical source, and the hosting copy is a deployment target.

## Important

- Do not edit posts through Yellow Edit on the live site; make all post changes in Obsidian.
- Do not run a server-to-GitHub sync that overwrites repository content.
- Keep the existing Yellow Markdown syntax and metadata conventions when editing older posts.
