<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class ProbeRunner
{
    private const array SSH_OPERATIONS = [
        'os_release' => 'uname -srm',
        'disk' => 'df -Pk',
        'uptime' => 'uptime -p',
        'interfaces' => 'ip -brief address',
    ];

    private const array SNMP_INVENTORY_OIDS = [
        '1.3.6.1.2.1.1.1.0',
        '1.3.6.1.2.1.1.2.0',
        '1.3.6.1.2.1.1.3.0',
        '1.3.6.1.2.1.1.5.0',
        '1.3.6.1.2.1.2.1.0',
    ];

    private const array WINRM_OPERATIONS = [
        'operating_system' => [
            'class' => 'Win32_OperatingSystem',
            'properties' => ['Caption', 'Version', 'LastBootUpTime'],
        ],
        'logical_disks' => [
            'class' => 'Win32_LogicalDisk',
            'properties' => ['Size', 'FreeSpace'],
        ],
        'services' => [
            'class' => 'Win32_Service',
            'properties' => ['State', 'StartMode'],
        ],
    ];

    public function __construct(
        private ScopeGuard $scope,
        private ?CredentialLeaseDecryptor $credentials = null,
    ) {}

    /** @param array<string, mixed> $check @return array<string, mixed> */
    public function run(array $check, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCheck($check, $at);
        $started = hrtime(true);
        $material = null;

        try {
            if (in_array($check['protocol'], ['snmp', 'ssh', 'winrm'], true)) {
                if ($this->credentials === null) {
                    throw new RuntimeException('credential_lease_decryptor_unavailable');
                }
                $material = $this->credentials->open($check, $at);
            }
            $result = match ($check['protocol']) {
                'icmp' => $this->icmp($check),
                'tcp' => $this->tcp($check),
                'dns' => $this->dns($check),
                'http', 'https' => $this->http($check),
                'tls' => $this->tls($check),
                'snmp' => $this->snmp($check, $material),
                'ssh' => $this->ssh($check, $material),
                'winrm' => $this->winrm($check, $material),
                default => throw new RuntimeException('Collector probe protocol is unavailable.'),
            };
        } catch (\Throwable $exception) {
            $result = [
                'state' => 'unknown',
                'reason_code' => $this->reasonCode($exception),
                'metrics' => [],
            ];
        } finally {
            if (is_array($material)) {
                $this->destroyMaterial($material);
            }
        }

        return [
            'check_id' => $check['id'],
            'device_id' => $check['device_id'],
            'protocol' => $check['protocol'],
            'target' => $check['target'],
            'observed_at' => $at->format(DATE_ATOM),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'state' => $result['state'],
            'reason_code' => $result['reason_code'],
            'metrics' => $this->boundedMetrics($result['metrics']),
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function icmp(array $check): array
    {
        $timeout = $this->timeout($check);
        $arguments = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', (string) ($timeout * 1000), $check['target']]
            : ['ping', '-c', '1', '-W', (string) $timeout, $check['target']];
        $process = new Process($arguments);
        $process->setTimeout($timeout + 1);
        $process->run();

        return [
            'state' => $process->isSuccessful() ? 'healthy' : 'failed',
            'reason_code' => $process->isSuccessful() ? 'icmp_reply' : 'icmp_unreachable',
            'metrics' => ['exit_code' => $process->getExitCode()],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function tcp(array $check): array
    {
        $port = $this->port($check);
        $started = hrtime(true);
        $socket = @stream_socket_client(
            'tcp://'.$this->socketAddress($check['target']).':'.$port,
            $errorNumber,
            $errorMessage,
            $this->timeout($check),
            STREAM_CLIENT_CONNECT,
        );
        $connected = is_resource($socket);
        if ($connected) {
            fclose($socket);
        }

        return [
            'state' => $connected ? 'healthy' : 'failed',
            'reason_code' => $connected ? 'tcp_connected' : 'tcp_connection_failed',
            'metrics' => ['port' => $port, 'connect_ms' => (int) round((hrtime(true) - $started) / 1_000_000)],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function dns(array $check): array
    {
        $query = $check['query'] ?? null;
        $recordType = strtoupper((string) ($check['record_type'] ?? 'A'));
        if (! is_string($query) || ! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $query)) {
            throw new RuntimeException('dns_query_invalid');
        }
        $type = match ($recordType) {
            'A' => 1,
            'AAAA' => 28,
            default => throw new RuntimeException('dns_type_invalid'),
        };
        $transaction = random_int(1, 65535);
        $question = '';
        foreach (explode('.', $query) as $label) {
            $question .= chr(strlen($label)).$label;
        }
        $packet = pack('nnnnnn', $transaction, 0x0100, 1, 0, 0, 0).$question."\0".pack('nn', $type, 1);
        $socket = @stream_socket_client(
            'udp://'.$this->socketAddress($check['target']).':'.(int) ($check['port'] ?? 53),
            $errorNumber,
            $errorMessage,
            $this->timeout($check),
        );
        if (! is_resource($socket)) {
            throw new RuntimeException('dns_transport_unavailable');
        }
        stream_set_timeout($socket, $this->timeout($check));
        fwrite($socket, $packet);
        $response = fread($socket, 4096);
        fclose($socket);
        if (! is_string($response) || strlen($response) < 12) {
            throw new RuntimeException('dns_response_invalid');
        }
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($response, 0, 12));
        if ($header['id'] !== $transaction || ($header['flags'] & 0x000F) !== 0) {
            throw new RuntimeException('dns_response_rejected');
        }

        return [
            'state' => $header['answers'] > 0 ? 'healthy' : 'failed',
            'reason_code' => $header['answers'] > 0 ? 'dns_answered' : 'dns_no_answer',
            'metrics' => ['answer_count' => min(100, $header['answers']), 'record_type' => $recordType],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function http(array $check): array
    {
        $url = $check['url'] ?? null;
        if (! is_string($url) || strlen($url) > 2048) {
            throw new RuntimeException('http_url_invalid');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        if (! is_string($host) || ! in_array($scheme, ['http', 'https'], true) || $scheme !== $check['protocol']) {
            throw new RuntimeException('http_url_invalid');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('http_transport_unavailable');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout($check),
            CURLOPT_TIMEOUT => $this->timeout($check),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$check['target']}"],
            CURLOPT_PROTOCOLS => $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'state' => $success !== false && $status >= 200 && $status < 500 ? 'healthy' : 'failed',
            'reason_code' => $success !== false ? 'http_response' : 'http_transport_failed',
            'metrics' => ['status_code' => $status],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function tls(array $check): array
    {
        $serverName = $check['server_name'] ?? null;
        if (! is_string($serverName) || $serverName === '' || strlen($serverName) > 253) {
            throw new RuntimeException('tls_server_name_invalid');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $serverName,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'disable_compression' => true,
        ]]);
        $socket = @stream_socket_client(
            'tls://'.$this->socketAddress($check['target']).':'.$this->port($check, 443),
            $errorNumber,
            $errorMessage,
            $this->timeout($check),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            throw new RuntimeException('tls_handshake_failed');
        }
        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate === null ? false : openssl_x509_parse($certificate, false);
        $validTo = is_array($parsed) ? (int) ($parsed['validTo_time_t'] ?? 0) : 0;

        return [
            'state' => $validTo > time() ? 'healthy' : 'failed',
            'reason_code' => $validTo > time() ? 'tls_verified' : 'tls_certificate_expired',
            'metrics' => ['days_remaining' => max(0, (int) floor(($validTo - time()) / 86400))],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function snmp(array $check, #[\SensitiveParameter] array $material): array
    {
        if (! function_exists('snmp3_get')) {
            return ['state' => 'unknown', 'reason_code' => 'snmp_runtime_unavailable', 'metrics' => []];
        }
        $response = @snmp3_get(
            $check['target'],
            (string) ($material['username'] ?? ''),
            'authPriv',
            (string) ($material['auth_protocol'] ?? 'SHA'),
            (string) ($material['auth_passphrase'] ?? ''),
            (string) ($material['privacy_protocol'] ?? 'AES'),
            (string) ($material['privacy_passphrase'] ?? ''),
            self::SNMP_INVENTORY_OIDS,
            $this->timeout($check) * 1_000_000,
            0,
        );

        return [
            'state' => $response === false ? 'failed' : 'healthy',
            'reason_code' => $response === false ? 'snmp_query_failed' : 'snmp_query_succeeded',
            'metrics' => ['returned_oids' => is_array($response) ? min(16, count($response)) : 1],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function ssh(array $check, #[\SensitiveParameter] array $material): array
    {
        $operation = $check['operation'] ?? null;
        $fixedOperation = is_string($operation) ? (self::SSH_OPERATIONS[$operation] ?? null) : null;
        $username = $material['username'] ?? null;
        $knownHosts = $material['known_hosts_file'] ?? null;
        $identity = $material['identity_file'] ?? null;
        if (! is_string($fixedOperation)
            || ! is_string($username)
            || ! preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/i', $username)
            || ! is_string($knownHosts)
            || ! is_file($knownHosts)
            || ! is_string($identity)
            || ! is_file($identity)
        ) {
            throw new RuntimeException('ssh_lease_material_invalid');
        }

        $process = new Process([
            'ssh', '-F', 'none', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$knownHosts, '-o', 'ConnectTimeout='.$this->timeout($check),
            '-i', $identity, $username.'@'.$check['target'], $fixedOperation,
        ]);
        $process->setTimeout($this->timeout($check));
        $process->run();
        $output = $process->getOutput();

        return [
            'state' => $process->isSuccessful() ? 'healthy' : 'failed',
            'reason_code' => $process->isSuccessful() ? 'ssh_inventory_collected' : 'ssh_inventory_failed',
            'metrics' => [
                'operation' => $operation,
                'line_count' => min(256, substr_count(substr($output, 0, 1_048_576), "\n") + 1),
                'output_hash' => hash('sha256', substr($output, 0, 1_048_576)),
            ],
        ];
    }

    /** @param array<string, mixed> $check @return array{state: string, reason_code: string, metrics: array<string, mixed>} */
    private function winrm(array $check, #[\SensitiveParameter] array $material): array
    {
        $operationName = $check['operation'] ?? null;
        $operation = is_string($operationName) ? (self::WINRM_OPERATIONS[$operationName] ?? null) : null;
        $certificate = $material['certificate_file'] ?? null;
        $privateKey = $material['private_key_file'] ?? null;
        if (! is_array($operation)
            || ! is_string($certificate)
            || ! is_file($certificate)
            || ! is_string($privateKey)
            || ! is_file($privateKey)
        ) {
            throw new RuntimeException('winrm_lease_material_invalid');
        }
        $port = $this->port($check, 5986);
        $handle = curl_init('https://'.$this->socketAddress($check['target']).':'.$port.'/wsman');
        if ($handle === false) {
            throw new RuntimeException('winrm_transport_unavailable');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->winrmEnvelope($operation),
            CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml;charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout($check),
            CURLOPT_TIMEOUT => $this->timeout($check),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $certificate,
            CURLOPT_SSLKEY => $privateKey,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $bounded = is_string($response) ? substr($response, 0, 1_048_576) : '';

        return [
            'state' => $response !== false && $status >= 200 && $status < 300 ? 'healthy' : 'failed',
            'reason_code' => $response !== false ? 'winrm_inventory_collected' : 'winrm_inventory_failed',
            'metrics' => [
                'operation' => $operationName,
                'status_code' => $status,
                'response_hash' => hash('sha256', $bounded),
            ],
        ];
    }

    /** @param array{class: string, properties: list<string>} $operation */
    private function winrmEnvelope(array $operation): string
    {
        $resource = 'http://schemas.microsoft.com/wbem/wsman/1/wmi/root/cimv2/'.$operation['class'];
        $query = 'SELECT '.implode(',', $operation['properties']).' FROM '.$operation['class'];

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
            .'xmlns:w="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd" '
            .'xmlns:n="http://schemas.xmlsoap.org/ws/2004/09/enumeration">'
            .'<s:Header><w:ResourceURI s:mustUnderstand="true">'.$resource.'</w:ResourceURI></s:Header>'
            .'<s:Body><n:Enumerate><w:Filter Dialect="http://schemas.microsoft.com/wbem/wsman/1/WQL">'
            .$query.'</w:Filter></n:Enumerate></s:Body></s:Envelope>';
    }

    /** @param array<string, mixed> $check */
    private function timeout(array $check): int
    {
        return max(1, min(15, (int) ($check['timeout_seconds'] ?? 5)));
    }

    /** @param array<string, mixed> $check */
    private function port(array $check, ?int $default = null): int
    {
        $port = (int) ($check['port'] ?? $default ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('probe_port_invalid');
        }

        return $port;
    }

    private function socketAddress(string $address): string
    {
        return str_contains($address, ':') ? "[{$address}]" : $address;
    }

    private function reasonCode(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        $normalised = preg_replace('/[^a-z0-9_]+/', '_', $message);

        return substr(trim((string) $normalised, '_'), 0, 96) ?: 'probe_failed';
    }

    /** @param array<string, scalar|null> $material */
    private function destroyMaterial(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    /** @param array<string, mixed> $metrics @return array<string, int|float|string|bool|null> */
    private function boundedMetrics(array $metrics): array
    {
        $bounded = [];
        foreach (array_slice($metrics, 0, 32, true) as $key => $value) {
            if (! is_string($key) || strlen($key) > 64 || ! is_scalar($value) && $value !== null) {
                continue;
            }
            $bounded[$key] = is_string($value) ? substr($value, 0, 256) : $value;
        }

        return $bounded;
    }
}
