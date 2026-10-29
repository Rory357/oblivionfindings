# Hardware walks

This file records every walk of a Security & Devices driver on real hardware.

A driver may say "Tested on real hardware" (`DriverSupportLevel::TestedOnHardware`) only if it has a row
here. The test `tests/Unit/SecurityDevices/DriverHonestyGuardTest.php` fails the build when a driver
claims that level without a row, or when a row names a driver that doesn't claim it.

A walk counts only when Stephan, or MASTER on the live .com, has done it with real kit and the
expected result was seen. Tests against pretend devices don't count. See `GOAL.md`, section 2.

## How to add a walk

Add one row per driver. Put the driver key in backticks, then the model and firmware walked, the
date, the phase walk step it passed, and who walked it. In the same commit, change the driver's
`supportLevel` to `TestedOnHardware` and set `hardwareEvidence` to a short plain sentence.

## Walks

| Driver key | Model and firmware walked | Date | Walk step passed | Walked by |
| --- | --- | --- | --- | --- |

No driver has been walked on real hardware yet (30 Oct 2026). Gemini's earlier claims for UniFi
Network and Queclink were removed in Phase A because no walk was recorded.
