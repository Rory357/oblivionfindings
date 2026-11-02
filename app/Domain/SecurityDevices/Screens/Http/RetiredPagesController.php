<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * S&D rebuild C6, the menu switch: today's old Security & devices pages send people to their new
 * home in the six-entry menu (plan v11, "What happens to today's 36 pages").
 *
 * Each route keeps its old name and its old permission middleware, so nobody who couldn't open the
 * old page gets a hint of the new one, and every target page checks its own permissions and Sites.
 * Only page views redirect: the write endpoints the new pages still use (or that have no new home
 * yet) stay on their own routes.
 */
final class RetiredPagesController extends Controller
{
    private const string DEVICES = '/security-devices/devices';

    /** Old workspace tabs that have a closer home than the workspace's Devices tab. */
    private const array WORKSPACE_TABS = [
        'security' => ['cctv' => 'cameras', 'alarms' => 'alarms_doors'],
        'healthcare' => ['calibration-maintenance' => 'upkeep'],
    ];

    // ── The five workspaces (Drop) → Devices tabs ─────────────────────

    public function networkIt(Request $request): RedirectResponse
    {
        // "Global map → each Site's Network map".
        if ($request->query('tab') === 'map') {
            $site = $this->siteId($request);

            return redirect()->to($site === null ? '/security-devices/sites' : "/security-devices/sites/{$site}?tab=network");
        }

        return $this->workspace($request, 'network-it', 'network');
    }

    public function security(Request $request): RedirectResponse
    {
        return match ($request->query('tab')) {
            // Phase H: doors, people, visitors and the door log live on Access.
            'access-control' => redirect()->to('/security-devices/access'),
            'events' => redirect()->to($this->with('/security-devices/alerts', $this->siteQuery($request))),
            default => $this->workspace($request, 'security', 'alarms_doors'),
        };
    }

    /** Stephan, 1 Nov: the Health kit workspace becomes a Devices › Sensors filter. */
    public function healthcare(Request $request): RedirectResponse
    {
        return $this->workspace($request, 'healthcare', 'sensors', ['group' => 'health']);
    }

    /** Stephan, 1 Nov: trackers are owned by Fleet and read-only here. */
    public function tracking(Request $request): RedirectResponse
    {
        return $this->workspace($request, 'tracking', 'trackers');
    }

    public function facilitiesIot(Request $request): RedirectResponse
    {
        if ($request->query('tab') === 'automations') {
            return redirect()->to('/security-devices/alerts?tab=rules');
        }

        return $this->workspace($request, 'facilities-iot', 'sensors');
    }

    // ── The old category links (Keep redirects) → the matching Devices tab ──

    public function alarms(Request $request): RedirectResponse
    {
        return $this->devices($request, 'alarms_doors');
    }

    public function cctv(Request $request): RedirectResponse
    {
        return $this->devices($request, 'cameras');
    }

    public function accessControl(): RedirectResponse
    {
        return redirect()->to('/security-devices/access');
    }

    public function trackingDevices(Request $request): RedirectResponse
    {
        return $this->devices($request, 'trackers');
    }

    public function smartIotHealthcare(Request $request): RedirectResponse
    {
        return $this->devices($request, 'sensors', ['group' => 'health']);
    }

    public function itInfrastructure(Request $request): RedirectResponse
    {
        return $this->devices($request, 'network');
    }

    public function facilities(Request $request): RedirectResponse
    {
        return $this->devices($request, 'sensors');
    }

    // ── Device groups (Fold in, D8) and command batches (Drop) ────────

    /** The group pages go; a group is now a filter on Devices. The tables stay, read-only. */
    public function deviceGroups(Request $request, mixed $group = null): RedirectResponse
    {
        $id = is_numeric($group) && (int) $group > 0 ? (int) $group : null;

        return redirect()->to($this->with(self::DEVICES, $id === null ? [] : ['group' => (string) $id]));
    }

    /** The bulk bar on Devices acts directly; a batch's results are in each device's logbook. */
    public function commandBatch(): RedirectResponse
    {
        return redirect()->to(self::DEVICES);
    }

    // ── Alerts, upkeep and Setup (Rebuild / Merge / Drop page) ────────

    public function alerts(Request $request): RedirectResponse
    {
        return redirect()->to($this->with('/security-devices/alerts', $this->siteQuery($request)));
    }

    /** Batteries and planned work: Devices › Upkeep (quiet time is on Alerts). */
    public function maintenance(Request $request): RedirectResponse
    {
        return redirect()->to($this->with(self::DEVICES, ['tab' => 'upkeep', ...$this->siteQuery($request)]));
    }

    public function integrations(): RedirectResponse
    {
        return redirect()->to('/security-devices/setup/services?tab=connected');
    }

    /** Discovery and the generic downloads: Setup › Hubs & networks (per-Site downloads live in Add a hub). */
    public function hubsAndNetworks(): RedirectResponse
    {
        return redirect()->to('/security-devices/setup/hubs?tab=networks');
    }

    public function settings(Request $request): RedirectResponse
    {
        return redirect()->to(match ($request->query('tab')) {
            'monitoring' => '/security-devices/setup/alerts',
            'sources' => '/security-devices/setup/services?tab=logins',
            'audit' => '/security-devices/setup/history',
            default => '/security-devices/setup',
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $extra
     */
    private function workspace(Request $request, string $slug, string $tab, array $extra = []): RedirectResponse
    {
        $device = $request->query('device_id');
        if (is_string($device) && ctype_digit($device) && (int) $device > 0) {
            return redirect()->to(self::DEVICES.'/'.(int) $device);
        }

        $requested = (string) $request->query('tab', '');

        return $this->devices($request, self::WORKSPACE_TABS[$slug][$requested] ?? $tab, $extra);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function devices(Request $request, string $tab, array $extra = []): RedirectResponse
    {
        $query = ['tab' => $tab, ...$extra, ...$this->siteQuery($request)];
        $search = $request->query('q') ?? $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $query['q'] = mb_substr(trim($search), 0, 120);
        }

        return redirect()->to($this->with(self::DEVICES, $query));
    }

    /** @return array<string, string> */
    private function siteQuery(Request $request): array
    {
        $site = $this->siteId($request);

        return $site === null ? [] : ['site' => (string) $site];
    }

    private function siteId(Request $request): ?int
    {
        $raw = $request->query('site') ?? $request->query('site_id');

        return is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function with(string $path, array $query): string
    {
        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
