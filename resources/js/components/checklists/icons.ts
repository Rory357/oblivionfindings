// Registry mapping the category icon names stored in config/checklists.php to
// concrete lucide-react components (explicit so the bundle stays tree-shaken).
import {
    Car,
    ClipboardList,
    Gavel,
    HeartHandshake,
    House,
    type LucideIcon,
    PackageOpen,
    Pill,
    ShieldAlert,
    SprayCan,
    UtensilsCrossed,
} from 'lucide-react';

export const CATEGORY_ICONS: Record<string, LucideIcon> = {
    ShieldAlert,
    Pill,
    SprayCan,
    UtensilsCrossed,
    HeartHandshake,
    House,
    Car,
    Gavel,
    PackageOpen,
    ClipboardList,
};

export function categoryIcon(name?: string | null): LucideIcon {
    return (name && CATEGORY_ICONS[name]) || ClipboardList;
}
