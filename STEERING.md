# Nexus Agent Guide (streamlined)
Full guide: [AGENTS.md](AGENTS.md) (reference only — do not read unless the user explicitly asks or you hit something not covered by this guide; see BOOTSTRAP.md)

## What is Nexus
Personal agentic CMS. Posts = tasks. Comment threads = work log. MCP server is pre-registered — no auth setup needed. Your identity is one of `coder-agent`, `reviewer-agent`, `qa-agent`, or `scrum-agent` — use it as your `author` on every comment.

## Workflow
1. Human tells you which post to work on ("work on post 42" or a Nexus URL).
2. `posts-get {"id": 42}` — returns full content + all comments in one call.
3. Read post body + full comment thread before doing anything. Prior context lives there.
4. Do the work.
5. `posts-add-comment` with `author` set to your agent identity.
6. Human reads and decides next step.

## Resolving a Nexus URL to a post
When given a Nexus site URL (e.g. `https://YOUR_DOMAIN/some-post-slug/`):
- Do NOT open it in the browser.
- Extract the slug — the last path segment (`some-post-slug`) — and run `posts-list {"slug": "some-post-slug"}`. This matches the slug exactly and returns the post directly. (Do **not** use `search` for this — `search` only matches title/content, not the URL slug.)
- Then use `posts-get {"id": N}` with the returned id.

## Key MCP abilities

Each ability is exposed as its **own flat MCP tool** — the tool name is the ability with the slash replaced by a dash (`posts/get` → tool `posts-get`). Call it directly and pass its fields as **top-level arguments**:

```
posts-add-comment {"post_id": 5, "content": "...", "author": "coder-agent"}
```

| Tool | Essential args / return shape |
|---|---|
| `posts-list` | `slug?` (exact, for URL resolution), `search?` (title/content), `category?`, `tag?`, `per_page?` — **returns** `{ posts: [...] }` |
| `posts-get` | `id` |
| `posts-add-comment` | `post_id`, `content`, `author` (always set this) |
| `posts-update-comment` | `id`, `content?`, `author?` |
| `posts-get-comment` | `id` — returns raw markdown |
| `posts-create` | `title`, `content?` |
| `posts-update` | `id`, fields to change |
| `media-list` | `search?`, `per_page?` (Search by filename or title) — **returns** `{ media: [...] }` |
| `media-get` | `id` |
| `media-upload` | `filename`, `content`, `encoding?` |

Comment content renders as markdown.

## Calling abilities correctly (avoid these footguns)
- **Every ability is its own flat tool** (`posts-get`, `posts-add-comment`, …) — there is no generic `execute-ability` wrapper to call through. Call it directly and pass each field (`id`, `post_id`, `content`, …) as a typed top-level argument.
- **Populate required args before calling.** Don't send `{}` when a tool needs args (e.g. `posts-get` requires `id`). A missing field fails with `<field> is a required property of input`.
- **A validation error is NOT a server outage.** `... is required` / `not of type object` mean the server rejected your *input* — it is healthy. Fix the args and re-call. Do **not** retry the same malformed call: repeated failures trip your client's circuit breaker, which then falsely reports the MCP server as "unreachable." If that happens the server is fine — correct your call (or wait out the short cooldown) and continue.

## Media template pattern
1. `media-list {"search": "template-name"}` → get attachment ID
2. `media-get {"id": N}` → read content as plain text
3. Fill in the template locally
4. `media-upload {"filename": "result.md", "content": "...", "encoding": "text"}`

## REST API credentials (fallback only)
Skate keys follow this convention:
- `app:<username>@<domain>` — Application Password for REST API / curl (use this one)
- `login:<username>@<domain>` — regular login password (do NOT use for API calls)

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
- When commenting, pass your username (e.g. `coder-agent`), not your display name (e.g. "Coder Agent"). Passing your username attributes the comment to your own agent account (with its own avatar); passing a display name triggers an `author_conflict` error.
