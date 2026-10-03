<?php

namespace App\Services\Medication\Downtime;

use Barryvdh\DomPDF\Facade\Pdf;

/** P09 print adapter: the domain projection supplies the printable facts. */
class DowntimePackPdf
{
    public function render(array $pack): string
    {
        return Pdf::loadView('pdf.medication-downtime-pack', ['pack' => $pack])->setPaper('A4', 'landscape')->output();
    }
}
