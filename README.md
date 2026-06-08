# Nexus

![Nexus](https://cdn.leunam.me/file/leunames-cdn/IMG_0221.png)

A personal agentic CMS. A shared knowledge repository where you and your agents collaborate through clean, natural-language threads.

Use it as a task board, documentation hub, coordination layer, or anything else you need. Agents interact via MCP, you use the familiar WordPress interface. One system, multiple access patterns.

## Why Nexus?

Most agent workflows today are fragmented across tools, chats, and temporary contexts. Nexus gives you and your agents a persistent, structured place to work together.

The classic example is using it as an AI-assisted task board (posts are tickets, comment threads are the work log). But the same system works just as well for documentation, research notes, runbooks, or any shared context you want agents to reliably access and update.

Posts are tickets. Comment threads are the work log.

Agents read tasks and context through MCP, then communicate back using normal WordPress comments. They write in raw markdown (clean for agents), while you see everything nicely rendered. The same repository is accessible via the WordPress GUI, REST API, or MCP depending on what you need.

The result is cleaner collaboration between agents, less copy-paste, and a clear, auditable record of the work.

## Quick Start (Recommended)

The fastest way to get started is to let an LLM guide you through the setup interactively.

1. Copy the contents of `PROMPT.md`
2. In your AI client, say something like:
   > "Follow PROMPT.md to set up Nexus for me."
3. The LLM will walk you through configuration (domain, `WP_PORT` for a custom HTTPS port, credentials, etc.) and only run commands after your explicit confirmation.

This path is designed to be safe and transparent — you stay in control at every step.

## Manual Setup

If you prefer to set things up manually (to understand the internals or customize the configuration), follow the steps below.

## Stack

| Service | Image | Role |
|---|---|---|
| WordPress | `dhi.io/wordpress:7.0-php8.5-fpm` | PHP-FPM, app logic |
| MariaDB | `mariadb:11` | Database |
| Caddy | `caddy:2-alpine` | TLS termination, reverse proxy |

DB and WordPress are on an internal network (`backend`) with no outbound internet access. Caddy sits on both `backend` and `frontend` networks and is the only container with host-facing ports.

## Project structure

```
.
├── Caddyfile            # Caddy reverse proxy config (reads WP_DOMAIN from env)
├── docker-compose.yml   # Service orchestration
├── .env.example         # Environment variable template (WP_DOMAIN, WP_PORT, passwords)
├── uploads.ini          # PHP upload limits (100 MB, md/yaml/json/pdf allowed)
├── mu-plugins/
│   ├── allow-uploads.php       # Extends WordPress MIME type allowlist
│   ├── post-abilities.php      # Registers posts/*, media/* MCP abilities
│   ├── mcp-flat-tools.php      # Exposes each ability as a flat MCP tool; drops the execute-ability wrapper
│   ├── nexus-defaults.php      # Seeds opinionated WP settings on first boot (idempotent, version-gated)
│   ├── markdown-comments.php   # Renders markdown in comments at display time
│   └── Parsedown.php           # Markdown parser (bundled; no Composer)
├── certs/               # TLS cert and key (gitignored)
├── backups/             # DB and file backups (gitignored)
└── backup.sh            # Backup script
```

---

## Prerequisites

- Docker Desktop
- OpenSSL (ships with macOS / most Linux distros)
- A credential manager or secret store of your choice (skate, 1Password, Bitwarden, `pass`, or even a protected `.env` file)

---

### 1. Add your domain to `/etc/hosts`

```
127.0.0.1   YOUR_DOMAIN
```

### 2. Generate a self-signed TLS certificate

The cert needs CA extensions so the OS accepts it in the keychain, and a SAN so browsers accept it.

```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout certs/server.key \
  -out certs/server.crt \
  -subj "/C=US/ST=CA/L=MyCity/O=Local Dev/CN=YOUR_DOMAIN" \
  -addext "subjectAltName=DNS:YOUR_DOMAIN,IP:127.0.0.1" \
  -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
  -addext "keyUsage=critical,keyCertSign,digitalSignature"
```

Trust it in your operating system (for browsers and curl):

```bash
security add-trusted-cert -r trustRoot \
  -k ~/Library/Keychains/login.keychain-db \
  /absolute/path/to/nexus/certs/server.crt     # macOS example
```

> **Important:** You can only trust one self-signed certificate per domain user-wide via the keychain. Trusting a different certificate for the same domain later will cause certificate errors and break the MCP connection. Only run this once per domain.

**Per-application fallback (optional)**

If trusting the certificate system-wide in the keychain doesn't work for a specific tool, you can instead trust it at the application level. The right approach depends on the runtime the tool uses.

*Node.js-based CLIs* (Claude Code, many MCP clients):

```bash
echo 'export NODE_EXTRA_CA_CERTS="/absolute/path/to/nexus/certs/server.crt"' >> ~/.zshrc
source ~/.zshrc
# (fish / other shells: equivalent syntax)
```

*Python-based clients* (Hermes, LangChain-based agents, etc.) use certifi's own CA bundle and ignore both the system keychain and `NODE_EXTRA_CA_CERTS`. The safe fix is to append your cert to certifi's bundle and point `SSL_CERT_FILE` at the result — do **not** point `SSL_CERT_FILE` at your cert alone, as that replaces the entire CA bundle and breaks all other HTTPS connections.

```bash
# One-time: build a combined bundle
python3 -c "import certifi; print(certifi.where())"   # find certifi's bundle path
cat /path/to/certifi/cacert.pem \
    /absolute/path/to/nexus/certs/server.crt \
    > /path/to/combined-ca-bundle.pem

# Then add to the tool's env file or your shell profile:
export SSL_CERT_FILE=/path/to/combined-ca-bundle.pem
```

Regenerate the combined bundle if certifi updates (rare in a fixed dev environment).

> Most users can skip this step.

### 3. Configure `.env`

```bash
cp .env.example .env
# edit .env — set WP_DOMAIN, WP_PORT, DB_ROOT_PASSWORD, DB_PASSWORD
```

Then export the non-secret variables into your current shell. `docker compose` reads `.env` automatically, but your host shell does not — later steps use `${WP_PORT:+:$WP_PORT}` which requires `WP_PORT` to be set in the shell:

```bash
export WP_PORT=$(grep '^WP_PORT=' .env | cut -d= -f2 | tr -d ' "\r')
```

Only `WP_PORT` is consumed by shell commands — domain references use the literal `YOUR_DOMAIN` placeholder. Sourcing the full `.env` is avoided because passwords may contain `$`, `#`, or spaces that the shell would misparse. Re-run this in any new terminal before running the setup commands.

> **Note on `WP_HOME` / `WP_SITEURL`:** `docker-compose.yml` sets these as PHP constants via `WORDPRESS_CONFIG_EXTRA`. PHP constants override the WordPress database options, so `wp option update siteurl` and `wp option update home` have no effect. If the site URL ever looks wrong, edit the constants in `docker-compose.yml` and recreate the WordPress container (`docker compose up -d --force-recreate wordpress`).

### 4. Pre-chown the WordPress volume

The hardened image runs as `nonroot` (uid 65532). Pre-create the `wp-content/` tree so the container can write on first start.

**Why this matters:** `docker-compose.yml` bind-mounts `./mu-plugins` into `wp-content/mu-plugins`. When Docker sets up that mount it creates the `wp-content/` path component as root — even if the parent volume is already chowned. The image entrypoint (running as uid 65532) then can't create anything inside `wp-content/`, so themes, plugins, cache, and upgrade directories are never populated. The site loads a blank page. Pre-creating the directories with the correct owner prevents Docker from creating `wp-content/` as root in the first place.

```bash
# Pull all images first so caddy:2-alpine is available for the helper container below
docker compose pull

docker compose up -d db

docker run --rm -v wp-local_wp_files:/var/www/html caddy:2-alpine sh -c "
  mkdir -p /var/www/html/wp-content/themes \
           /var/www/html/wp-content/plugins \
           /var/www/html/wp-content/uploads \
           /var/www/html/wp-content/cache \
           /var/www/html/wp-content/upgrade &&
  chown -R 65532:65532 /var/www/html
"
```

### 5. Start the stack

```bash
docker compose up -d
docker compose logs -f   # watch until wordpress is healthy (~30s)
```

### 6. Install WordPress

```bash
docker compose run --rm wpcli wp core install \
  --url="https://YOUR_DOMAIN${WP_PORT:+:$WP_PORT}" \
  --title="Nexus" \
  --admin_user="YOUR_USERNAME" \
  --admin_password="YOUR_ADMIN_PASSWORD" \
  --admin_email="noreply@local.dev" \
  --skip-email
```

> The `${WP_PORT:+:$WP_PORT}` expansion handles the port automatically once `WP_PORT` is exported (step 3). The URL written here is stored in the database but overridden at runtime by the PHP constants in `docker-compose.yml`, so a mismatch won't break the site.

> Always run `docker compose up -d` before any `docker compose run --rm wpcli` command. If the stack isn't running, `run` starts and stops services itself, leaving the network in a broken state.

### 7. Install a default theme

The hardened WordPress image does not bundle themes, and the wpcli container has no outbound internet access. Download on the host and copy in via a helper container — the same pattern as the volume pre-chown in step 4.

```bash
curl -sL "https://downloads.wordpress.org/theme/twentytwentyfive.latest-stable.zip" \
  -o /tmp/twentytwentyfive.zip
unzip -q /tmp/twentytwentyfive.zip -d /tmp/

# caddy:2-alpine is already pulled as part of the stack — use it to avoid a fresh pull
docker run --rm \
  -v wp-local_wp_files:/var/www/html \
  -v /tmp/twentytwentyfive:/tmp/twentytwentyfive \
  caddy:2-alpine sh -c "
    mkdir -p /var/www/html/wp-content/themes/twentytwentyfive &&
    cp -r /tmp/twentytwentyfive/. /var/www/html/wp-content/themes/twentytwentyfive &&
    chown -R 65532:65532 /var/www/html/wp-content/themes/twentytwentyfive
  "

docker compose run --rm wpcli wp theme activate twentytwentyfive
```

Verify:

```bash
docker compose run --rm wpcli wp theme list
# twentytwentyfive   active   ...
```

### 8. Enable pretty permalinks

```bash
docker compose run --rm wpcli wp rewrite structure '/%postname%/' --hard
```

The `.htaccess` warning is harmless — Caddy handles routing.

> **Note:** the `nexus-defaults.php` mu-plugin (below) also forces this permalink
> structure on first boot, so this step is belt-and-suspenders. It's required:
> agents resolve a Nexus URL by its last path segment (the post slug), which only
> exists under `/%postname%/` — a plain `?p=N` install has no slug to match.

### Auto-seeded settings (`nexus-defaults.php`)

On the **first request** after a fresh install, `nexus-defaults.php` seeds an
opinionated set of options for a private, localhost-only agentic CMS. It's gated
by the `nexus_seed_version` option, so it runs **once** and never clobbers
changes you later make in wp-admin. Bump `NEXUS_SEED_VERSION` in the file to roll
out a new batch (re-applies the whole set once).

| Option | Value | Why |
|---|---|---|
| `permalink_structure` | `/%postname%/` | Slug-based URL resolution depends on it |
| `blog_public` | `0` | Discourage search-engine indexing |
| `thread_comments` | `0` | Work log is linear, not threaded |
| `page_comments` | `0` | Don't paginate the work log |
| `close_comments_for_old_posts` | `0` | Old tasks keep accepting comments |
| `comment_order` | `asc` | Oldest-first reads as a chronological log |
| `default_comment_status` | `open` | Tasks accept comments by default |
| `show_avatars` | `1` | Per-agent identicons (see comment attribution) |
| `show_comments_cookies_opt_in` | `0` | Irrelevant cookie checkbox for a backend |
| `comment_moderation` | `0` | No manual-approval gate |
| `comment_previously_approved` | `0` | **Otherwise every agent comment is held → invisible to `posts-get`** |
| `comments_notify` / `moderation_notify` | `0` | No outbound email noise |
| `default_ping_status` | `closed` | Not a public blog — no trackbacks |
| `default_pingback_flag` | `0` | Don't notify linked blogs |
| `timezone_string` | `$WORDPRESS_TIMEZONE` (default `UTC`) | Local-time work-log timestamps |

It also removes WordPress' stock sample content on first boot — the `Hello world!`
post, the `Sample Page`, and the draft `Privacy Policy` page — matched by their
default slugs, so only the untouched defaults are ever deleted (real task posts
have their own slugs and are never matched).

**Comment attribution:** `posts-add-comment` resolves the `author` field against
registered non-admin accounts by login. Passing `coder-agent` attributes the
comment to that account (`user_id`, email → a distinct gravatar/identicon),
rather than to the API credential's owner. This is why each agent shows a
different avatar despite all calls authenticating as the same user.

### 9. Create additional users (optional)

```bash
docker compose run --rm wpcli wp user create coder-agent    coder@local.dev    --role=editor --user_pass="PASS" --display_name="Coder Agent"
# ... repeat for other users
```

### 10. Generate Application Passwords

Application passwords are the recommended way for MCP / API access.

```bash
# Generate (password shown once)
docker compose run --rm wpcli wp user application-password create YOUR_USERNAME  "Personal" --porcelain
docker compose run --rm wpcli wp user application-password create coder-agent    "API" --porcelain
# ... repeat for other users

# Store the passwords in your chosen credential manager under the `app:` namespace
skate set 'app:coder-agent@YOUR_DOMAIN' "<the password shown above>"
skate set 'app:qa-agent@YOUR_DOMAIN' "<the password shown above>"
# ... repeat for reviewer-agent, scrum-agent, etc.
```

### 11. Install the MCP adapter plugin

The wpcli container has no internet access — download on the host first.

```bash
# Download and extract
curl -fsSL -o /tmp/mcp-adapter.zip \
  "https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip"
cd /tmp && unzip -q mcp-adapter.zip

# The zip already contains vendor/ in recent releases. If it does not,
# run this on the host (requires internet access and a local composer install or Docker pull):
# docker run --rm -v /tmp/mcp-adapter:/app -w /app composer:2 install --no-dev --optimize-autoloader --no-interaction

# Copy into the running container
docker cp /tmp/mcp-adapter wp-local-wordpress-1:/var/www/html/wp-content/plugins/mcp-adapter

# Fix ownership
docker run --rm -v wp-local_wp_files:/var/www/html caddy:2-alpine \
  chown -R 65532:65532 /var/www/html/wp-content/plugins

# Activate
docker compose run --rm wpcli wp plugin activate mcp-adapter
```

Verify the MCP namespace is registered:

```bash
curl -sk "https://YOUR_DOMAIN${WP_PORT:+:$WP_PORT}/wp-json/" | python3 -c \
  "import sys,json; d=json.load(sys.stdin); print('mcp' in d.get('namespaces',[]))"
# → True
```

### 12. Register the MCP server in your AI client

The MCP endpoint is:

```
https://YOUR_DOMAIN/wp-json/mcp/mcp-adapter-default-server
```

(With a custom `WP_PORT`, the port is included automatically by the shell commands below — provided `WP_PORT` was exported in step 3.)

Use **Basic Auth** with one of the application passwords created in step 10:

```bash
AUTH=$(echo -n "YOUR_USERNAME:APPLICATION_PASSWORD" | base64)
# header value: Authorization: Basic $AUTH
```

> **Auth scheme gotcha:** client CLIs with a generic `--auth header` flow often default to the `Bearer` scheme. Nexus requires `Basic`. After registering via CLI, verify that the saved header reads `Authorization: Basic ...` and not `Authorization: Bearer ...` — edit the config if not.

**Prefer your client's CLI or registration UI over editing config files by hand.** Most clients that support HTTP-transport MCP servers expose a command that accepts a URL and custom headers — this is safer than editing JSON config directly, since the CLI validates the entry and avoids schema pitfalls (for example, `mcpServers` is not a valid key in Claude Code's `~/.claude/settings.json`; the CLI writes to a separate store).

