# Agent Guide — Nexus

Nexus is a personal agentic CMS. Posts are tickets or knowledge items. Comment threads are the work log.

You interact with it through the Nexus MCP server. You do not act autonomously — the human tells you which task to work on and when.

## Authentication

### MCP (primary)

The Nexus MCP server is registered in your AI client. Use it directly — no extra setup needed. It authenticates as the admin user created during setup.

For authorship, pass your agent name in the `author` field of `posts/add-comment` — you do not need separate credentials.

### REST API (fallback / agent-specific identity)

Base URL: `https://YOUR_DOMAIN/wp-json/wp/v2/`

> If you configured a custom `WP_PORT`, append it to the domain throughout: `https://YOUR_DOMAIN:WP_PORT/...`

Credentials are stored in the credential manager chosen during setup (skate is the recommended default). Retrieve with the appropriate command for your chosen store.

Credentials are stored under two key namespaces in skate:

| Key pattern | Contains | Use for |
|---|---|---|
| `app:<username>@YOUR_DOMAIN` | Application Password | REST API / curl calls |
| `<username>@YOUR_DOMAIN` | Login password | Do NOT use for API calls |

Always use the `app:` key for API authentication:

```bash
skate get app:coder-agent@YOUR_DOMAIN
skate get app:reviewer-agent@YOUR_DOMAIN
skate get app:scrum-agent@YOUR_DOMAIN
skate get app:qa-agent@YOUR_DOMAIN
```

Use Basic Auth: `username:app_password` (Application Password format — includes spaces, use as-is).

```bash
curl -s -u "coder-agent:$(skate get app:coder-agent@YOUR_DOMAIN)" \
  https://YOUR_DOMAIN/wp-json/wp/v2/posts
```

## Data model

### Posts = Tasks

Each post is one task or ticket.

| Field | Meaning |
|---|---|
| `id` | Stable task identifier — use this to reference tasks |
| `title.rendered` | Task title |
| `content.rendered` | Full task description (HTML) |
| `status` | Always `publish` for active tasks |
| `categories` | Should include the `Tasks` category |
| `tags` | Optional tags (status tracking is not enforced by the system) |
| `link` | Browser URL for the task |

### Comments = Work log

The comment thread on a post is the full history: agent outputs, feedback, sign-offs. Always read the full thread before starting work on a task — it contains prior context, partial results, and instructions from the human.

| Field | Meaning |
|---|---|
| `post` | ID of the parent task |
| `content.rendered` | Comment body (HTML) |
| `author_name` | Who wrote it |
| `date` | Timestamp |

Fetch a thread in chronological order:
```
GET /comments?post=<id>&order=asc&per_page=100
```

### Taxonomy

**Category:** `Tasks` — all task posts belong to this category. Get its ID:
```
GET /categories?search=Tasks
```

## Workflow

The human directs you verbally. A typical interaction looks like:

1. Human: "Work on post 42."
2. Call `posts/get` with `{"id": 42}` — returns full content and all comments in one call.
3. Read everything — post body + full thread — before doing any work.
4. You do the work.
5. Call `posts/add-comment` with your `author` name set to your agent identity.
6. Human reads your comment and decides what to do next.

Never assume you know the current state of a post without reading it first. The thread may contain instructions, corrections, or partial work from a previous session.

## MCP tools

### Protocol tools

| Tool | Use it to |
|---|---|
| `mcp-adapter-discover-abilities` | List all registered abilities |
| `mcp-adapter-get-ability-info` | Inspect the input/output schema for an ability |

There is **no** generic execute-ability tool. Every ability is exposed as its own flat MCP tool.

### Post abilities

Each ability is its own flat MCP tool: the tool name is the ability with the slash replaced by a dash (`posts/get` → `posts-get`). Call it directly and pass its fields as **top-level arguments**.

| Ability | Parameters | Notes |
|---|---|---|
| `posts/list` | `slug?` (exact, for URL resolution), `search?` (title/content), `category?`, `tag?`, `per_page?` (default 20) | Returns summaries under a `posts` key |
| `posts/get` | `id` | Returns full content + all approved comments |
| `posts/create` | `title`, `content?`, `category?`, `tags?` | Creates a published post |
| `posts/update` | `id`, `title?`, `content?`, `tags?` | Only provided fields change |
| `posts/add-comment` | `post_id`, `content`, `author` | Pass `author` as your agent username (e.g. `coder-agent`); the comment is then attributed to that account, with its own avatar. Content supports markdown. Errors if comments are closed on the post (`comments_closed`) or if `author` is another registered user's display name (`author_conflict`) — always pass the username, not the display name (e.g. `"Coder Agent"`). |
| `posts/get-comment` | `id` | Returns raw markdown (not rendered HTML) |
| `posts/get-latest-comment` | `author?` | Most recent comment site-wide; pass `author` to filter by agent name |
| `posts/update-comment` | `id`, `content?`, `author?` | Edit an existing comment |

Always pass `author` when commenting so authorship is visible in the thread.

Comment content is rendered as markdown on the frontend — use standard markdown syntax (` ``` ` code blocks, `**bold**`, bullet lists, etc.). `posts/get-comment` and `posts/get-latest-comment` return the raw markdown source, so you can read and edit cleanly.

### Media abilities

| Ability | Parameters | Notes |
|---|---|---|
| `media/list` | `search?`, `per_page?` (default 20) | Search by filename or title |
| `media/get` | `id` | Returns file content; `encoding` is `"text"` or `"base64"`. Files over 50 MB return a `file_too_large` error. |
| `media/upload` | `filename`, `content`, `encoding?`, `title?`, `description?` | `encoding` defaults to `"text"` |

### When to use MCP vs REST API for uploads

| File type | Method | Why |
|---|---|---|
| Text files (md, json, yaml, txt) | MCP `media/upload` with `encoding: "text"` | Content passes as a plain string — no extra steps |
| Binary files (images, PDFs) | REST API with `curl --data-binary` | More reliable; base64-encoding large binaries through MCP can hit context limits |

Binary upload via REST:
```bash
curl -u "qa-agent:$(skate get 'app:qa-agent@YOUR_DOMAIN')" \
  -H "Content-Disposition: attachment; filename=image.png" \
  -H "Content-Type: image/png" \
  --data-binary @/path/to/image.png \
  https://YOUR_DOMAIN/wp-json/wp/v2/media
```

Template fill-in pattern:
1. `media/list` with `{"search": "template-name"}` → get attachment ID
2. `media/get` with that ID → read content as plain string
3. Fill in the template
4. `media/upload` with `{"filename": "result.md", "content": "...", "encoding": "text"}`

## REST API (manual / fallback)

The REST API is available for debugging or one-off edits. Agents use MCP abilities instead.

```bash
# Read a post
curl -s -u "AGENT:PASS" https://YOUR_DOMAIN/wp-json/wp/v2/posts/42

# Read its comment thread (oldest first)
curl -s -u "AGENT:PASS" \
  "https://YOUR_DOMAIN/wp-json/wp/v2/comments?post=42&order=asc&per_page=100"
```

## Constraints

- You do not self-assign tasks. The human tells you which post to work on.
- You do not change task status unless explicitly told to.
- Read the full comment thread before acting — context from prior sessions lives there.
- Always pass `author` when calling `posts/add-comment` — use the agent identity that matches your role (the usernames chosen during interactive setup) so the human can see who did what in the thread.
- Do not create or delete categories. Do not install plugins. Do not modify site settings.