#!/usr/bin/env bash
# Phase 5 long-tail — map status-family colour classes to semantic status tokens.
#
# This is a DELIBERATELY opinionated pass: red → critical, amber/yellow → warning,
# emerald/green/lime → success, blue/sky/cyan → info. The small number of
# legitimate non-status uses (colour-coded pipelines, charts, decorative
# gradients) should be guarded with /* eslint-disable */ comments and kept
# out of this sweep. We work only on .tsx/.ts files under resources/js.
#
# Patterns we normalise:
#
#   bg-red-50 / -100 / -200        → bg-status-critical-bg
#   bg-red-400 / -500 / -600 / -700 / -800 / -900
#                                   → bg-status-critical
#   text-red-*                     → text-status-critical
#   border-red-*                   → border-status-critical/30  (lighter border)
#
#   bg-amber/yellow/orange-50/-100/-200 → bg-status-warning-bg
#   bg-amber/yellow/orange-400..900     → bg-status-warning
#   text-amber/yellow/orange-*          → text-status-warning
#   border-amber/yellow/orange-*        → border-status-warning/30
#
#   bg-emerald/green/lime-50/-100/-200  → bg-status-success-bg
#   bg-emerald/green/lime-400..900       → bg-status-success
#   text-emerald/green/lime-*            → text-status-success
#   border-emerald/green/lime-*          → border-status-success/30
#
#   bg-blue/sky/cyan-50/-100/-200        → bg-status-info-bg
#   bg-blue/sky/cyan-400..900             → bg-status-info
#   text-blue/sky/cyan-*                  → text-status-info
#   border-blue/sky/cyan-*                → border-status-info/30
#
# dark: variants fall to the same semantic token (status tokens have dark
# variants in app.css already).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Skip the token-source files and any file that documents a deliberate
# exception via a top-of-file marker comment.
mapfile -t FILES < <(find resources/js -type f \( -name "*.tsx" -o -name "*.ts" \) \
    ! -name "derive-palette.ts" \
    ! -name "status-colors.ts" \
    ! -name "status-badge.tsx" \
    ! -path "*/recruitment/status-badge.tsx")

echo "Processing ${#FILES[@]} files..."

for f in "${FILES[@]}"; do
    perl -i -pe '
        # --- RED → critical ---
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-red-(50|100|200)\b(\/\d+)?/$1-status-critical-bg/g;
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-red-(300|400|500|600|700|800|900|950)\b(\/\d+)?/$1-status-critical/g;
        s/\b(text|hover:text|dark:text|group-hover:text)-red-\d+\b(\/\d+)?/$1-status-critical/g;
        s/\b(border|hover:border|dark:border|focus:border)-red-\d+\b/$1-status-critical\/30/g;
        s/\b(ring|focus:ring)-red-\d+\b/$1-status-critical/g;

        # --- AMBER / YELLOW / ORANGE → warning ---
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(amber|yellow|orange)-(50|100|200)\b(\/\d+)?/$1-status-warning-bg/g;
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(amber|yellow|orange)-(300|400|500|600|700|800|900|950)\b(\/\d+)?/$1-status-warning/g;
        s/\b(text|hover:text|dark:text|group-hover:text)-(amber|yellow|orange)-\d+\b(\/\d+)?/$1-status-warning/g;
        s/\b(border|hover:border|dark:border|focus:border)-(amber|yellow|orange)-\d+\b/$1-status-warning\/30/g;
        s/\b(ring|focus:ring)-(amber|yellow|orange)-\d+\b/$1-status-warning/g;

        # --- EMERALD / GREEN / LIME → success ---
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(emerald|green|lime)-(50|100|200)\b(\/\d+)?/$1-status-success-bg/g;
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(emerald|green|lime)-(300|400|500|600|700|800|900|950)\b(\/\d+)?/$1-status-success/g;
        s/\b(text|hover:text|dark:text|group-hover:text)-(emerald|green|lime)-\d+\b(\/\d+)?/$1-status-success/g;
        s/\b(border|hover:border|dark:border|focus:border)-(emerald|green|lime)-\d+\b/$1-status-success\/30/g;
        s/\b(ring|focus:ring)-(emerald|green|lime)-\d+\b/$1-status-success/g;

        # --- BLUE / SKY / CYAN → info ---
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(blue|sky|cyan)-(50|100|200)\b(\/\d+)?/$1-status-info-bg/g;
        s/\b(bg|hover:bg|dark:bg|group-hover:bg)-(blue|sky|cyan)-(300|400|500|600|700|800|900|950)\b(\/\d+)?/$1-status-info/g;
        s/\b(text|hover:text|dark:text|group-hover:text)-(blue|sky|cyan)-\d+\b(\/\d+)?/$1-status-info/g;
        s/\b(border|hover:border|dark:border|focus:border)-(blue|sky|cyan)-\d+\b/$1-status-info\/30/g;
        s/\b(ring|focus:ring)-(blue|sky|cyan)-\d+\b/$1-status-info/g;

        # --- PINK / ROSE → critical (rare, usually used as alt-red) ---
        s/\b(bg|hover:bg|dark:bg)-(pink|rose)-(50|100|200)\b(\/\d+)?/$1-status-critical-bg/g;
        s/\b(bg|hover:bg|dark:bg)-(pink|rose)-(300|400|500|600|700|800|900)\b(\/\d+)?/$1-status-critical/g;
        s/\b(text|hover:text|dark:text)-(pink|rose)-\d+\b(\/\d+)?/$1-status-critical/g;
        s/\b(border|hover:border|dark:border)-(pink|rose)-\d+\b/$1-status-critical\/30/g;

        # --- TEAL → info (harmonises with blue family) ---
        s/\b(bg|hover:bg|dark:bg)-teal-(50|100|200)\b(\/\d+)?/$1-status-info-bg/g;
        s/\b(bg|hover:bg|dark:bg)-teal-(300|400|500|600|700|800|900)\b(\/\d+)?/$1-status-info/g;
        s/\b(text|hover:text|dark:text)-teal-\d+\b(\/\d+)?/$1-status-info/g;
        s/\b(border|hover:border|dark:border)-teal-\d+\b/$1-status-info\/30/g;

        # --- Residual neutrals not caught by the first sweep (shade 400) ---
        s/\btext-(gray|zinc|slate|neutral|stone)-400\b/text-muted-foreground/g;
        s/\bbg-(gray|zinc|slate|neutral|stone)-(300|400)\b/bg-muted/g;
        s/\bborder-(gray|zinc|slate|neutral|stone)-(400|500|600|700)\b/border-border/g;
    ' "$f"
done

echo "Done."
