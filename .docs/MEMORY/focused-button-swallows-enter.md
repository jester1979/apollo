---
name: focused-button-swallows-enter
description: A page button that keeps focus after a mouse click takes the keyboard Enter, so ENTR never reaches the DSKY
metadata:
  type: project
---

The keyboard handler in `public/assets/dsky.js` skips Enter when a button has focus, so the browser can click that button. A button that keeps focus after a mouse click therefore swallows the next Enter, and `V16N68 Enter` does nothing. One `click` listener on `document` in `dsky.js` blurs every button after a pointer click (`event.detail > 0`); keyboard activation keeps its focus. Found in slice 4 of `apollo-agc-php` on 2026-11-20 for the DSKY keys, and again at the delivery gate for the speed buttons added in slice 13. The document-level listener dates from the fix after the gate.

**Why:** The second occurrence came from a new button that had no blur of its own. The unit tests do not cover focus, so only a browser run shows it.

**How to apply:** A new button needs nothing extra while that listener exists. A clickable control that is not a `<button>`, or a handler that calls `stopPropagation()`, bypasses it. In a browser check, click the new control first and then type a DSKY command that ends with Enter. See [[chrome-devtools-profile-lock]] for the other browser-check constraints.
