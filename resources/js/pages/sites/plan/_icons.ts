import {
    BellElectric,
    BellRing,
    BookMarked,
    Circle,
    CircleDashed,
    Cpu,
    Cross,
    Crosshair,
    DoorClosed,
    DoorOpen,
    Droplet,
    Droplets,
    Flame,
    FlameKindling,
    HeartPulse,
    MapPin,
    PanelTop,
    Pencil,
    Pill,
    Pin,
    Pipette,
    Route,
    Shield,
    Square,
    Type,
    Video,
    Zap,
    type LucideIcon,
} from 'lucide-react';

/**
 * Every icon the site-plan feature resolves BY NAME, keyed exactly as the
 * names appear in config/site_plan_taxonomy.php ('icon' entries) and in
 * _thumbnail.tsx's FALLBACK_PIN_STYLES.
 *
 * This replaces the `import * as Lucide from 'lucide-react'` barrels that
 * defeated tree-shaking and shipped the entire icon library (~427 KB) with
 * the heaviest pages in the app. An unknown name falls back at each call
 * site (MapPin / Circle) exactly as before — when adding a new icon name to
 * the taxonomy config, add its import here too.
 */
export const PLAN_ICONS: Record<string, LucideIcon> = {
    BellElectric,
    BellRing,
    BookMarked,
    Circle,
    CircleDashed,
    Cpu,
    Cross,
    Crosshair,
    DoorClosed,
    DoorOpen,
    Droplet,
    Droplets,
    Flame,
    FlameKindling,
    HeartPulse,
    MapPin,
    PanelTop,
    Pencil,
    Pill,
    Pin,
    Pipette,
    Route,
    Shield,
    Square,
    Type,
    Video,
    Zap,
};
