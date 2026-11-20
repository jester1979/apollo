---
name: session-objects-hold-no-closures
description: Objects inside the Simulation are serialized into the PHP session, so they must not hold closures
metadata:
  type: project
---

The `Simulation` object is stored in `$_SESSION['simulation']`, so PHP serializes it and everything it holds (`Agc`, `Dsky`, chapters, guidance) after every request. A `Closure` property makes that serialization fail. Reported by the builder of slice 3 of `apollo-agc-php` on 2026-11-20.

**Why:** A callback stored on a class in this graph breaks every tick at the end of the request, outside the unit tests that never serialize.

**How to apply:** Programs push data into the DSKY (`Dsky::supplyData`) and other objects; no object in the simulation graph stores a closure or other unserializable value.
