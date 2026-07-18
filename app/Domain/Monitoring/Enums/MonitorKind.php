<?php

namespace App\Domain\Monitoring\Enums;

enum MonitorKind: string
{
    case Icmp = 'icmp';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Http = 'http';
    case Tls = 'tls';
    case Snmp = 'snmp';
    case SnmpInterface = 'snmp_interface';
    case Provider = 'provider';
    case Collector = 'collector';
}
