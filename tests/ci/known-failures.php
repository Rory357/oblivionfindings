<?php

/*
 * Tests that fail on main today, reviewed and owned. CI is green when every
 * failing test is on this list and red on any other failure (see
 * scripts/ci/test-verdict.php and docs/testing/github-round-gate.md).
 *
 * Each entry:
 *   test   "<path from the repo root>::<test name>" exactly as JUnit reports it
 *   owner  who is fixing it (a session or person)
 *   reason one line on why it fails
 *   since  date it was first listed (YYYY-MM-DD)
 *
 * Remove an entry in the same change that fixes the test. The verdict lists
 * known failures that now pass, so stale entries are easy to spot. Adding an
 * entry needs MASTER's OK: it is how a red test becomes "known".
 */

return [
];
