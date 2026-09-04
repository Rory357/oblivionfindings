# OblivionFindings — project instructions

## Design consistency (mandatory)

Before writing or changing **any** UI code (pages, components, styles,
charts, dialogs), read [DESIGN.md](DESIGN.md) and follow it. In particular:

- Semantic tokens only — never raw Tailwind colour classes or inline hex.
- Reuse the primitives in `resources/js/components/ui/` and the shared
  components in `resources/js/components/` before building anything new.
- Use the typography helpers (`.text-page-title` etc.), the correct layout
  shell, and `<StatusBadge>` for all status/severity display.
- Check DESIGN.md's "Named anti-patterns" list before finishing a UI change.

When the user corrects a design decision, add it to DESIGN.md's anti-pattern
list (and an ESLint guardrail if mechanically checkable) as part of the fix.
