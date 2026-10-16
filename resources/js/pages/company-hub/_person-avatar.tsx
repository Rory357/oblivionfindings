import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';

export function HubPersonAvatar({
    name,
    url,
    className = '',
}: {
    name: string;
    url?: string | null;
    className?: string;
}) {
    return (
        <Avatar className={`shrink-0 ${className}`} aria-hidden>
            {url && <AvatarImage src={url} alt="" className="object-cover" />}
            <AvatarFallback className="bg-primary/10 text-primary">
                {name
                    .trim()
                    .split(/\s+/)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')}
            </AvatarFallback>
        </Avatar>
    );
}