As a concrete example, Claude Code's `claude mcp add`:

```bash
claude mcp add --transport http --scope user \
  --header "Authorization: Basic $AUTH" \
  -- Nexus "https://YOUR_DOMAIN${WP_PORT:+:$WP_PORT}/wp-json/mcp/mcp-adapter-default-server"
```

> The `--` before the server name is required: `--header` is a variadic flag and will silently consume the server name as an additional header value without it.

Most clients also distinguish between a user-wide (global) and a project-scoped registration — choose whichever matches your intended scope.

After registration, test that the `posts/list`, `posts/get`, `posts/add-comment`, etc. abilities are available.

### 13. Agent Bootstrap (final setup step)

This is the final step of the setup process (requires skate). It wires the agent prefix trigger (`coder-agent:`, `reviewer-agent:`, `qa-agent:`, or `scrum-agent:`) so that your AI client automatically fetches `STEERING.md` from skate and loads it as operational context.

#### 1. Load STEERING.md into skate

Run this command (stores the guide under the key `agents-guide@nexus`):

```bash
skate set "agents-guide@nexus" "$(cat STEERING.md)"
```

Re-run this whenever `STEERING.md` changes.

#### 2. Add the harness instruction to your AI client

Add the instruction block from `BOOTSTRAP.md` to your AI client's global memory file. The table of client files and the exact instruction text are in `BOOTSTRAP.md`.

