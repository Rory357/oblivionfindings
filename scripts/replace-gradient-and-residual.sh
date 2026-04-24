#!/usr/bin/env bash
# Phase 5 long-tail — gradient classes + residual slate/gray references.
#
# Gradients don't compose well with CSS-variable semantic tokens
# (arbitrary `from-[oklch(...)]` is verbose and hard to keep consistent).
# The pragmatic fix is to drop the gradient for a single-colour token
# background that re-tints with --primary.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

mapfile -t FILES < <(find resources/js -type f \( -name "*.tsx" -o -name "*.ts" \) \
    ! -name "derive-palette.ts" \
    ! -name "status-colors.ts" \
    ! -name "status-badge.tsx" \
    ! -path "*/recruitment/*")

echo "Processing ${#FILES[@]} files..."

for f in "${FILES[@]}"; do
    perl -i -pe '
        # Gradient → solid semantic tints
        # Brand-family gradients
        s/\bbg-gradient-to-\w+\s+from-(violet|indigo|purple|fuchsia)-(50|100|200)\s+(via-\S+\s+)?to-(violet|indigo|purple|fuchsia)-(50|100|200)\b/bg-primary\/10/g;
        s/\bbg-gradient-to-\w+\s+from-(violet|indigo|purple|fuchsia)-(300|400|500|600)\s+(via-\S+\s+)?to-(violet|indigo|purple|fuchsia)-(300|400|500|600)\b/bg-primary/g;
        # Status-family gradients
        s/\bbg-gradient-to-\w+\s+from-(red|rose|pink)-(50|100|200)\s+(via-\S+\s+)?to-(red|rose|pink)-(50|100|200)\b/bg-status-critical-bg/g;
        s/\bbg-gradient-to-\w+\s+from-(amber|yellow|orange)-(50|100|200)\s+(via-\S+\s+)?to-(amber|yellow|orange)-(50|100|200)\b/bg-status-warning-bg/g;
        s/\bbg-gradient-to-\w+\s+from-(emerald|green|lime)-(50|100|200)\s+(via-\S+\s+)?to-(emerald|green|lime)-(50|100|200)\b/bg-status-success-bg/g;
        s/\bbg-gradient-to-\w+\s+from-(blue|sky|cyan|teal)-(50|100|200)\s+(via-\S+\s+)?to-(blue|sky|cyan|teal)-(50|100|200)\b/bg-status-info-bg/g;
        # Mixed two-colour gradients (e.g. violet-50 → pink-50): default to primary/10
        s/\bbg-gradient-to-\w+\s+from-\w+-(50|100|200)\s+(via-\S+\s+)?to-\w+-(50|100|200)\b/bg-primary\/10/g;

        # Stand-alone from-/to-/via- classes (in case the bg-gradient-to-* was elsewhere)
        s/\bfrom-(violet|indigo|purple|fuchsia)-(50|100|200)\b/from-primary\/10/g;
        s/\bto-(violet|indigo|purple|fuchsia)-(50|100|200)\b/to-primary\/10/g;
        s/\bvia-(violet|indigo|purple|fuchsia)-(50|100|200)\b/via-primary\/10/g;
        s/\bfrom-(violet|indigo|purple|fuchsia)-(500|600|700)\b/from-primary/g;
        s/\bto-(violet|indigo|purple|fuchsia)-(500|600|700)\b/to-primary/g;
        s/\bfrom-(red|rose|pink)-(50|100|200)\b/from-status-critical-bg/g;
        s/\bto-(red|rose|pink)-(50|100|200)\b/to-status-critical-bg/g;
        s/\bfrom-(amber|yellow|orange)-(50|100|200)\b/from-status-warning-bg/g;
        s/\bto-(amber|yellow|orange)-(50|100|200)\b/to-status-warning-bg/g;
        s/\bfrom-(emerald|green|lime)-(50|100|200)\b/from-status-success-bg/g;
        s/\bto-(emerald|green|lime)-(50|100|200)\b/to-status-success-bg/g;
        s/\bfrom-(blue|sky|cyan|teal)-(50|100|200)\b/from-status-info-bg/g;
        s/\bto-(blue|sky|cyan|teal)-(50|100|200)\b/to-status-info-bg/g;

        # Residual slate/gray 500 bg + text-slate-300
        s/\bbg-slate-500\b/bg-muted-foreground\/80/g;
        s/\btext-slate-300\b/text-muted-foreground/g;
        s/\bbg-gray-500\b/bg-muted-foreground\/80/g;
        s/\btext-gray-300\b/text-muted-foreground/g;
    ' "$f"
done

echo "Done."
