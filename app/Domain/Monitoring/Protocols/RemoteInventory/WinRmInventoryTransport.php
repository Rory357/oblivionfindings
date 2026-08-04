<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

final class WinRmInventoryTransport
{
    private const int MAX_SOAP_BYTES = 1_048_576;

    private const int MAX_PULL_PAGES = 16;

    public function __construct(private readonly WinRmHttpClient $client) {}

    public function collect(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        InventoryQuery $query,
    ): InventoryResult {
        if ($target->scheme !== 'winrm' || $target->path !== '/wsman'
            || $target->addresses === [] || $query->platform !== 'windows') {
            throw new RuntimeException('WinRM requires an approved HTTPS target.');
        }

        $material = $lease->material();
        try {
            $this->validateMaterial($material);
            $maximumBytes = min($target->maxResponseBytes, self::MAX_SOAP_BYTES);
            if ($maximumBytes < 1) {
                return InventoryResult::failure('response_too_large');
            }

            $facts = [];
            $completed = 0;
            $failed = 0;
            $latency = 0;
            foreach ($query->operations as $operation) {
                /** @var array{class: string, properties: list<string>} $operation */
                $responses = [];
                $context = null;
                $operationBytes = 0;
                for ($page = 0; $page < self::MAX_PULL_PAGES; $page++) {
                    try {
                        $response = $this->client->exchange(
                            $target,
                            $target->addresses[0],
                            $context === null
                                ? $this->enumerateSoap($target, $operation)
                                : $this->pullSoap($target, $operation, $context),
                            $material,
                            max(1, $maximumBytes - $operationBytes),
                        );
                    } catch (WinRmTransportException $exception) {
                        return InventoryResult::failure($exception->reason, $latency, $completed, $failed + 1);
                    }
                    $latency += max(0, $response->latencyMs);
                    $operationBytes += strlen($response->body);
                    if ($response->truncated || $operationBytes > $maximumBytes) {
                        return InventoryResult::failure('response_too_large', $latency, $completed, $failed + 1);
                    }
                    if ($response->status !== 200) {
                        $failed++;

                        continue 2;
                    }

                    $state = $this->enumerationState($response->body);
                    if ($state === null) {
                        $failed++;

                        continue 2;
                    }
                    $responses[] = $response->body;
                    $context = $state['context'];
                    if ($state['complete'] || $context === null) {
                        break;
                    }
                    if ($page === self::MAX_PULL_PAGES - 1) {
                        $failed++;

                        continue 2;
                    }
                }

                $parsed = $this->parse($operation, $responses);
                if ($parsed === null) {
                    $failed++;

                    continue;
                }
                $facts = [...$facts, ...$parsed];
                $completed++;
            }

            return InventoryResult::collected($facts, $latency, $completed, $failed);
        } finally {
            $this->clear($material);
        }
    }

    /** @param array{class: string, properties: list<string>} $operation */
    private function enumerateSoap(AuthorizedProbeTarget $target, array $operation): string
    {
        $class = $operation['class'];
        $properties = implode(',', $operation['properties']);
        $resource = 'http://schemas.microsoft.com/wbem/wsman/1/wmi/root/cimv2/'.$class;
        $messageId = strtoupper(bin2hex(random_bytes(16)));
        $query = "SELECT {$properties} FROM {$class}";
        $host = str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;
        $endpoint = "https://{$host}:{$target->port}/wsman";

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
            .'xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
            .'xmlns:w="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd" '
            .'xmlns:n="http://schemas.xmlsoap.org/ws/2004/09/enumeration">'
            .'<s:Header>'
            .'<a:Action>http://schemas.xmlsoap.org/ws/2004/09/enumeration/Enumerate</a:Action>'
            .'<a:To>'.htmlspecialchars($endpoint, ENT_XML1).'</a:To>'
            .'<w:ResourceURI s:mustUnderstand="true">'.htmlspecialchars($resource, ENT_XML1).'</w:ResourceURI>'
            .'<a:MessageID>uuid:'.$messageId.'</a:MessageID>'
            .'</s:Header><s:Body><n:Enumerate><w:OptimizeEnumeration/><w:MaxElements>256</w:MaxElements>'
            .'<w:Filter Dialect="http://schemas.microsoft.com/wbem/wsman/1/WQL">'
            .htmlspecialchars($query, ENT_XML1)
            .'</w:Filter></n:Enumerate></s:Body></s:Envelope>';
    }

