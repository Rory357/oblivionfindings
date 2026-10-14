import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    authorization: Record<string, string>;
    account: { name: string; email: string };
    server: string;
};

export default function AuthorizeObli({
    authorization,
    account,
    server,
}: Props) {
    const form = useForm({ ...authorization, decision: 'allow' });
    function decide(decision: 'allow' | 'decline') {
        form.transform((data) => ({ ...data, decision }));
        form.post('/obli/authorize');
    }
    return (
        <AuthLayout
            title="Sign in to Obli"
            description="Only continue if you just selected Sign in in the Windows helper on this computer."
        >
            <Head title="Sign in to Obli" />
            <div className="space-y-6">
                <div>
                    <p className="font-medium">{account.name}</p>
                    <p className="text-subtle text-muted-foreground">
                        {account.email}
                    </p>
                    <p className="text-caption mt-2 break-all text-muted-foreground">
                        {server}
                    </p>
                </div>
                <p className="text-subtle">
                    Obli can show your own support tickets and submit the
                    requests you review. You can also choose to send a private
                    conversation to your organisation’s configured AI provider.
                    Signing in alone does not send chat or grant remote control.
                </p>
                <p className="text-subtle text-muted-foreground">
                    On a shared computer, check that this is your account. If it
                    is not, decline and sign out of Oblivion in your browser
                    before trying again.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button
                        className="frontline-tap"
                        disabled={form.processing}
                        onClick={() => decide('allow')}
                    >
                        Continue as {account.name}
                    </Button>
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => decide('decline')}
                    >
                        Decline
                    </Button>
                </div>
            </div>
        </AuthLayout>
    );
}
