import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import { useAppShortcuts } from '@/hooks/use-keyboard-shortcut';
import { Keyboard } from 'lucide-react';
import { useState } from 'react';

interface ShortcutHelpItem {
    keys: string[];
    description: string;
}

const shortcuts: ShortcutHelpItem[] = [
    { keys: ['Ctrl/Cmd', 'K'], description: 'Open search / command palette' },
    { keys: ['Ctrl/Cmd', 'N'], description: 'Create new item' },
    { keys: ['Ctrl/Cmd', 'S'], description: 'Save changes' },
    { keys: ['Ctrl/Cmd', 'D'], description: 'Go to Dashboard' },
    { keys: ['Ctrl/Cmd', 'Shift', 'C'], description: 'Go to Clients' },
    { keys: ['Ctrl/Cmd', 'Shift', 'S'], description: 'Go to Shifts' },
    { keys: ['Ctrl/Cmd', 'Shift', 'I'], description: 'Go to Incidents' },
    { keys: ['Ctrl/Cmd', 'B'], description: 'Toggle sidebar' },
    { keys: ['Escape'], description: 'Close modal / Cancel action' },
    { keys: ['Shift', '?'], description: 'Show this help dialog' },
];

export function KeyboardShortcutsHelp() {
    const [open, setOpen] = useState(false);

    useAppShortcuts(
        {
            onHelp: () => setOpen(true),
        },
        { enabled: true },
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8">
                    <Keyboard className="h-4 w-4" />
                    <span className="sr-only">Keyboard shortcuts</span>
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Keyboard Shortcuts</DialogTitle>
                    <DialogDescription>
                        Speed up your workflow with these keyboard shortcuts.
                    </DialogDescription>
                </DialogHeader>
                <Table>
                    <TableBody>
                        {shortcuts.map((shortcut, index) => (
                            <TableRow key={index}>
                                <TableCell className="py-2">
                                    <div className="flex items-center gap-1">
                                        {shortcut.keys.map((key, keyIndex) => (
                                            <span key={keyIndex}>
                                                <kbd className="rounded border bg-muted px-1.5 py-0.5 font-mono text-xs">
                                                    {key}
                                                </kbd>
                                                {keyIndex <
                                                    shortcut.keys.length -
                                                        1 && (
                                                    <span className="mx-1 text-muted-foreground">
                                                        +
                                                    </span>
                                                )}
                                            </span>
                                        ))}
                                    </div>
                                </TableCell>
                                <TableCell className="py-2 text-sm text-muted-foreground">
                                    {shortcut.description}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </DialogContent>
        </Dialog>
    );
}

export default KeyboardShortcutsHelp;
