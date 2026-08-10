<?php

namespace App\Domain\Monitoring\Contracts;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
