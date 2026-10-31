<?php

namespace App\Domain\Monitoring\Services;

final class NetworkConfigurationRedactor
{
    public const string REDACTED = '[REDACTED]';

    public const int MAX_CONFIG_LINES = 20000;

    public const int MAX_LINE_LENGTH = 512;

    private const string SENSITIVE_KEY_PATTERN = '/(?:^|_|-)(password|passwd|passphrase|secret|token|api_key|apikey|private_key|pre_shared_key|psk|community|snmp_community|auth_key|priv_key|tacacs_key|radius_key|wpa_passphrase|shared_secret|bearer|authorization|cookie|credential|key_material)(?:$|_|-)/i';

    /**
     * Redact secrets across structured network configuration payloads and CLI running-config lines.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactPayload(array $payload): array
    {
        return $this->redactArray($payload);
    }

    /**
     * Split and redact raw CLI running-configuration text into bounded lines.
     *
     * @return list<string>
     */
    public function redactTextToLines(string $configText): array
    {
        $cleanText = $this->stripPemBlocks($configText);
        $rawLines = preg_split("/\r\n|\n|\r/", $cleanText) ?: [];

        return $this->redactLines($rawLines);
    }

    /**
     * @param  array<int|string, mixed>  $lines
     * @return list<string>
     */
    public function redactLines(array $lines): array
    {
        $output = [];
        $inPrivateBlock = false;

        foreach ($lines as $rawLine) {
            if (! is_string($rawLine)) {
                continue;
            }
            if (count($output) >= self::MAX_CONFIG_LINES) {
                break;
            }

            $line = rtrim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $rawLine) ?? '');
            if (preg_match('/-----BEGIN [A-Z0-9 ]*(?:PRIVATE KEY|SECRET|ENCRYPTED)-----/i', $line) === 1) {
                $inPrivateBlock = true;
                $output[] = '-----REDACTED PRIVATE KEY-----';

                continue;
            }
            if ($inPrivateBlock) {
                if (preg_match('/-----END [A-Z0-9 ]*(?:PRIVATE KEY|SECRET|ENCRYPTED)-----/i', $line) === 1) {
                    $inPrivateBlock = false;
                }

                continue;
            }

            $redacted = $this->redactLine($line);
            if (mb_strlen($redacted) > self::MAX_LINE_LENGTH) {
                $redacted = mb_substr($redacted, 0, self::MAX_LINE_LENGTH);
            }
            $output[] = $redacted;
        }

        return $output;
    }

    public function redactLine(string $line): string
    {
        $patterns = [
            // Cisco / Aruba enable secret or password (with optional encryption type 0/5/7/8/9)
            '/^(\s*enable\s+(?:secret|password)(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            // Cisco / Aruba username ... secret/password ...
            '/^(\s*username\s+\S+(?:\s+privilege\s+\d+)?(?:\s+role\s+\S+)?\s+(?:secret|password)(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            // SNMP community strings (Cisco / Aruba / HP / MikroTik / EdgeOS)
            '/^(\s*snmp-server\s+community)\s+\S+(\s+.*)?$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*set\s+system\s+services\s+snmp\s+community)\s+\S+(\s+.*)?$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*\/snmp\s+community\s+(?:add|set)\b.*?\bname\s*=\s*)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1'.self::REDACTED,
            // SNMPv3 auth / priv passphrases on a single line
            '/(\b(?:auth|priv)\s+(?:md5|sha|sha256|sha512|des|3des|aes(?:\s+\d+)?))\s+\S+/i' => '$1 '.self::REDACTED,
            // RADIUS / TACACS / NTP / OSPF / BGP / HSRP / VRRP / ISAKMP / IPsec keys
            '/^(\s*(?:tacacs-server|radius-server)(?:\s+host\s+\S+)?\s+key(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*key-string(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*ntp\s+authentication-key\s+\d+\s+\S+(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*ip\s+ospf\s+message-digest-key\s+\d+\s+md5(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*neighbor\s+\S+\s+password(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*crypto\s+isakmp\s+key(?:\s+\d+)?)\s+\S+(\s+address\s+.*)?$/i' => '$1 '.self::REDACTED.'$2',
            '/^(\s*(?:pre-shared-key|wpa-psk|wpa-passphrase|passphrase|shared-secret|encrypted-password|secret-data|api-token|auth-token))((?:\s+(?:ascii|hex))?(?:\s+\d+)?)\s+\S+(.*)$/i' => '$1$2 '.self::REDACTED.'$3',
            // FortiGate: set password / set psksecret / set passwd / set private-key / set auth-password-l1
            '/^(\s*set\s+(?:password|passwd|psksecret|secret|keylifeseconds-secret|auth-password\S*|priv-password\S*|private-key|api-key))\s+.+$/i' => '$1 '.self::REDACTED,
            // Junos: encrypted-password "..."; secret "..."; pre-shared-key ascii-text "...";
            '/(\b(?:encrypted-password|secret|pre-shared-key(?:\s+ascii-text)?|authentication-key)\s+)"[^"]*"(\s*;?)/i' => '$1"'.self::REDACTED.'"$2',
            // Aruba AOS-CX / ArubaOS-Switch ciphertext secrets (e.g., "password ciphertext AQBap..." or "radius-server host 1.2.3.4 key ciphertext AQB...")
            '/(\bciphertext)\s+\S+/i' => '$1 '.self::REDACTED,
            // MikroTik RouterOS & generic key=value or key: value assignments inside config lines
            '/(\b(?:password|passwd|passphrase|secret|api_token|api-token|access_token|private_key|private-key|pre_shared_key|pre-shared-key|preshared-key|wpa_psk|wpa-pre-shared-key|wpa2-pre-shared-key|authentication-password|encryption-password|shared-secret|community)\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1'.self::REDACTED,
            // Line-level password command inside console/vty stanza (e.g., " password 7 0822455D0A16")
            '/^(\s*password(?:\s+\d+)?)\s+\S+$/i' => '$1 '.self::REDACTED,
            // Authorization Bearer headers if echoed
            '/(\bBearer\s+)[A-Za-z0-9._~+\/-]+/i' => '$1'.self::REDACTED,
        ];

        $current = $line;
        foreach ($patterns as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $current);
            if (is_string($updated)) {
                $current = $updated;
            }
        }

        return $current;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data, ?string $parentKey = null): array
    {
        if ($parentKey === 'running_config_lines') {
            return $this->redactLines($data);
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $result[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redactArray($value, is_string($key) ? $key : null);

                continue;
            }

            if (is_string($value)) {
                $result[$key] = str_contains($value, "\n") || str_contains($value, '-----BEGIN')
                    ? implode("\n", $this->redactTextToLines($value))
                    : $this->redactLine($value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function stripPemBlocks(string $text): string
    {
        return preg_replace(
            '/-----BEGIN [A-Z0-9 ]*(?:PRIVATE KEY|SECRET|ENCRYPTED)-----.*?-----END [A-Z0-9 ]*(?:PRIVATE KEY|SECRET|ENCRYPTED)-----/is',
            '-----REDACTED PRIVATE KEY-----',
            $text,
        ) ?? $text;
    }
}
