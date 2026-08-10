<?php

namespace App\Services\Queclink\Listener;

class QueclinkListenerRuntimeProbe
{
    public function serviceState(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'not_applicable';
        }

        $output = [];
        $code = 0;
        exec('systemctl is-active oblivion-queclink.service 2>&1', $output, $code);

        return trim(implode(' ', $output)) ?: 'unknown';
    }
}
