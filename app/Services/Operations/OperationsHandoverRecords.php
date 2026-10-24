<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Checked selected writer fields, not a full-column native AFTER certificate. */
final class OperationsHandoverRecords
{
    public static function rows(string $table, string $column, array $ids): array
    {
        if (! preg_match('/^[a-z_]+$/D', $table) || ! preg_match('/^[a-z_]+$/D', $column)) {
            throw new \LogicException('Invalid internal handover read.');
        }
        if ($ids === []) { return []; }
        // Native reads do not dispatch Eloquent retrieved or QueryExecuted callbacks.
        $pdo = DB::connection()->getPdo();
        if (! $pdo->inTransaction()) { throw new \LogicException('A retained handover transaction is required.'); }
        $sql = 'SELECT * FROM `'.$table.'` WHERE `'.$column.'` IN ('.implode(',', array_fill(0, count($ids), '?')).') FOR SHARE NOWAIT';
        $query = $pdo->prepare($sql);
        if (! $query || ! $query->execute(array_values($ids))) { throw new \RuntimeException('Current handover evidence is unavailable.'); }
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        usort($rows, fn (array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        return $rows;
    }

    public static function model(string $class, array $row): Model
    {
        $model = new $class;
        $model->setConnection(DB::connection()->getName());
        $model->setRawAttributes($row, true);
        $model->exists = true;
        return $model; // newFromBuilder would fire retrieved; do not use it here.
    }

    public static function equalRow(string $table, array $equals): ?array
    {
        if (! preg_match('/^[a-z_]+$/D', $table) || $equals === []) { throw new \LogicException('Invalid internal handover read.'); }
        foreach (array_keys($equals) as $column) {
            if (! preg_match('/^[a-z_]+$/D', $column)) { throw new \LogicException('Invalid internal handover read.'); }
        }
        $pdo = DB::connection()->getPdo();
        if (! $pdo->inTransaction()) { throw new \LogicException('A retained handover transaction is required.'); }
        $where = array_map(fn ($column) => '`'.$column.'` = ?', array_keys($equals));
        $query = $pdo->prepare('SELECT * FROM `'.$table.'` WHERE '.implode(' AND ', $where).' FOR SHARE NOWAIT');
        if (! $query || ! $query->execute(array_values($equals))) { throw new \RuntimeException('Current handover evidence is unavailable.'); }
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous original handover attempt.'); }
        return $rows[0] ?? null;
    }

    public static function projection(Model $model): array
    {
        $result = [];
        foreach (array_keys($model->getAttributes()) as $field) {
            $value = $model->getAttribute($field);
            if ($value instanceof \DateTimeInterface) { $value = $value->format('Y-m-d H:i:s'); }
            elseif ($value !== null && ($field === 'id' || str_ends_with($field, '_id') || str_ends_with($field, '_by'))) { $value = (int) $value; }
            // Maps sort canonically, while list order and scalar types remain significant.
            $result[$field] = json_decode(OperationsHandoverIntent::json($value), true, flags: JSON_THROW_ON_ERROR);
        }
        ksort($result);
        return $result;
    }

    public static function write(Model $model): array
    {
        if ($model->getConnection() !== DB::connection() || ! DB::connection()->getPdo()->inTransaction()) {
            throw new \LogicException('The checked record is outside its handover connection.');
        }
        $now = now()->startOfSecond();
        $model->setUpdatedAt($now);
        if (! $model->exists) { $model->setCreatedAt($now); }
        $expected = self::projection($model);
        $oldId = $model->exists ? (int) $model->id : null;
        if ($model->save() !== true || ! $model->exists || (int) $model->id < 1 || $model->getConnection() !== DB::connection()) {
            throw new \RuntimeException('The checked handover record was not saved.');
        }
        if ($oldId !== null && (int) $model->id !== $oldId) { throw new \RuntimeException('The checked record identity changed.'); }
        $expected['id'] = (int) $model->id;
        $proof = ['class' => $model::class, 'table' => $model->getTable(), 'id' => (int) $model->id, 'expected' => $expected];
        self::verify($proof);
        return $proof;
    }

    public static function verify(array $proof): void
    {
        $rows = self::rows($proof['table'], 'id', [$proof['id']]);
        if (count($rows) !== 1) { throw new \RuntimeException('The checked record is missing.'); }
        $actual = self::projection(self::model($proof['class'], $rows[0]));
        foreach ($proof['expected'] as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) { throw new \RuntimeException('A checked handover field changed.'); }
        }
    }
}
