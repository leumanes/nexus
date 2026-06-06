# Bootstrap

## Requirements
This bootstrap mechanism requires the `skate` credential store. If you chose a different store during setup, the `skate get` command below will not exist.

## Harness instruction

Add the following to your AI client's global memory or instructions file. Adapt the file path to your client:

| Client | Global memory file |
|---|----|
| Claude Code | `~/.claude/CLAUDE.md` |
| GitHub Copilot CLI | `~/.copilot/settings.json` (check client docs for instruction support) |

### Instruction text

```
## Agent bootstrap

If the user's first message begins with "coder-agent:", "reviewer-agent:", "qa-agent:", or "scrum-agent:":

1. Run `skate get "agents-guide@nexus"` via Bash.
2. Load the output as your operational context for this session — it contains the Nexus CMS workflow, MCP ability reference, and constraints.
3. Acknowledge the agent identity (e.g. "Ready as coder-agent.") and confirm you loaded the guide, then respond to whatever follows the colon.

The full guide at AGENTS.md is reference only — do not read it unless the user explicitly asks or you hit something not covered by the loaded guide.
```

> **Note:** `STEERING.md` is loaded into skate under the key `agents-guide@nexus` as the final setup step (see README).
