import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { Check } from 'lucide-react';
import { useId } from 'react';
import { heroIconChoices, heroIcons } from './_hero-icons';
import type { HubHeroSettings } from './_types';

function HeroChoices<Value extends string>({
    label,
    value,
    choices,
    onChange,
}: {
    label: string;
    value: Value;
    choices: { value: Value; label: string; description: string }[];
    onChange: (value: Value) => void;
}) {
    const id = useId();
    return (
        <div className="space-y-2">
            <Label id={id}>{label}</Label>
            <RadioGroup
                aria-labelledby={id}
                value={value}
                onValueChange={(next) => onChange(next as Value)}
                className="grid-cols-2 gap-2"
            >
                {choices.map((choice) => (
                    <Label
                        key={choice.value}
                        className="relative flex min-w-0 cursor-pointer items-start gap-2 rounded-lg border bg-card p-3 hover:bg-accent has-focus-visible:ring-2 has-focus-visible:ring-ring data-[selected=true]:border-primary data-[selected=true]:bg-primary/5"
                        data-selected={value === choice.value}
                    >
                        <RadioGroupItem
                            value={choice.value}
                            aria-label={choice.label}
                            className="mt-0.5 shrink-0"
                        />
                        <span className="min-w-0 space-y-1">
                            <span className="block text-sm font-medium">
                                {choice.label}
                            </span>
                            <span className="block text-xs leading-5 font-normal text-muted-foreground">
                                {choice.description}
                            </span>
                        </span>
                    </Label>
                ))}
            </RadioGroup>
        </div>
    );
}

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
    const iconId = useId();
    const hero = { ...defaultHero, ...value };
    const set = <K extends keyof HubHeroSettings>(
        key: K,
        next: HubHeroSettings[K],
    ) => onChange({ ...hero, [key]: next });
    return (
        <fieldset className="space-y-4">
            <legend className="text-section-title mb-3">Hero appearance</legend>
            <HeroChoices
                label="Background"
                value={hero.style}
                onChange={(value) => set('style', value)}
                choices={[
                    {
                        value: 'brand',
                        label: 'Company colour',
                        description: 'Use the company theme.',
                    },
                    {
                        value: 'image',
                        label: 'Cover image',
                        description: 'Use your selected photo.',
                    },
                ]}
            />
            <HeroChoices
                label="Text alignment"
                value={hero.alignment ?? 'left'}
                onChange={(value) => set('alignment', value)}
                choices={[
                    {
                        value: 'left',
                        label: 'Left',
                        description: 'Keep the heading to the left.',
                    },
                    {
                        value: 'center',
                        label: 'Centre',
                        description: 'Centre the heading and description.',
                    },
                ]}
            />
            <div className="space-y-2">
                <Label id={`${iconId}-label`}>Hero icon</Label>
                <RadioGroup
                    aria-labelledby={`${iconId}-label`}
                    className="grid-cols-3"
                    value={
                        hero.icon === 'heart-handshake' ? 'sparkles' : hero.icon
                    }
                    onValueChange={(value) =>
                        set('icon', value as HubHeroSettings['icon'])
                    }
                >
                    {heroIconChoices.map(({ value, label }) => {
                        const Icon = heroIcons[value];
                        const selected =
                            (hero.icon === 'heart-handshake'
                                ? 'sparkles'
                                : hero.icon) === value;
                        return (
                            <Label
                                key={value}
                                className="group relative flex min-w-0 cursor-pointer flex-col items-center gap-2 rounded-lg border bg-card px-2 py-3 text-center hover:bg-accent has-focus-visible:ring-2 has-focus-visible:ring-ring data-[selected=true]:border-primary data-[selected=true]:bg-primary/5 data-[selected=true]:text-primary"
                                data-selected={selected}
                            >
                                <RadioGroupItem
                                    className="sr-only"
                                    value={value}
                                    aria-label={label}
                                />
                                <Icon className="size-6" />
                                {selected && (
                                    <Check
                                        aria-hidden="true"
                                        className="absolute top-1 right-1 size-3"
                                    />
                                )}
                                <span className="text-caption max-w-full wrap-anywhere">
                                    {label}
                                </span>
                            </Label>
                        );
                    })}
                </RadioGroup>
            </div>
            {(
                [
                    ['show_search', 'Show Hub search'],
                    ['show_summary', 'Show description'],
                    ...(isHome
                        ? [['show_metrics', 'Show Hub summary links'] as const]
                        : []),
                ] as const
            ).map(([key, label]) => (
                <Label
                    key={key}
                    className="flex cursor-pointer items-center justify-between gap-3 rounded-lg border bg-card px-3 py-3"
                    htmlFor={`${iconId}-${key}`}
                >
                    {label}
                    <Switch
                        id={`${iconId}-${key}`}
                        checked={hero[key]}
                        onCheckedChange={(checked) => set(key, checked)}
                    />
                </Label>
            ))}
            {isHome && (
                <p className="text-caption text-muted-foreground">
                    Summary links show the stories, guides, teams and events
                    each staff member can access.
                </p>
            )}
            <HeroChoices
                label="Banner height"
                value={hero.height}
                onChange={(value) => set('height', value)}
                choices={[
                    {
                        value: 'compact',
                        label: 'Compact',
                        description: 'More room for your page.',
                    },
                    {
                        value: 'comfortable',
                        label: 'Comfortable',
                        description: 'Give the banner more space.',
                    },
                ]}
            />
            {hero.style === 'image' && (
                <>
                    {(
                        [
                            ['focal_x', 'Image horizontal position', 0, 100],
                            ['focal_y', 'Image vertical position', 0, 100],
                            ['overlay', 'Image shade', 20, 90],
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
                        Adjust the photo shade; a soft shade behind the words
                        keeps them readable.
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
