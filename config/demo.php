<?php

return [
    /*
    | The client demo website (Stephan, 23 Oct 2026). When true, guarded migrations install the
    | fictional "Oblivion Living" provider: branding and photos, published referral settings with
    | online enquiries on, and sample job postings. Set only on demonstration servers; a real
    | client's server must leave it off so it never receives demo content.
    */
    'site' => (bool) env('DEMO_SITE', false),
];
