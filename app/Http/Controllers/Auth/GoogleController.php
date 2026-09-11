<?php

namespace App\Http\Controllers\Auth;

/** Google shares the governed staff identity publication and approval path. */
class GoogleController extends MicrosoftController
{
    protected function provider(): string
    {
        return 'google';
    }
}