After adding it, restart your AI client or session.

#### Verification

- Test by prefixing a message with e.g. `coder-agent: hello` in your AI client.
- The agent should respond acknowledging the identity and that the guide was loaded.

This completes the interactive/manual setup.

---

## Credentials

Store application passwords (not the initial login passwords) in your credential manager using a key like `app:username@your-domain`.

> **Migration note (if you set up before this hotfix):** Re-key any existing entries with `skate set 'app:<username>@YOUR_DOMAIN' "<password>"`.

Retrieval is client-specific. Most clients have a `get` or `read` command.

Re-register the MCP server after rotating an application password.

---

## Day-to-day

```bash
docker compose up -d                          # start
docker compose down                           # stop (data preserved in volumes)
docker compose down -v                        # stop + wipe all data
docker compose logs -f caddy                  # Caddy access log
docker compose logs -f wordpress              # PHP-FPM log
docker compose run --rm wpcli wp <command>    # WP-CLI passthrough
./backup.sh                                   # manual backup
```

> Supports custom HTTPS port via `WP_PORT` (see the Quick Start section above).


---

## Maintenance

### Update WordPress core and plugins

The wpcli container has no outbound internet access (internal network). `wp core update` and `wp plugin update --all` will fail if run directly — they need to reach wordpress.org.

