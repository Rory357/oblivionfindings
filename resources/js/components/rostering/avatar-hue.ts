/**
 * Inline style for a staff/resident avatar-initials chip, tinted by a stable hue.
 *
 * The foreground lightness (28%) is deliberately darker than the chip's original
 * 35% so the bold initials clear WCAG AA (4.5:1) against the 90%-lightness chip
 * background for every hue. The previous `hsl(H 50% 35%)` failed axe color-contrast
 * at ~3.83:1 for green hues (e.g. #2d8661 on #d7f4e8); the worst-case hue (~yellow)
 * now sits at ~4.97:1. Centralised so the fix applies to every rostering avatar chip.
 */
export function avatarHueStyle(hue: number): {
    background: string;
    color: string;
} {
    return {
        background: `hsl(${hue} 55% 90%)`,
        color: `hsl(${hue} 50% 28%)`,
    };
}
