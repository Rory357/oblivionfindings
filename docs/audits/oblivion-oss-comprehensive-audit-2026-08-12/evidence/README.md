# Evidence index

This directory contains redacted screenshots, safe command logs, structured
read-only source-inspection and reconciliation logs, side-by-side visual
comparisons, benchmark metadata, and small derived diagrams only. JSON files in
`source/`, `browser/`, and `benchmarks/` are evidence sidecars or structured safe
logs; they are not executable application or audit-generator code.

Reproducibility helpers live in `../generators/`, outside the evidence boundary.
This directory must never contain executables, secrets, or real health, client,
HR, finance, camera, location, or security data.
