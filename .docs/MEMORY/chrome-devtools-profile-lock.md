---
name: chrome-devtools-profile-lock
description: Browser checks with the chrome-devtools MCP: the shared profile lock, one tab per session, screenshots without filePath
metadata:
  type: project
---

The chrome-devtools MCP uses one profile at `~/.cache/chrome-devtools-mcp/chrome-profile`. When another Chrome instance (for example from a second Claude session) holds that profile, `list_pages` and `new_page` fail with "The browser is already running". This blocked the browser check of slices 1, 2 and 12 of `apollo-agc-php` on 2026-11-20.

Two more constraints showed up in the same build:

- The simulation lives in the PHP session, so two tabs on `http://127.0.0.1:8000/` share one simulation and both tick it. A stale tab doubled the clock (1.68 simulated seconds per wall second at 1×).
- `take_screenshot` with a `filePath` outside the workspace roots is refused.

**Why:** Browser verification of a slice cannot run until that Chrome is closed, and a subagent must not kill a process it did not start. A second tab makes every time and speed measurement wrong.

**How to apply:** On the "already running" error, ask the user to close the MCP's Chrome window (or start the MCP with `--isolated`), then re-run only the browser check. Use one tab per check and blank or close older tabs first. Call `take_screenshot` without `filePath`. See [[focused-button-swallows-enter]] for what to check after clicking a page control.