Workaround: download the update zip on the host and install from the local file. For example:

```bash
# Core update from a local zip
# Stage onto the shared wp_files volume (/var/www/html) — wpcli and wordpress
# share that volume but have separate /tmp directories.
curl -sL "https://wordpress.org/latest.zip" -o /tmp/wordpress.zip
docker cp /tmp/wordpress.zip wp-local-wordpress-1:/var/www/html/wordpress.zip
docker compose run --rm wpcli wp core update /var/www/html/wordpress.zip
docker compose run --rm wpcli wp --quiet eval 'unlink(ABSPATH."wordpress.zip");'

# Plugin update from a local zip
curl -sL "https://downloads.wordpress.org/plugin/example.zip" -o /tmp/example.zip
docker cp /tmp/example.zip wp-local-wordpress-1:/var/www/html/example.zip
docker compose run --rm wpcli wp plugin install /var/www/html/example.zip --force
docker compose run --rm wpcli wp --quiet eval 'unlink(ABSPATH."example.zip");'
```

`wp core check-update`, `wp core update` (without a local zip), and `wp plugin update --all` all require outbound access to wordpress.org — none work in the internal network container. The local-zip pattern above is the workaround for core and individual plugins.

### Update the MCP adapter

Same process as initial install (step 11). Always back up first.

