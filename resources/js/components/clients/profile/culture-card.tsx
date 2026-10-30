import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

const labels = {
    ethnicity: 'Ethnicity',
    iwi: 'Iwi',
    hapu: 'Hapū',
    marae: 'Marae',
    languages: 'Languages',
    preferred_pronouns: 'Pronouns',
    religion: 'Religion or beliefs',
    cultural_dietary_needs: 'Cultural dietary needs',
};
export function ProfileCultureCard({
    client,
    onEdit,
}: {
    client: Partial<Record<keyof typeof labels, string | string[] | null>>;
    onEdit?: () => void;
}) {
    const fields = Object.entries(labels).flatMap(([key, label]) => {
        const raw = client[key as keyof typeof labels];
        const value = Array.isArray(raw) ? raw.join(', ') : raw?.trim();
        return value ? [{ key, label, value }] : [];
    });
    if (!fields.length) return null;
    return (
        <Card className="mb-4">
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-3">
                <CardTitle className="text-base">
                    Culture and language
                </CardTitle>
                {onEdit && (
                    <Button size="sm" variant="outline" onClick={onEdit}>
                        Edit profile details
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {fields.map(({ key, label, value }) => (
                        <div key={key} className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="mt-1 text-sm break-words whitespace-pre-wrap">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}
