#!/usr/bin/env bash
# Clean up artifacts introduced by replace-status-colours.sh:
#   - doubled opacity suffixes (status-X/30/30 → status-X/30)
#   - accidental drop of /10 or /20 opacity when the previous regex matched
#     the shade number rather than the full class

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

mapfile -t FILES < <(find resources/js -type f \( -name "*.tsx" -o -name "*.ts" \))

echo "Cleaning ${#FILES[@]} files..."

for f in "${FILES[@]}"; do
    perl -i -pe '
        # Collapse doubled opacity suffixes
        s/\b(bg|text|border|ring)-status-(success|warning|critical|info)\/(\d+)\/(\d+)\b/$1-status-$2\/$4/g;

        # If a badge ended up "bg-status-X text-status-X border-status-X/30"
        # (solid bg is too strong for a pill), demote the bg to the -bg token.
        # This only applies when the bg is paired with matching text of the
        # same tone — a common badge pattern.
        s/\bbg-status-(success|warning|critical|info)\b(\s+[^"\x27]*text-status-\1\b)/bg-status-$1-bg$2/g;
    ' "$f"
done

echo "Done."
