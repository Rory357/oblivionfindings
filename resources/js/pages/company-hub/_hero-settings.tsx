import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HubHeroSettings } from './_types';

export const defaultHero: HubHeroSettings = {
    style: 'brand',
    height: 'compact',
    focal_x: 50,
    focal_y: 50,
    overlay: 65,
    action_label: '',
    action_url: '',
    alignment: 'left',
    icon: 'heart-handshake',
    show_search: true,
    show_summary: true,
    show_metrics: false,
};

export function HeroSettings({
    value,
    onChange,
    isHome = false,
}: {
    value?: HubHeroSettings;
    onChange: (hero: HubHeroSettings) => void;
    isHome?: boolean;
}) {
    const hero = { ...defaultHero, ...value };
    const set = <K extends keyof HubHeroSettings>(
        key: K,
        next: HubHeroSettings[K],
    ) => onChange({ ...hero, [key]: next });
    return (
        <fieldset className="space-y-4">
            <legend className="text-section-title mb-3">Hero appearance</legend>
            <Label className="grid gap-2">
                Background
                <select
                    className="hub-native-select"
                    value={hero.style}
                    onChange={(e) =>
                        set('style', e.target.value as HubHeroSettings['style'])
                    }
                >
                    <option value="brand">Company colour</option>
                    <option value="image">Cover image</option>
                </select>
            </Label>
            <Label className="grid gap-2">
                Text alignment
                <select
                    className="hub-native-select"
                    value={hero.alignment}
                    onChange={(e) =>
                        set(
                            'alignment',
                            e.target.value as HubHeroSettings['alignment'],
                        )
                    }
                >
                    <option value="left">Left</option>
                    <option value="center">Centre</option>
                </select>
            </Label>
            <Label className="grid gap-2">
                Hero icon
                <select
                    className="hub-native-select"
                    value={hero.icon}
                    onChange={(e) =>
                        set('icon', e.target.value as HubHeroSettings['icon'])
                    }
                >
                    <option value="heart-handshake">
                        People and connection
                    </option>
                    <option value="newspaper">News</option>
                    <option value="sparkles">Celebration</option>
                    <option value="building-2">Company or team</option>
                    <option value="flag">Campaign</option>
                    <option value="door-open">Welcome</option>
                </select>
            </Label>
            {(
                [
                    ['show_search', 'Show Hub search'],
                    ['show_summary', 'Show description'],
                    ...(isHome
                        ? [['show_metrics', 'Show Hub summary links'] as const]
                        : []),
                ] as const
            ).map(([key, label]) => (
                <Label key={key} className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={hero[key]}
                        onChange={(e) => set(key, e.target.checked)}
                    />
                    {label}
                </Label>
            ))}
            {isHome && (
                <p className="text-caption text-muted-foreground">
                    Summary links show the stories, guides, teams and events
                    each staff member can access.
                </p>
            )}
            <Label className="grid gap-2">
                Banner height
                <select
                    className="hub-native-select"
                    value={hero.height}
                    onChange={(e) =>
                        set(
                            'height',
                            e.target.value as HubHeroSettings['height'],
                        )
                    }
                >
                    <option value="compact">Compact</option>
                    <option value="comfortable">Comfortable</option>
                </select>
            </Label>
            {hero.style === 'image' && (
                <>
                    {(
                        [
                            ['focal_x', 'Image horizontal position', 0, 100],
                            ['focal_y', 'Image vertical position', 0, 100],
                            ['overlay', 'Image shade', 55, 90],
                        ] as const
                    ).map(([key, label, min, max]) => (
                        <Label key={key} className="grid gap-2">
                            <span className="flex justify-between">
                                {label}
                                <span>{hero[key]}%</span>
                            </span>
                            <input
                                type="range"
                                min={min}
                                max={max}
                                value={hero[key]}
                                onChange={(e) =>
                                    set(key, Number(e.target.value))
                                }
                            />
                        </Label>
                    ))}
                    <p className="text-caption text-muted-foreground">
                        Position the important part of the photo in the banner.
                        The shade keeps the text readable.
                    </p>
                </>
            )}
            <Label className="grid gap-2">
                Hero link label
                <Input
                    value={hero.action_label ?? ''}
                    maxLength={60}
                    placeholder="Explore wellbeing"
                    onChange={(e) => set('action_label', e.target.value)}
                />
            </Label>
            <Label className="grid gap-2">
                Hero link destination
                <Input
                    value={hero.action_url ?? ''}
                    placeholder="/company-hub?view=resources"
                    onChange={(e) => set('action_url', e.target.value)}
                />
            </Label>
        </fieldset>
    );
}
