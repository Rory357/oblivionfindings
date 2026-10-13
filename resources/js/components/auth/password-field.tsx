import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import { useState, type ComponentProps } from 'react';

/** A password input with a show/hide button (sign-in pages). */
export function PasswordField({
    className,
    ...props
}: Omit<ComponentProps<'input'>, 'type'>) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input
                {...props}
                type={visible ? 'text' : 'password'}
                className={cn('journey-control pr-13', className)}
            />
            <Button
                unstyled
                type="button"
                aria-label={visible ? 'Hide password' : 'Show password'}
                aria-pressed={visible}
                onClick={() => setVisible((value) => !value)}
                className="absolute top-1/2 right-0.5 grid size-11 -translate-y-1/2 place-items-center rounded-[10px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
            >
                {visible ? (
                    <EyeOff aria-hidden="true" className="size-[17px]" />
                ) : (
                    <Eye aria-hidden="true" className="size-[17px]" />
                )}
            </Button>
        </div>
    );
}
