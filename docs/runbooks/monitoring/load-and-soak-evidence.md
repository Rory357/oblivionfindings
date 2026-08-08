# Monitoring load and soak evidence

## Local synthetic fixture boundary

`tests/Performance/Monitoring/MonitoringLoadTest.php` is a bounded local regression fixture. Its `full_scale` profile describes the generated dataset size; it does not mean a deployed runtime, live dependency, sustained load, or soak observation was exercised.

Setting `MONITORING_WRITE_EVIDENCE=1` writes an immutable, collision-safe JSON artifact with a unique `artifact_id`. Every such artifact is classified as `local_synthetic_fixture`, `test_process_only`, and `v09_release_evidence: false`. These artifacts are prerequisite regression evidence only and cannot close V09.

## Required deployed evidence

V09 load or soak evidence must come from an isolated deployed runtime and independently record the unique run identity, start and end time, sustained duration, load profile and achieved throughput, supervised workers and listeners, safe dependency identities, generator exit status, latency/error/queue observations during the run, and recovery observations after load stops. Preserve each artifact under a unique immutable identity; never overwrite an earlier run.
