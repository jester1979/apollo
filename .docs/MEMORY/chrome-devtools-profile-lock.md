---
name: chrome-devtools-profile-lock
description: chrome-devtools MCP fails with "browser is already running" when another Chrome holds its profile
metadata:
  type: project
---

The chrome-devtools MCP uses one profile at `~/.cache/chrome-devtools-mcp/chrome-profile`. When another Chrome instance (for example from a second Claude session) holds that profile, `list_pages` and `new_page` fail with "The browser is already running". Seen on 2026-11-20 during slice 1 of `apollo-agc-php`.

**Why:** Browser verification of a slice cannot run until that Chrome is closed, and a subagent must not kill a process it did not start.

**How to apply:** On this error, ask the user to close the MCP's Chrome window (or start the MCP with `--isolated`), then re-run only the browser check.
