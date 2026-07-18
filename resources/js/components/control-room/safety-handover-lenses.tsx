import { Button } from '@/components/ui/button';

export type SafetyHandoverLens = {
    key: string;
    label: string;
    help: string;
    count: number;
};

export function SafetyHandoverLenses({
    lenses,
    activeLens,
    onSelect,
}: {
    lenses: readonly SafetyHandoverLens[];
    activeLens: string;
    onSelect: (lens: string) => void;
}) {
    return (
        <nav
            aria-label="Safety handover lenses"
            data-testid="safety-handover-lenses"
            className="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]"
        >
            {lenses.map((lens) => {
                const active = activeLens === lens.key;
                return (
                    <Button
                        unstyled
                        type="button"
                        key={lens.key}
                        aria-pressed={active}
                        onClick={() => onSelect(lens.key)}
                        title={lens.help}
                        className={`min-h-11 min-w-max rounded-full border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none ${
                            active
                                ? 'border-primary bg-primary/10 shadow-sm'
                                : 'border-border bg-card hover:border-primary/40 hover:bg-muted/30'
                        }`}
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="text-sm font-semibold text-foreground">
                                {lens.label}
                            </span>
                            <span
                                className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                }`}
                            >
                                {lens.count}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </nav>
    );
}