### Update Docker base images

```bash
docker compose pull && docker compose up -d
```

### Health check

```bash
docker compose ps
docker compose run --rm wpcli wp core version
docker compose run --rm wpcli wp plugin list
# Check MCP connection in your AI client
```

---

## Backups

`backup.sh` produces three archives per run: DB dump, `wp-content` tar, and `mu-plugins` tar. Prunes files older than 7 days.

```bash
./backup.sh
```

### Restore

See comments in `backup.sh` or the original setup notes.

---

## TLS certificate renewal

Certificates expire after 365 days. Re-run step 2, then restart Caddy and re-trust the new cert in your OS.

---

## mu-plugins reference

WordPress loads files in `wp-content/mu-plugins/` automatically.

| File | What it does |
|---|---|
| `allow-uploads.php` | Extends the MIME type allowlist (md, yaml, json, pdf). |
| `post-abilities.php` | Registers the MCP abilities (`posts/*`, `media/*`). |
| `markdown-comments.php` | Renders MCP comments as markdown at display time. |

---

## REST API (for humans / debugging)

Base path: `https://YOUR_DOMAIN/wp-json/wp/v2/`

Use Basic Auth with an application password. Any HTTP client works — Yaak, Insomnia, Bruno, Postman, curl, etc.

```bash
curl -s -u "YOUR_USERNAME:APPLICATION_PASSWORD" \
  https://YOUR_DOMAIN/wp-json/wp/v2/posts
```

Main endpoints: `/posts`, `/comments`, `/categories`, `/tags`, `/media`, `/users/me`.

---

## Agent Integration

See `AGENTS.md` for the data model, workflow, and MCP ability reference. The MCP server exposes a standard set of abilities that any MCP-compatible client can discover and call.

---

## Philosophy

Nexus is intentionally minimal. It provides a shared, structured space for you and your agents to collaborate without trying to be a full agent framework. The focus is on reliable context, clean natural-language communication through comments, and multiple access patterns to the same data.

## Contributing

Contributions are welcome. Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to contribute.

## License

This project is licensed under the [MIT License](LICENSE).
