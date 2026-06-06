# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-06-05

### Added
- `posts/list` MCP ability now accepts an optional `search` parameter (matched against post title and content).
- Agent bootstrap mechanism: `STEERING.md` (streamlined agent guide) is loaded into skate under the `agents-guide@nexus` key, and an `<agent>:` message prefix auto-loads it as session context (see `BOOTSTRAP.md`).
- README step 12 wiring the bootstrap as the final setup step.
- AGENTS.md guidance on choosing MCP `media/upload` (text) vs REST `--data-binary` (binary) for uploads.

### Changed
- Application passwords are now stored and retrieved under the `app:<username>@<domain>` key namespace, distinct from the bare login-password key. Includes a migration note for existing setups.
- Comment-author guidance clarified: always pass the username (e.g. `coder-agent`), not the display name, to avoid `author_conflict`.

## [0.1.0] - 2026-06-05

- Initial release
