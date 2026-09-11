import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { Circle } from 'lucide-react';
import { useRef, useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { WizardShell } from './shell';

function Host({ optedIn }: { optedIn: boolean }) {
    const [open, setOpen] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    return (
        <>
            <Button onClick={() => setOpen(true)}>Open example wizard</Button>
            {open && (
                <WizardShell
                    open
                    onClose={() => setOpen(false)}
                    onOpenAutoFocus={
                        optedIn
                            ? (event) => {
                                  event.preventDefault();
                                  input.current?.focus();
                              }
                            : undefined
                    }
                    title="Example wizard"
                    description="Synthetic focus test"
                    railIcon={Circle}
                    railTitle="Example"
                    railSub="Focus check"
                    steps={[
                        {
                            key: 'details',
                            label: 'Details',
                            blurb: 'Add details',
                            icon: Circle,
                        },
                    ]}
                    stepIndex={0}
                    onStepClick={() => {}}
                >
                    <Input ref={input} aria-label="Example field" />
                </WizardShell>
            )}
        </>
    );
}

describe('WizardShell optional initial focus', () => {
    afterEach(async () => {
        cleanup();
        await new Promise((resolve) => window.setTimeout(resolve, 0));
    });
    it.each([true, false])(
        'preserves opener capture with the optional hook %s',
        async (optedIn) => {
            render(<Host optedIn={optedIn} />);
            const opener = screen.getByRole('button', {
                name: 'Open example wizard',
            });
            opener.focus();
            fireEvent.click(opener);
            const dialog = await screen.findByRole('dialog', {
                name: 'Example wizard',
            });
            await waitFor(() => {
                if (optedIn)
                    expect(
                        screen.getByRole('textbox', { name: 'Example field' }),
                    ).toHaveFocus();
                else
                    expect(dialog).toContainElement(
                        document.activeElement as HTMLElement,
                    );
            });
            fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
            await waitFor(() =>
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
            );
            await waitFor(() => expect(opener).toHaveFocus());
        },
    );
});
