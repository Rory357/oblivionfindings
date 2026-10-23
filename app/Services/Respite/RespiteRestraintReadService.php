<?php

namespace App\Services\Respite;

use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\RestraintRegisterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Respite capability is an entry grant, never an implicit restraint narrative read. */
final class RespiteRestraintReadService
{
    public function __construct(private readonly RestraintAccessService $access, private readonly RestraintRegisterService $register, private readonly RespiteStayScope $stays) {}

    public function forStay(User $viewer, RespiteStay $stay): array
    {
        return $this->conceal(fn () => $this->within($viewer, function ($current, $reads, $sites) use ($stay) {
            $fresh = $this->currentStay($current, $reads, $sites, $stay);
            $can = $this->capabilities($current, $reads, $sites, $fresh);

            return ['can' => $can, 'summary' => $can['view'] ? $this->register->summary($current, $fresh->client) : null, 'report_href' => $can['capture'] ? $this->reportHref($fresh) : null];
        }), $this->emptyStay());
    }

    public function reportContext(User $viewer, RespiteStay $stay): array
    {
        return $this->within($viewer, function ($current, $reads, $sites) use ($stay) {
            $fresh = $this->currentStay($current, $reads, $sites, $stay);
            $can = $this->capabilities($current, $reads, $sites, $fresh);
            abort_unless($can['capture'], 403);

            return ['pickers' => $this->register->pickersForStay($current, $fresh), 'client_id' => (int) $fresh->client_id, 'site_id' => $this->stays->siteId($fresh), 'respite_stay_id' => (int) $fresh->id, 'store_href' => '/respite/stays/'.$fresh->id.'/restraints', 'can_create_plan' => ($current->canDo('restraints.create') || $current->canDo('restraints.manage')) && $this->allowed(fn () => $this->access->assertClient($fresh->client, $current, $reads, $sites, 'capture'))];
        });
    }

    /** Legacy picker names and H&S counts only; unrelated Respite lists are not rewritten here. */
    public function workspace(User $viewer): array
    {
        return $this->conceal(fn () => $this->within($viewer, function ($current, $reads, $sites) {
            $picker = ['sites' => [], 'staff' => [], 'incidents' => [], 'plans' => []];
            $byStay = [];
            $reviewTotal = $missingTotal = 0;
            $reads->query(RespiteStay::query())->chunkById(200, function ($rows) use ($current, $reads, $sites, &$picker, &$byStay, &$reviewTotal, &$missingTotal) {
                foreach ($rows as $row) {
                    $fresh = $this->conceal(fn () => $this->currentStay($current, $reads, $sites, $row), null);
                    if (! $fresh) {
                        continue;
                    }
                    $can = $this->capabilities($current, $reads, $sites, $fresh);
                    $operation = $can['view'] ? 'view' : ($can['review'] ? 'workspace' : null);
                    $unreviewed = $missing = 0;
                    if ($operation) {
                        $reads->query(RestraintEvent::query())->where('stay_id', $fresh->id)->where('client_id', $fresh->client_id)->where('site_id', $this->stays->siteId($fresh))->chunkById(200, function ($events) use ($current, $reads, $sites, $operation, &$unreviewed, &$missing) {
                            foreach ($events as $event) {
                                if (! $this->allowed(fn () => $this->access->assertEvent($event, $current, $reads, $sites, $operation))) {
                                    continue;
                                }
                                $unreviewed += $event->needsReview() ? 1 : 0;
                                $missing += $event->within_support_plan && $event->behaviour_support_plan_id === null ? 1 : 0;
                            }
                        });
                    }
                    $reviewTotal += $unreviewed;
                    $missingTotal += $missing;
                    $byStay[(int) $fresh->id] = ['can' => $can, 'report_href' => $can['capture'] ? $this->reportHref($fresh) : null, 'unreviewed' => $unreviewed];
                    if (! $can['capture'] || ! in_array($fresh->status, ['active', 'extended'], true)) {
                        continue;
                    }
                    $options = $this->register->pickersForStay($current, $fresh);
                    foreach (['sites' => 'sites', 'staff' => 'staff', 'incidents' => 'incidents', 'plans' => 'plansForPicker'] as $legacy => $key) {
                        foreach ($options[$key] as $option) {
                            if ($legacy === 'incidents') {
                                $option['respite_stay_id'] = (int) $fresh->id;
                            }
                            $picker[$legacy][$option['id']] = $option;
                        }
                    }
                }
            });

            return ['pickers' => array_map(fn ($rows) => array_values($rows), $picker), 'by_stay' => $byStay, 'restraintsAwaitingReview' => $reviewTotal, 'bspAwaitingLink' => $missingTotal];
        }), ['pickers' => ['sites' => [], 'staff' => [], 'incidents' => [], 'plans' => []], 'by_stay' => [], 'restraintsAwaitingReview' => 0, 'bspAwaitingLink' => 0]);
    }

    private function within(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, RestraintAccessService::PERMISSIONS, fn ($context) => $this->access->withReadContext($context, function ($current, $reads, $sites) use ($operation) {
            abort_unless($current->canDo('respite.viewAny'), 403);

            return $operation($current, $reads, $sites);
        }));
    }

    private function currentStay(User $current, CurrentAuthorizationReads $reads, array $sites, RespiteStay $stay): RespiteStay
    {
        $reads->assertActive();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $current);
        $fresh = $this->stays->resolveAuthorizedStay($request, (int) $stay->id, true, $reads);
        $this->access->assertStay((int) $fresh->id, (int) $fresh->client_id, $this->stays->siteId($fresh), $current, $reads, $sites);

        return $fresh;
    }

    private function capabilities(User $current, CurrentAuthorizationReads $reads, array $sites, RespiteStay $stay): array
    {
        $proof = fn ($operation) => $this->allowed(fn () => $this->access->assertClient($stay->client, $current, $reads, $sites, $operation));

        return ['capture' => $current->canDo('respite.stays.manage') && $proof('respite_capture'), 'view' => $current->canDo('restraints.view') && $proof('view'), 'review' => ($current->canDo('restraints.review') || $current->canDo('restraints.manage')) && $proof('workspace')];
    }

    private function reportHref(RespiteStay $stay): string
    {
        return '/respite/stays/'.$stay->id.'/restraints/report?'.http_build_query(['return_to' => '/respite/stays/'.$stay->id], '', '&', PHP_QUERY_RFC3986);
    }

    private function emptyStay(): array
    {
        return ['can' => ['capture' => false, 'view' => false, 'review' => false], 'summary' => null, 'report_href' => null];
    }

    private function allowed(callable $operation): bool
    {
        return $this->conceal(function () use ($operation) {
            $operation();

            return true;
        }, false);
    }

    private function conceal(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            return $fallback;
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return $fallback;
        }
    }
}
