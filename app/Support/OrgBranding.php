<?php

namespace App\Support;

class OrgBranding
{
    public static function name(): string
    {
        return (string) config('app.name', 'Oblivion Findings');
    }

    public static function logoUrl(): ?string
    {
        return null;
    }
}

