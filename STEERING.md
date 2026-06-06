# Nexus Agent Guide (streamlined)
Full guide: [AGENTS.md](AGENTS.md) (reference only — do not read unless the user explicitly asks or you hit something not covered by this guide; see BOOTSTRAP.md)

## What is Nexus
Personal agentic CMS. Posts = tasks. Comment threads = work log. MCP server is pre-registered — no auth setup needed. Your identity is one of `coder-agent`, `reviewer-agent`, `qa-agent`, or `scrum-agent` — use it as your `author` on every comment.

## Workflow
1. Human tells you which post to work on ("work on post 42" or a Nexus URL).
2. `posts/get {"id": 42}` — returns full content + all comments in one call.
3. Read post body + full comment thread before doing anything. Prior context lives there.
4. Do the work.
5. `posts/add-comment` with `author` set to your agent identity.
6. Human reads and decides next step.

## Resolving a Nexus URL to a post
When given a Nexus site URL (e.g. `https://YOUR_DOMAIN/some-post-slug/`):
- Do NOT open it in the browser.
- Extract the slug from the path and run `posts/list {"search": "slug-keyword"}` to find the post ID.
- Then use `posts/get {"id": N}` as normal.

## Key MCP abilities (all via mcp-adapter-execute-ability)

| Ability | Essential params |
|---|---|
| `posts/list` | `category?`, `tag?`, `per_page?`, `search?` |
| `posts/get` | `id` |
| `posts/add-comment` | `post_id`, `content`, `author` (always set this) |
| `posts/update-comment` | `id`, `content?`, `author?` |
| `posts/get-comment` | `id` — returns raw markdown |
| `posts/create` | `title`, `content?` |
| `posts/update` | `id`, fields to change |
| `media/list` | `search?`, `per_page?` (Search by filename or title) |
| `media/get` | `id` |
| `media/upload` | `filename`, `content`, `encoding?` |

Comment content renders as markdown.

## Media template pattern
1. `media/list {"search": "template-name"}` → get attachment ID
2. `media/get {"id": N}` → read content as plain text
3. Fill in the template locally
4. `media/upload {"filename": "result.md", "content": "...", "encoding": "text"}`

## REST API credentials (fallback only)
Skate keys follow this convention:
- `app:<username>@<domain>` — Application Password for REST API / curl (use this one)
- `<username>@<domain>` — regular login password (do NOT use for API calls)

`curl -u` auto-encodes Basic Auth:
```bash
curl -u "qa-agent:$(skate get 'app:qa-agent@YOUR_DOMAIN')" https://YOUR_DOMAIN/wp-json/wp/v2/media
```

## Tools available
- **Nexus MCP** — primary interface for all task/post work.
- **chrome-devtools MCP** — use for external web exploration only. Never use it to open Nexus post URLs.

## Blockers
If you hit a blocker that requires human action (authentication, approval, a decision, credentials), stop and clearly state what you need. Do not proceed or guess — wait for the human to unblock you.

## Constraints
- Human assigns tasks — never self-assign.
- Never change task status unless explicitly told to.
- Never create/delete categories, install plugins, or modify site settings.
- When commenting, pass your username (e.g. `coder-agent`), not your display name (e.g. "Coder Agent") — mismatches trigger an `author_conflict` error.
