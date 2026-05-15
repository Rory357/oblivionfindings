import { Button } from '@/components/ui/button';
import { PlusCircle } from 'lucide-react';

type Props = {
    label?: string;
    onClick?: () => void;
    disabled?: boolean;
};

export function MissingFieldButton({
    label = 'Add missing detail',
    onClick,
    disabled = false,
}: Props) {
    if (!onClick || disabled) {
        return (
            <span className="font-normal italic text-muted-foreground">
                Not specified
            </span>
        );
    }

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            className="gap-1.5"
            onClick={onClick}
        >
            <PlusCircle className="h-3.5 w-3.5" />
            {label}
        </Button>
    );
}
