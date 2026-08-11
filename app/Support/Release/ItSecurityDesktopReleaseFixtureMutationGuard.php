<?php

namespace App\Support\Release;

use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use Closure;
use Throwable;

final class ItSecurityDesktopReleaseFixtureMutationGuard
{
    public const int SCHEMA_VERSION = 1;

    public const string EVIDENCE_CLASS = 'it_security_desktop_release_fixture_mutation_guard_v1';

    /** @var list<string> */
    public const array ACTIONS = ['prepare', 'cleanup', 'reset', 'withdraw-tracking-consent'];

    /** @var Closure(string, string): bool */
    private readonly Closure $verifyCheckout;

    /** @param null|Closure(string, string): bool $verifyCheckout */
    public function __construct(?Closure $verifyCheckout = null)
    {
        $this->verifyCheckout = $verifyCheckout
            ?? static fn (string $checkout, string $revision): bool => (new LoadSoakReleaseCheckoutVerifier)
                ->verify($checkout, $revision);
    }

    public static function confirmationToken(string $action, string $revision): string
    {
        return 'IT-SECURITY-DESKTOP-FIXTURES:'.strtoupper($action).':'.$revision;
    }

    /**
     * @param null|array{
     *     environment: mixed,
     *     platform: mixed,
     *     enabled: mixed,
     *     environment_class: mixed,
     *     database_driver: mixed,
     *     database_name: mixed,
     *     database_name_sha256: mixed,
     *     checkout: mixed
     * } $context
     * @return array<string, mixed>
     */
    public function assess(
        string $action,
        string $expectedRevision,
        string $confirmation,
        ?array $context = null,
    ): array {
        $context ??= $this->runtimeContext();
        $gaps = [];
        $actionAllowed = in_array($action, self::ACTIONS, true);
        $revisionValid = preg_match('/\A[0-9a-f]{40}\z/', $expectedRevision) === 1;
        $environment = $context['environment'] ?? null;
        $platform = $context['platform'] ?? null;
        $environmentApproved = $environment === 'testing'
            || ($environment === 'staging' && $platform === 'Linux');
        $enabled = ($context['enabled'] ?? null) === true;
        $environmentClassApproved = ($context['environment_class'] ?? null) === 'approved_non_production';
        $databaseDriverApproved = ($context['database_driver'] ?? null) === 'mysql';
        $databaseName = $context['database_name'] ?? null;
        $databasePin = $context['database_name_sha256'] ?? null;
        $databasePinned = is_string($databaseName)
            && $databaseName !== ''
            && is_string($databasePin)
            && preg_match('/\A[0-9a-f]{64}\z/', $databasePin) === 1
            && hash_equals($databasePin, hash('sha256', $databaseName));
        $confirmationVerified = $actionAllowed
            && $revisionValid
            && hash_equals(self::confirmationToken($action, $expectedRevision), $confirmation);
        $checkoutVerified = false;

        if ($revisionValid && is_string($context['checkout'] ?? null) && $context['checkout'] !== '') {
            try {
                $checkoutVerified = ($this->verifyCheckout)($context['checkout'], $expectedRevision);
            } catch (Throwable) {
                $checkoutVerified = false;
            }
        }

        if (! $actionAllowed) {
            $gaps[] = 'release_fixture_mutation_action_not_allowed';
        }
        if (! $environmentApproved) {
            $gaps[] = 'release_fixture_environment_not_approved';
        }
        if (! $enabled) {
            $gaps[] = 'release_fixture_mutation_not_enabled';
        }
        if (! $environmentClassApproved) {
            $gaps[] = 'release_fixture_environment_class_not_approved';
        }
        if (! $databaseDriverApproved) {
            $gaps[] = 'release_fixture_database_driver_not_approved';
        }
        if (! $databasePinned) {
            $gaps[] = 'release_fixture_database_pin_mismatch';
        }
        if (! $revisionValid) {
            $gaps[] = 'release_fixture_revision_invalid';
        }
        if (! $checkoutVerified) {
            $gaps[] = 'release_fixture_checkout_not_verified';
        }
        if (! $confirmationVerified) {
            $gaps[] = 'release_fixture_confirmation_mismatch';
        }

        $gaps = array_values(array_unique($gaps));
        sort($gaps);
        $authorised = $gaps === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_class' => self::EVIDENCE_CLASS,
            'state' => $authorised ? 'authorised' : 'refused',
            'action' => $actionAllowed ? $action : null,
            'release_revision' => $revisionValid ? $expectedRevision : null,
            'checks' => [
                'approved_non_production_environment' => $environmentApproved && $environmentClassApproved,
                'explicit_mutation_enablement' => $enabled,
                'exact_mysql_database_pin' => $databaseDriverApproved && $databasePinned,
                'clean_origin_main_checkout' => $checkoutVerified,
                'exact_action_confirmation' => $confirmationVerified,
            ],
            'gap_codes' => $gaps,
            'fixture_write_authorized' => $authorised,
            'v10_release_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function runtimeContext(): array
    {
        $defaultConnection = config('database.default');
        $connection = is_string($defaultConnection)
            ? config('database.connections.'.$defaultConnection)
            : null;

        return [
            'environment' => app()->environment(),
            'platform' => PHP_OS_FAMILY,
            'enabled' => config('it.desktop_release_fixtures.enabled'),
            'environment_class' => config('it.desktop_release_fixtures.environment_class'),
            'database_driver' => is_array($connection) ? ($connection['driver'] ?? null) : null,
            'database_name' => is_array($connection) ? ($connection['database'] ?? null) : null,
            'database_name_sha256' => config('it.desktop_release_fixtures.database_name_sha256'),
            'checkout' => base_path(),
        ];
    }
}
