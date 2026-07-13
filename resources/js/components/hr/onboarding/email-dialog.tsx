import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useEffect } from 'react';

export interface EmailTemplate {
    id: number;
    template_name: string;
    subject: string;
    body: string;
    send_days_before_start: number;
    is_active: boolean;
}

const TOKENS = [
    '{{employee_name}}',
    '{{position_title}}',
    '{{start_date}}',
    '{{manager_name}}',
    '{{company_name}}',
];

const SAMPLE: Record<string, string> = {
    employee_name: 'Aroha Ngata',
    position_title: 'Support Worker',
    start_date: '30/06/2026',
    manager_name: 'Mere Tipene',
    company_name: 'Kaha Care',
};

function renderPreview(template: string): string {
    let out = template;
    for (const [k, v] of Object.entries(SAMPLE)) out = out.replaceAll(`{{${k}}}`, v);
    return out;
}

export function EmailDialog({
    open,
    onClose,
    email,
}: {
    open: boolean;
    onClose: () => void;
    email: EmailTemplate | null;
}) {
    const form = useForm({
        template_name: '',
        subject: '',
        body: '',
        send_days_before_start: 7,
        is_active: true,
    });

    useEffect(() => {
        if (!open) return;
        form.setData(
            email
                ? {
                      template_name: email.template_name,
                      subject: email.subject,
                      body: email.body,
                      send_days_before_start: email.send_days_before_start,
                      is_active: email.is_active,
                  }
                : {
                      template_name: '',
                      subject: '',
                      body: '',
                      send_days_before_start: 7,
                      is_active: true,
                  },
        );
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, email?.id]);

    const submit = () => {
        if (email) {
            form.put(`/hr/onboarding/emails/${email.id}`, {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        } else {
            form.post('/hr/onboarding/emails', {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
        }
    };

    const insertToken = (token: string) => form.setData('body', `${form.data.body}${token}`);

    const sendTest = () => {
        if (!email) return;
        router.post(`/hr/onboarding/emails/${email.id}/test`, {}, { preserveScroll: true });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-hidden p-0 sm:max-w-[760px]">
                <DialogHeader className="border-b border-border px-6 py-4">
                    <DialogTitle>{email ? 'Edit email template' : 'New email template'}</DialogTitle>
                    <DialogDescription>Edit copy, merge tokens & schedule.</DialogDescription>
                </DialogHeader>

                <div className="grid max-h-[64vh] gap-5 overflow-y-auto px-6 py-5 md:grid-cols-2">
                    <div className="space-y-3">
                        <div className="space-y-1.5">
                            <Label>Name</Label>
                            <Input
                                value={form.data.template_name}
                                onChange={(e) => form.setData('template_name', e.target.value)}
                                placeholder="Welcome to the team"
                            />
                            {form.errors.template_name && (
                                <p className="text-xs text-status-critical">{form.errors.template_name}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Subject</Label>
                            <Input
                                value={form.data.subject}
                                onChange={(e) => form.setData('subject', e.target.value)}
                                placeholder="Welcome to {{company_name}}, {{employee_name}}!"
                            />
                            {form.errors.subject && (
                                <p className="text-xs text-status-critical">{form.errors.subject}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Body</Label>
                            <Textarea
                                rows={6}
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                                placeholder="Kia ora {{employee_name}}…"
                            />
                            {form.errors.body && <p className="text-xs text-status-critical">{form.errors.body}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Insert merge token</Label>
                            <div className="flex flex-wrap gap-1.5">
                                {TOKENS.map((t) => (
                                    <Button unstyled
                                        key={t}
                                        type="button"
                                        onClick={() => insertToken(t)}
                                        className="rounded-md bg-accent px-2 py-1 font-mono text-[11px] font-semibold text-primary"
                                    >
                                        {t}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Send timing</Label>
                            <div className="flex items-center gap-2 text-sm">
                                <Input
                                    type="number"
                                    className="w-20"
                                    value={form.data.send_days_before_start}
                                    onChange={(e) => form.setData('send_days_before_start', Number(e.target.value))}
                                />
                                <span className="text-muted-foreground">days before start date</span>
                            </div>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_active}
                                onCheckedChange={(c) => form.setData('is_active', Boolean(c))}
                            />
                            Active
                        </label>
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs text-muted-foreground">Live preview</Label>
                        <div className="overflow-hidden rounded-xl border border-border">
                            <div className="h-1.5 bg-primary" />
                            <div className="space-y-2.5 p-4 text-[12.5px] leading-relaxed">
                                <div className="text-[11px] text-muted-foreground">
                                    <b>Subject:</b> {renderPreview(form.data.subject) || '—'}
                                </div>
                                <p className="whitespace-pre-wrap">{renderPreview(form.data.body) || '…'}</p>
                            </div>
                        </div>
                        {email && (
                            <Button type="button" variant="outline" className="w-full" onClick={sendTest}>
                                <Send className="mr-2 h-4 w-4" /> Send test to me
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-6 py-3.5">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : email ? 'Save template' : 'Create template'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default EmailDialog;
