---
name: chrome-devtools-profile-lock
description: Browser checks with the chrome-devtools MCP: the shared profile lock and screenshots without filePath
metadata:
  type: project
---

The chrome-devtools MCP uses one profile at `~/.cache/chrome-devtools-mcp/chrome-profile`. When another Chrome instance (for example from a second Claude session) holds that profile, `list_pages` and `new_page` fail with "The browser is already running". This blocked the browser check of slices 1, 2 and 12 of `apollo-agc-php` on 2026-11-20.

`take_screenshot` with a `filePath` outside the workspace roots is refused.

Two tabs no longer share a simulation: since the fixes after the gate each page load sends its own `tab` id and `SessionStore` keeps one simulation per tab (at most 4 per session).

**Why:** Browser verification of a slice cannot run until that Chrome is closed, and a subagent must not kill a process it did not start.

**How to apply:** On the "already running" error, ask the user to close the MCP's Chrome window (or start the MCP with `--isolated`), then re-run only the browser check. Call `take_screenshot` without `filePath`. See [[focused-button-swallows-enter]] for what to check after clicking a page control.
