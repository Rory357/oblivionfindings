<?php

namespace App\Domain\It\Data;

use App\Domain\It\Exceptions\ItTicketVersionConflict;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Safe per-selection outcomes. Never publish exception text or record metadata. */
final class ItBulkActionResult
{
    /** @return array{status: string, message: string} */
    public static function capture(Closure $operation): array
    {
        try {
            return self::outcome($operation() ? 'updated' : 'unchanged');
        } catch (AuthorizationException|ModelNotFoundException) {
            return self::outcome('unavailable');
        } catch (ItTicketVersionConflict) {
            return self::outcome('stale');
        } catch (DomainException|ValidationException|LogicException) {
            return self::outcome('blocked');
        } catch (Throwable $exception) {
            report($exception);

            return self::outcome('failed');
        }
    }

    /** @return array{status: string, message: string} */
    public static function outcome(string $status): array
    {
        return ['status' => $status, 'message' => match ($status) {
            'updated' => 'Change saved.',
            'unchanged' => 'Already matches this action. No change was needed.',
            'stale' => 'Changed since selection. Reload and review before trying again.',
            'unavailable' => 'This item is no longer available for this action.',
            'blocked' => 'Current requirements prevent this action. Open the item to review them.',
            'failed' => 'The change could not be saved. Reload and check the item before retrying.',
        }];
    }

    /** @param list<array{id: int, status: string, message: string}> $items */
    public static function payload(string $resource, string $action, array $items): array
    {
        $updated = count(array_filter($items, fn (array $item): bool => $item['status'] === 'updated'));
        $rejected = count(array_filter($items, fn (array $item): bool => ! in_array($item['status'], ['updated', 'unchanged'], true)));

        return [
            'resource' => $resource,
            'action' => $action,
            'selected' => count($items),
            'updated' => $updated,
            'unchanged' => count($items) - $updated - $rejected,
            'rejected' => $rejected,
            'items' => $items,
        ];
    }
}
