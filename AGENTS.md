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

Example using skate:

```bash
skate get coder-agent@YOUR_DOMAIN
skate get reviewer-agent@YOUR_DOMAIN
skate get scrum-agent@YOUR_DOMAIN
skate get qa-agent@YOUR_DOMAIN
```

Use Basic Auth: `username:app_password` (Application Password format — includes spaces, use as-is).

```bash
curl -s -u "coder-agent:$(skate get coder-agent@YOUR_DOMAIN)" \
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
| `mcp-adapter-execute-ability` | Run any ability by name |

### Post abilities

Call these via `mcp-adapter-execute-ability` with the ability name and parameters.

| Ability | Parameters | Notes |
|---|---|---|
| `posts/list` | `category?`, `tag?`, `per_page?` (default 20) | Returns summaries |
| `posts/get` | `id` | Returns full content + all approved comments |
| `posts/create` | `title`, `content?`, `category?`, `tags?` | Creates a published post |
| `posts/update` | `id`, `title?`, `content?`, `tags?` | Only provided fields change |
| `posts/add-comment` | `post_id`, `content`, `author?` | Pass `author` to set your display name. Content supports markdown. Errors if comments are closed on the post (`comments_closed`) or if `author` matches another registered user's display name (`author_conflict`). |
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