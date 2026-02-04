import AppLogoIcon from '@/components/app-logo-icon';
import { type PropsWithChildren } from 'react';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthLayoutProps>) {
    return (
        <div className="relative min-h-svh w-full overflow-hidden bg-background">
            <div className="relative grid min-h-svh w-full grid-cols-1">
                <div className="absolute inset-0">
                    <div
                        className="absolute inset-0"
                        style={{
                            backgroundImage:
                                "url('/images/auth/BackgroundImageOF5.jpg')",
                            backgroundSize: 'cover',
                            backgroundPosition: 'center',
                        }}
                    />
                    <div className="absolute inset-0 bg-background/0" />
                </div>
                <div className="flex min-h-svh items-center justify-center px-6 py-6">
                    <div className="w-full max-w-[420px] rounded-2xl border border-white/70 bg-card/45 p-6 shadow-2xl backdrop-blur-[32px] md:p-8">
                        <div className="mb-4 flex flex-col gap-3">
                            <div className="flex items-center gap-2">
                                <div className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                    <AppLogoIcon className="size-4 fill-current" />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <h1 className="text-2xl font-semibold text-foreground">
                                    {title}
                                </h1>
                                <p className="text-sm text-foreground/70">
                                    {description}
                                </p>
                            </div>
                        </div>
                        <div className="text-foreground">{children}</div>
                    </div>
                </div>
            </div>
        </div>
    );
}
