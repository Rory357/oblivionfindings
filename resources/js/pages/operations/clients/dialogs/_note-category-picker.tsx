import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    Activity,
    AlertTriangle,
    HeartPulse,
    MessageSquare,
    Moon,
    Target,
    UserRound,
} from 'lucide-react';
import type { ComponentType } from 'react';

export type NoteCategoryKey =
    | 'activity'
    | 'mood'
    | 'health'
    | 'communication'
    | 'concern'
    | 'goal_progress'
    | 'routine'
    | 'other';

type NoteCategory = {
    key: NoteCategoryKey;
    label: string;
    description: string;
    icon: ComponentType<{ className?: string }>;
    accent: string;
};

export const NOTE_CATEGORIES: NoteCategory[] = [
    {
        key: 'activity',
        label: 'Activity',
        description: 'What they did today',
        icon: Activity,
        accent: 'text-status-info',
    },
    {
        key: 'mood',
        label: 'Mood / behaviour',
        description: 'Patterns, mood, or support needs',
        icon: HeartPulse,
        accent: 'text-status-warning',
    },
    {
        key: 'health',
        label: 'Health',
        description: 'Wellbeing, symptoms, checks',
        icon: HeartPulse,
        accent: 'text-status-critical',
    },
    {
        key: 'communication',
        label: 'Communication',
        description: 'Family, agency, or provider contact',
        icon: MessageSquare,
        accent: 'text-primary',
    },
    {
        key: 'concern',
        label: 'Concern',
        description: 'Needs review or follow-up',
        icon: AlertTriangle,
        accent: 'text-status-critical',
    },
    {
        key: 'goal_progress',
        label: 'Goal progress',
        description: 'Progress against a support goal',
        icon: Target,
        accent: 'text-status-success',
    },
    {
        key: 'routine',
        label: 'Routine',
        description: 'Rhythm, preference, or change',
        icon: Moon,
        accent: 'text-status-info',
    },
    {
        key: 'other',
        label: 'Other',
        description: 'General daily note',
        icon: UserRound,
        accent: 'text-muted-foreground',
    },
];

export function NoteCategoryPicker({
    value,
    onChange,
    compact = false,
}: {
    value: string;
    onChange: (value: NoteCategoryKey) => void;
    compact?: boolean;
}) {
    if (compact) {
        return (
            <div className="flex flex-wrap gap-2">
                {NOTE_CATEGORIES.map((category) => (
                    <Button
                        key={category.key}
                        type="button"
                        variant={value === category.key ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => onChange(category.key)}
                        className="min-h-11"
                        data-test={`daily-note-category-${category.key}`}
                    >
                        {category.label}
                    </Button>
                ))}
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {NOTE_CATEGORIES.map((category) => {
                const Icon = category.icon;
                const active = value === category.key;

                return (
                    <Button unstyled
                        key={category.key}
                        type="button"
                        onClick={() => onChange(category.key)}
                        data-test={`daily-note-category-${category.key}`}
                        className={cn(
                            'frontline-focus group flex min-h-16 items-start gap-3 rounded-lg border bg-card p-3 text-left transition-colors',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/30'
                                : 'border-border hover:border-primary/50 hover:bg-accent/40',
                        )}
                    >
                        <span className="mt-0.5 rounded-md bg-background p-1.5">
                            <Icon className={cn('h-4 w-4', category.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                {category.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {category.description}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}
