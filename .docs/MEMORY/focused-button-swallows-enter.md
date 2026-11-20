---
name: focused-button-swallows-enter
description: A page button that keeps focus after a mouse click takes the keyboard Enter, so ENTR never reaches the DSKY
metadata:
  type: project
---

The keyboard handler in `public/assets/dsky.js` skips Enter when a button has focus, so the browser can click that button. A button that keeps focus after a mouse click therefore swallows the next Enter, and `V16N68 Enter` does nothing. The DSKY keys blur themselves after a pointer click; other buttons on the page do not do this by themselves. Found in slice 4 of `apollo-agc-php` on 2026-11-20, and again at the delivery gate for the speed buttons added in slice 13 (`public/assets/dsky.js:165`).

**Why:** The second occurrence came from a new button added without the blur. The unit tests do not cover focus, so only a browser run shows it.

**How to apply:** Every new clickable control on the page (speed, start, next chapter) blurs itself after a pointer click, or the Enter rule in `dsky.js` is narrowed to the DSKY keys. In a browser check, click the new control first and then type a DSKY command that ends with Enter. See [[chrome-devtools-profile-lock]] for the other browser-check constraints.
