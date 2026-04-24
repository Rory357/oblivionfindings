#!/usr/bin/env bash
# Phase 5 long-tail — final residual pass.
#
# Catches edge cases the main sweep missed:
#   - dark:bg-slate/gray-700/800/900 → dark:bg-muted (depth layers)
#   - bg-slate-500/600/700/800/900 → bg-muted-foreground/80 or bg-muted
#   - text-slate-200/100 → text-foreground
#   - from-/to-/via- with shade 500+ (brand gradients)

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
        # --- Residual neutrals ---
        s/\bbg-(slate|gray|zinc|neutral|stone)-(500|600)\b(\/\d+)?/bg-muted-foreground\/80/g;
        s/\bbg-(slate|gray|zinc|neutral|stone)-(700|800|900|950)\b(\/\d+)?/bg-muted/g;
        s/\bdark:bg-(slate|gray|zinc|neutral|stone)-(600|700|800|900|950)\b(\/\d+)?/dark:bg-muted/g;
        s/\btext-(slate|gray|zinc|neutral|stone)-(100|200|300)\b/text-foreground/g;
        s/\btext-(slate|gray|zinc|neutral|stone)-400\b/text-muted-foreground/g;
        s/\bdark:text-(slate|gray|zinc|neutral|stone)-(100|200|300)\b/dark:text-foreground/g;

        # --- Residual gradient shades 300-900 ---
        s/\bfrom-(violet|indigo|purple|fuchsia)-(300|400|500|600|700|800|900)\b/from-primary/g;
        s/\bto-(violet|indigo|purple|fuchsia)-(300|400|500|600|700|800|900)\b/to-primary/g;
        s/\bvia-(violet|indigo|purple|fuchsia)-(300|400|500|600|700|800|900)\b/via-primary/g;

        s/\bfrom-(red|rose|pink)-(300|400|500|600|700|800|900)\b/from-status-critical/g;
        s/\bto-(red|rose|pink)-(300|400|500|600|700|800|900)\b/to-status-critical/g;
        s/\bvia-(red|rose|pink)-(300|400|500|600|700|800|900)\b/via-status-critical/g;

        s/\bfrom-(amber|yellow|orange)-(300|400|500|600|700|800|900)\b/from-status-warning/g;
        s/\bto-(amber|yellow|orange)-(300|400|500|600|700|800|900)\b/to-status-warning/g;
        s/\bvia-(amber|yellow|orange)-(300|400|500|600|700|800|900)\b/via-status-warning/g;

        s/\bfrom-(emerald|green|lime)-(300|400|500|600|700|800|900)\b/from-status-success/g;
        s/\bto-(emerald|green|lime)-(300|400|500|600|700|800|900)\b/to-status-success/g;
        s/\bvia-(emerald|green|lime)-(300|400|500|600|700|800|900)\b/via-status-success/g;

        s/\bfrom-(blue|sky|cyan|teal)-(300|400|500|600|700|800|900)\b/from-status-info/g;
        s/\bto-(blue|sky|cyan|teal)-(300|400|500|600|700|800|900)\b/to-status-info/g;
        s/\bvia-(blue|sky|cyan|teal)-(300|400|500|600|700|800|900)\b/via-status-info/g;

        s/\bfrom-(slate|gray|zinc|neutral|stone)-\d+\b/from-muted/g;
        s/\bto-(slate|gray|zinc|neutral|stone)-\d+\b/to-muted/g;
        s/\bvia-(slate|gray|zinc|neutral|stone)-\d+\b/via-muted/g;
    ' "$f"
done

echo "Done."