    /** @param array{class: string, properties: list<string>} $operation */
    private function pullSoap(AuthorizedProbeTarget $target, array $operation, string $context): string
    {
        $class = $operation['class'];
        $resource = 'http://schemas.microsoft.com/wbem/wsman/1/wmi/root/cimv2/'.$class;
        $host = str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;
        $endpoint = "https://{$host}:{$target->port}/wsman";

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
            .'xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
            .'xmlns:w="http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd" '
            .'xmlns:n="http://schemas.xmlsoap.org/ws/2004/09/enumeration">'
            .'<s:Header>'
            .'<a:Action>http://schemas.xmlsoap.org/ws/2004/09/enumeration/Pull</a:Action>'
            .'<a:To>'.htmlspecialchars($endpoint, ENT_XML1).'</a:To>'
            .'<w:ResourceURI s:mustUnderstand="true">'.htmlspecialchars($resource, ENT_XML1).'</w:ResourceURI>'
            .'<a:MessageID>uuid:'.strtoupper(bin2hex(random_bytes(16))).'</a:MessageID>'
            .'</s:Header><s:Body><n:Pull><n:EnumerationContext>'
            .htmlspecialchars($context, ENT_XML1)
            .'</n:EnumerationContext><n:MaxElements>256</n:MaxElements></n:Pull></s:Body></s:Envelope>';
    }

    /** @return array{context: ?string, complete: bool}|null */
    private function enumerationState(string $soap): ?array
    {
        $document = $this->document($soap);
        if (! $document instanceof DOMDocument) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $contexts = $xpath->query('//*[local-name()="EnumerationContext"]');
        $complete = $xpath->query('//*[local-name()="EndOfSequence"]');
        if ($contexts === false || $complete === false || $contexts->length > 1) {
            return null;
        }
        $context = $contexts->length === 1 ? $this->safeValue($contexts->item(0)->textContent) : null;
        if ($contexts->length === 1 && $context === null) {
            return null;
        }

        return ['context' => $context, 'complete' => $complete->length > 0];
    }

    /**
     * @param  array{class: string, properties: list<string>}  $operation
     * @return array<string, int|float|string|bool|null>|null
     */
    private function parse(array $operation, array $soapResponses): ?array
    {
        if ($soapResponses === [] || ! array_is_list($soapResponses)) {
            return null;
        }
        $values = [];
        foreach ($operation['properties'] as $property) {
            $values[$property] = [];
        }
        foreach ($soapResponses as $soap) {
            $document = $this->document($soap);
            if (! $document instanceof DOMDocument) {
                return null;
            }
            $xpath = new DOMXPath($document);
            foreach ($operation['properties'] as $property) {
                $nodes = $xpath->query('//*[local-name()="'.$property.'"]');
                if ($nodes === false || $nodes->length + count($values[$property]) > 1_000) {
                    return null;
                }
                foreach ($nodes as $node) {
                    $value = $this->safeValue($node->textContent);
                    if ($value !== null) {
                        $values[$property][] = $value;
                    }
                }
            }
        }

        return match ($operation['class']) {
            'Win32_OperatingSystem' => $this->operatingSystemFacts($values),
            'Win32_LogicalDisk' => $this->diskFacts($values),
            'Win32_Service' => $this->serviceFacts($values),
            default => null,
        };
    }

