---
name: new-noun-needs-values-in-every-chapter
description: A noun added to NounTable becomes a valid user display in every chapter of that computer, and each chapter must supply values for it
metadata:
  type: project
---

A noun added to `src/Dsky/NounTable.php` for a computer (CM or LM) can be keyed in by the user in every chapter of that computer, for example `V06N40` during the launch. Each chapter's `nounValues()` must return values for it; a missing case throws a `LogicException`, which the API returns as a 500. Reported by the builder of slice 9 of `apollo-agc-php` on 2026-11-20.

**Why:** The slice that adds a noun only tests it in its own chapter, so the 500 shows up in another chapter.

**How to apply:** When adding a noun, add a case (zeros when the chapter has no data for it) to `nounValues()` of every chapter of that computer, and add a test that keys the noun in one other chapter. See [[session-objects-hold-no-closures]] for the other constraint shared by all chapters.
