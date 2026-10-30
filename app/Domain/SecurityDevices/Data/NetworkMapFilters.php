<?php

namespace App\Domain\SecurityDevices\Data;

use Illuminate\Pagination\Cursor;
use InvalidArgumentException;

/** Whitelisted map query state; permission and canonical ownership checks stay with their owners. */
final readonly class NetworkMapFilters
{
    public function __construct(public ?int $siteId = null, public string $search = '', public ?Cursor $deviceCursor = null, public ?int $anchorId = null, public ?Cursor $neighborCursor = null) {}

    public static function fromInput(array $input): self
    {
        $siteId = null;
        if (array_key_exists('map_site_id', $input)) {
            $value = $input['map_site_id'];
            if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
            $siteId = (int) $value;
        }
        $search = $input['map_search'] ?? '';
        if (! is_string($search) || preg_match('//u', $search) !== 1 || preg_match('/\p{Cc}/u', $search) === 1) {
            throw new InvalidArgumentException('Network map filters are invalid.');
        }
        $search = preg_replace('/\A\s+|\s+\z/u', '', $search);
        if (mb_strlen($search, 'UTF-8') > 200) {
            throw new InvalidArgumentException('Network map filters are invalid.');
        }
        $cursor = null;
        if (array_key_exists('map_device_cursor', $input)) {
            $encoded = $input['map_device_cursor'];
            if (! is_string($encoded) || $encoded === '' || strlen($encoded) > 128 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            try {
                $payload = $decoded === false ? null : json_decode($decoded, true, 3, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
            if (! is_array($payload) || array_keys($payload) !== ['id', '_pointsToNextItems']
                || ! is_int($payload['id']) || $payload['id'] < 1 || ! is_bool($payload['_pointsToNextItems'])) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
            $cursor = new Cursor(['id' => $payload['id']], $payload['_pointsToNextItems']);
            if ($cursor->encode() !== $encoded) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
        }

        $anchorId = null;
        if (array_key_exists('map_anchor_id', $input)) {
            $value = $input['map_anchor_id'];
            if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('Network map filters are invalid.');
            }
            $anchorId = (int) $value;
        }
        if (($anchorId === null && array_key_exists('map_neighbor_cursor', $input))
            || ($anchorId !== null && ($search !== '' || array_key_exists('map_device_cursor', $input) || array_key_exists('topology_cursor', $input)))) {
            throw new InvalidArgumentException('Network map filters are invalid.');
        }
        // Reuse the exact bounded canonical ID seek decoder, without accepting other query state.
        $neighborCursor = array_key_exists('map_neighbor_cursor', $input)
            ? self::fromInput(['map_device_cursor' => $input['map_neighbor_cursor']])->deviceCursor : null;

        return new self($siteId, $search, $cursor, $anchorId, $neighborCursor);
    }

    public function withDeviceCursor(?Cursor $cursor): self
    {
        return new self($this->siteId, $this->search, $cursor, $this->anchorId, $this->neighborCursor);
    }

    public function withNeighborCursor(?Cursor $cursor): self
    {
        return new self($this->siteId, '', null, $this->anchorId, $cursor);
    }

    public static function exploreHref(int $deviceId, int $siteId): string
    {
        return (new self($siteId, anchorId: $deviceId))->href();
    }

    /** History preserves effective inventory position; inventory links omit history by passing no history cursor. */
    public function href(?Cursor $historyCursor = null): string
    {
        $query = ['tab' => 'map'];
        if ($this->siteId !== null) {
            $query['map_site_id'] = $this->siteId;
        }
        if ($this->search !== '') {
            $query['map_search'] = $this->search;
        }
        if ($this->deviceCursor !== null) {
            $query['map_device_cursor'] = $this->deviceCursor->encode();
        }
        if ($this->anchorId !== null) {
            $query['map_anchor_id'] = $this->anchorId;
            if ($this->neighborCursor !== null) {
                $query['map_neighbor_cursor'] = $this->neighborCursor->encode();
            }
        }
        if ($historyCursor !== null) {
            $query['topology_cursor'] = $historyCursor->encode();
        }

        return '/security-devices/network-it?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function pageKey(): string
    {
        return $this->siteId === null && $this->search === '' && $this->deviceCursor === null && $this->anchorId === null ? 'latest' : hash('sha256', $this->href());
    }
}