    private function document(string $soap): ?DOMDocument
    {
        if ($soap === '' || str_contains(strtoupper($soap), '<!DOCTYPE')
            || str_contains(strtoupper($soap), '<!ENTITY')) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            return $document->loadXML($soap, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)
                ? $document
                : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string, list<string>> $values @return array<string, int|float|string|bool|null>|null */
    private function operatingSystemFacts(array $values): ?array
    {
        $caption = $values['Caption'][0] ?? null;
        $version = $values['Version'][0] ?? null;
        if (! is_string($caption) || $caption === '' || ! is_string($version) || $version === '') {
            return null;
        }
        $facts = ['os_name' => $caption, 'os_version' => $version];
        $boot = $values['LastBootUpTime'][0] ?? null;
        if (is_string($boot) && preg_match('/^(\d{14})\.(\d{6})([+-]\d{3})$/', $boot, $parts) === 1) {
            try {
                $offsetMinutes = (int) $parts[3];
                $sign = $offsetMinutes < 0 ? '-' : '+';
                $offsetMinutes = abs($offsetMinutes);
                $offset = sprintf('%s%02d:%02d', $sign, intdiv($offsetMinutes, 60), $offsetMinutes % 60);
                $facts['boot_time'] = CarbonImmutable::createFromFormat(
                    'YmdHis.uP',
                    $parts[1].'.'.$parts[2].$offset,
                )->utc()->toISOString();
            } catch (Throwable) {
                // The optional boot time is omitted when a provider emits an invalid DMTF value.
            }
        }

        return $facts;
    }

    /** @param array<string, list<string>> $values @return array<string, int>|null */
    private function diskFacts(array $values): ?array
    {
        $sizes = $values['Size'] ?? [];
        $freeValues = $values['FreeSpace'] ?? [];
        if ($sizes === [] || count($sizes) !== count($freeValues)) {
            return null;
        }
        $total = 0;
        $free = 0;
        $maximumUsage = 0;
        foreach ($sizes as $index => $size) {
            $available = $freeValues[$index] ?? null;
            if (preg_match('/^\d+$/', $size) !== 1 || ! is_string($available)
                || preg_match('/^\d+$/', $available) !== 1) {
                return null;
            }
            $sizeValue = filter_var($size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $freeValue = filter_var($available, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! is_int($sizeValue) || ! is_int($freeValue) || $freeValue > $sizeValue
                || $sizeValue > PHP_INT_MAX - $total || $freeValue > PHP_INT_MAX - $free) {
                return null;
            }
            $total += $sizeValue;
            $free += $freeValue;
            if ($sizeValue > 0) {
                $maximumUsage = max($maximumUsage, (int) round((($sizeValue - $freeValue) / $sizeValue) * 100));
            }
        }

        return [
            'disk_bytes_total' => $total,
            'disk_bytes_free' => $free,
            'disk_usage_percent_max' => $maximumUsage,
            'volume_count' => count($sizes),
        ];
    }

    /** @param array<string, list<string>> $values @return array{failed_service_count: int}|null */
    private function serviceFacts(array $values): ?array
    {
        $states = $values['State'] ?? [];
        $modes = $values['StartMode'] ?? [];
        if (count($states) !== count($modes)) {
            return null;
        }
        $failed = 0;
        foreach ($states as $index => $state) {
            if (strcasecmp($modes[$index] ?? '', 'Auto') === 0 && strcasecmp($state, 'Running') !== 0) {
                $failed++;
            }
        }

        return ['failed_service_count' => $failed];
    }

    private function safeValue(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && strlen($value) <= 512
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1
                ? $value
                : null;
    }

    /** @param array<string, scalar|null> $material */
    private function validateMaterial(array $material): void
    {
        $mode = $material['auth_mode'] ?? null;
        if ($mode === 'kerberos') {
            $allowed = ['auth_mode', 'username', 'password'];
            $username = $material['username'] ?? null;
            $password = $material['password'] ?? null;
            if (array_diff(array_keys($material), $allowed) !== []
                || ! is_string($username) || $username === '' || strlen($username) > 255
                || preg_match('/[\x00-\x20\x7f]/', $username) === 1
                || ! is_string($password) || $password === '' || strlen($password) > 4096) {
                throw new RuntimeException('WinRM credential material is invalid.');
            }

            return;
        }
        if ($mode === 'certificate') {
            $allowed = ['auth_mode', 'certificate_pem', 'private_key_pem', 'private_key_passphrase'];
            $certificate = $material['certificate_pem'] ?? null;
            $privateKey = $material['private_key_pem'] ?? null;
            if (array_diff(array_keys($material), $allowed) !== []
                || ! is_string($certificate) || $certificate === '' || strlen($certificate) > 131_072
                || ! is_string($privateKey) || $privateKey === '' || strlen($privateKey) > 131_072
                || (isset($material['private_key_passphrase']) && ! is_string($material['private_key_passphrase']))) {
                throw new RuntimeException('WinRM credential material is invalid.');
            }

            return;
        }

        throw new RuntimeException('WinRM credential material is invalid.');
    }

    /** @param array<string, scalar|null> $material */
    private function clear(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                function_exists('sodium_memzero') ? sodium_memzero($value) : $value = str_repeat("\0", strlen($value));
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
