# Test baseline: apollo-agc-php

Taken before slice 1.

- Test command: none. The working directory has no `composer.json`, `phpunit.xml.dist` or `tests/` folder yet. Slice 1 adds PHPUnit and PHPStan.
- Counts: 0 tests, 0 failures.
- Already-failing tests: none.
- Consequence for the delivery gate: every failing test after the build is new, so the gate reports no regressions against this baseline.
