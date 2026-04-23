#!/usr/bin/env bash
# Mechanical, context-free colour-class replacements across resources/js.
# Only does the replacements where the mapping is unambiguous:
#   - violet/indigo/purple/fuchsia (brand family) → primary tokens
#   - gray/zinc/slate/neutral/stone neutrals (specific shades) → foreground/muted/border tokens
#
# Status colours (red/amber/emerald/blue) are intentionally NOT mass-replaced
# — their semantics depend on context (destructive vs critical-status vs info).
# Those get converted via status-colors.ts (centralised) and StatusBadge adoption.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Work only on component + page source files
mapfile -t FILES < <(find resources/js -type f \( -name "*.tsx" -o -name "*.ts" \) ! -name "derive-palette.ts" ! -name "status-colors.ts" ! -name "status-badge.tsx")

echo "Processing ${#FILES[@]} files..."

for f in "${FILES[@]}"; do
    # Use perl for portable in-place edits with lookbehind support
    perl -i -pe '
        # -------- Brand-family → primary tokens --------
        # Backgrounds
        s/\bbg-(violet|indigo|purple|fuchsia)-(50|100)\b/bg-primary\/10/g;
        s/\bbg-(violet|indigo|purple|fuchsia)-(200|300)\b/bg-primary\/20/g;
        s/\bbg-(violet|indigo|purple|fuchsia)-(400)\b/bg-primary\/70/g;
        s/\bbg-(violet|indigo|purple|fuchsia)-(500|600)\b/bg-primary/g;
        s/\bbg-(violet|indigo|purple|fuchsia)-(700|800|900|950)\b/bg-primary/g;
        s/\bhover:bg-(violet|indigo|purple|fuchsia)-(500|600)\b/hover:bg-primary\/90/g;
        s/\bhover:bg-(violet|indigo|purple|fuchsia)-(700|800|900)\b/hover:bg-primary/g;
        s/\bhover:bg-(violet|indigo|purple|fuchsia)-(50|100)\b/hover:bg-primary\/10/g;
        # dark: variants of brand → same tokens
        s/\bdark:bg-(violet|indigo|purple|fuchsia)-\d+(\/\d+)?\b/dark:bg-primary\/20/g;

        # Text
        s/\btext-(violet|indigo|purple|fuchsia)-(400|500|600|700|800|900)\b/text-primary/g;
        s/\btext-(violet|indigo|purple|fuchsia)-(50|100|200|300)\b/text-primary\/70/g;
        s/\bhover:text-(violet|indigo|purple|fuchsia)-\d+\b/hover:text-primary/g;
        s/\bdark:text-(violet|indigo|purple|fuchsia)-\d+(\/\d+)?\b/dark:text-primary/g;

        # Borders
        s/\bborder-(violet|indigo|purple|fuchsia)-(200|300|400|500|600|700)\b/border-primary/g;
        s/\bborder-(violet|indigo|purple|fuchsia)-(50|100|800|900)\b/border-primary\/30/g;
        s/\bhover:border-(violet|indigo|purple|fuchsia)-\d+\b/hover:border-primary\/50/g;

        # Rings
        s/\bring-(violet|indigo|purple|fuchsia)-\d+\b/ring-ring/g;
        s/\bfocus:ring-(violet|indigo|purple|fuchsia)-\d+\b/focus:ring-ring/g;

        # -------- Neutrals → semantic tokens --------
        # Muted-foreground (secondary text)
        s/\btext-(gray|zinc|slate|neutral|stone)-(400|500|600)\b/text-muted-foreground/g;
        s/\bdark:text-(gray|zinc|slate|neutral|stone)-(300|400|500)\b/dark:text-muted-foreground/g;

        # Foreground (primary text)
        s/\btext-(gray|zinc|slate|neutral|stone)-(700|800|900|950)\b/text-foreground/g;
        s/\bdark:text-(gray|zinc|slate|neutral|stone)-(50|100|200)\b/dark:text-foreground/g;

        # Muted backgrounds
        s/\bbg-(gray|zinc|slate|neutral|stone)-(50|100)\b/bg-muted/g;
        s/\bbg-(gray|zinc|slate|neutral|stone)-(200)\b/bg-muted/g;
        s/\bhover:bg-(gray|zinc|slate|neutral|stone)-(50|100|200)\b/hover:bg-muted/g;
        s/\bdark:bg-(gray|zinc|slate|neutral|stone)-(800|900|950)\b/dark:bg-muted/g;

        # Borders
        s/\bborder-(gray|zinc|slate|neutral|stone)-(100|200|300)\b/border-border/g;
        s/\bdark:border-(gray|zinc|slate|neutral|stone)-(700|800|900)\b/dark:border-border/g;
    ' "$f"
done

echo "Done."
