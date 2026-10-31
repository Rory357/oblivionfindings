import { PageHeaderGlassButton } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-react';
import type { AlertActionItem, AlertActionKey } from './alert-actions';

/**
 * The "More actions" menu for an alert — one item list for the header
 * (glass button on the brand sky) and the Live response preview (outline
 * button on a card). Red items sit last, after a separator.
 */
export function AlertActionsMenu({
    items,
    onRun,
    appearance = 'glass',
    label = 'More actions',
}: {
    items: readonly AlertActionItem[];
    onRun: (key: AlertActionKey) => void;
    appearance?: 'glass' | 'outline';
    label?: string;
}) {
    if (!items.length) return null;
    const plain = items.filter((item) => item.tone !== 'critical');
    const critical = items.filter((item) => item.tone === 'critical');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {appearance === 'glass' ? (
                    <PageHeaderGlassButton
                        icon={MoreHorizontal}
                        aria-label={label}
                        title={label}
                    />
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={label}
                        title={label}
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                {plain.map((item) => {
                    const Icon = item.icon;
                    return (
                        <DropdownMenuItem
                            key={item.key}
                            onSelect={() => onRun(item.key)}
                        >
                            <Icon aria-hidden="true" />
                            {item.label}
                        </DropdownMenuItem>
                    );
                })}
                {critical.length && plain.length ? (
                    <DropdownMenuSeparator />
                ) : null}
                {critical.map((item) => {
                    const Icon = item.icon;
                    return (
                        <DropdownMenuItem
                            key={item.key}
                            variant="destructive"
                            onSelect={() => onRun(item.key)}
                        >
                            <Icon aria-hidden="true" />
                            {item.label}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
