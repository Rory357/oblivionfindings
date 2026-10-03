<?php

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/*
 * P01 foundation C3: the dose-slot outcome is written from the
 * administration model's own events, so it can only be bypassed by a write
 * that skips them. These guards pin every place an administration is
 * persisted, and the only writers of the slot table.
 */

function doseSlotAppSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    $sources = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($sources);

    return $sources;
}

/** Match mutations of the slot receiver, not an unrelated write elsewhere in a file. */
function doseSlotSourceWritesTable(string $source): bool
{
    if (! str_contains($source, 'MedicationDoseSlot') && ! str_contains($source, 'medication_dose_slots')) {
        return false;
    }

    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
    $resolver = new NodeTraverser;
    $resolver->addVisitor(new NameResolver);
    $nodes = $resolver->traverse($nodes);
    $detector = new class extends NodeVisitorAbstract
    {
        public bool $writes = false;

        private array $bindings = [[]];

        private const MUTATIONS = ['insert', 'insertOrIgnore', 'insertUsing', 'upsert', 'update', 'updateOrCreate', 'firstOrCreate', 'create', 'forceCreate', 'save', 'delete', 'forceDelete', 'truncate', 'increment', 'decrement', 'saveQuietly', 'updateQuietly', 'deleteQuietly'];

        public function enterNode(Node $node): null
        {
            if ($node instanceof FunctionLike) {
                $this->bindings[] = $node instanceof Closure || $node instanceof ArrowFunction
                    ? $this->bindings[array_key_last($this->bindings)]
                    : [];
                foreach ($node->getParams() as $parameter) {
                    $this->bind($parameter->var, $this->slotType($parameter->type));
                }
            }
            if ($node instanceof Assign) {
                $this->bind($node->var, $this->slotReceiver($node->expr));
            }
            if ($node instanceof Foreach_) {
                $this->bind($node->valueVar, $this->slotReceiver($node->expr));
            }
            if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                && $node->name instanceof Identifier
                && in_array($node->name->toString(), self::MUTATIONS, true)
                && $this->slotReceiver($node->var)) {
                $this->writes = true;
            }
            if ($node instanceof StaticCall
                && $node->name instanceof Identifier
                && in_array($node->name->toString(), self::MUTATIONS, true)
                && $this->slotType($node->class)) {
                $this->writes = true;
            }

            return null;
        }

        public function leaveNode(Node $node): null
        {
            if ($node instanceof FunctionLike) {
                array_pop($this->bindings);
            }

            return null;
        }

        private function bind(Node $variable, bool $slot): void
        {
            if ($variable instanceof Variable && is_string($variable->name)) {
                $scope = array_key_last($this->bindings);
                // Retain possible slot identity across conditional assignments.
                $this->bindings[$scope][$variable->name] = $slot || ($this->bindings[$scope][$variable->name] ?? false);
            }
        }

        private function slotType(mixed $type): bool
        {
            if ($type instanceof NullableType) {
                $type = $type->type;
            }

            return $type instanceof Name
                && in_array(strtolower($type->toString()), ['app\models\medicationdoseslot', 'medicationdoseslot'], true);
        }

        private function slotReceiver(Node $node): bool
        {
            if ($node instanceof Variable && is_string($node->name)) {
                return $this->bindings[array_key_last($this->bindings)][$node->name] ?? false;
            }
            if ($node instanceof New_) {
                return $this->slotType($node->class);
            }
            if ($node instanceof StaticCall && $this->slotType($node->class)) {
                return true;
            }
            if (($node instanceof StaticCall || $node instanceof MethodCall)
                && $node->name instanceof Identifier
                && $node->name->toString() === 'table'
                && ($node->args[0]->value ?? null) instanceof String_
                && $node->args[0]->value->value === 'medication_dose_slots') {
                return true;
            }
            if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                return $this->slotReceiver($node->var);
            }

            return false;
        }
    };
    $traverser = new NodeTraverser;
    $traverser->addVisitor($detector);
    $traverser->traverse($nodes);

    return $detector->writes;
}

it('syncs the slot outcome from every administration model event that can change evidence', function (): void {
    $model = (string) file_get_contents(dirname(__DIR__, 2).'/app/Models/ClientMedicationAdministration.php');

    expect($model)->toContain(
        'app(DoseSlotOutcomeWriter::class)->syncFor($administration)',
        'static::saving($lockOrder);',
        'static::deleting($lockOrder);',
        'static::restoring($lockOrder);',
        'static::forceDeleting($lockOrder);',
        'static::saved($sync);',
        'static::deleted($sync);',
        'static::restored($sync);',
        'static::forceDeleted($sync);',
        'app(DoseSlotOutcomeWriter::class)->lockOrderOf($administration)',
    );
});

it('locks the order row before any administration row in the outcome writer', function (): void {
    $writer = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Medication/DoseSlots/DoseSlotOutcomeWriter.php');
    $sync = substr($writer, (int) strpos($writer, 'public function syncFor('));
    $rootRead = substr($sync, (int) strpos($sync, '$root = '), (int) strpos($sync, '?? ($rootId') - (int) strpos($sync, '$root = '));
    $orderLock = strpos($sync, 'whereKey($root->client_medication_id)->lockForUpdate()');
    $evidenceLock = strpos($sync, '$this->lockEvidence(');

    expect($rootRead)->not->toContain('lockForUpdate')
        ->and($orderLock)->toBeInt()
        ->and($evidenceLock)->toBeInt()
        ->and($orderLock < $evidenceLock)->toBeTrue();
});

it('persists administrations only through the recording service and the two correction paths', function (): void {
    $creators = [];
    $replicators = [];
    $rawWrites = [];
    foreach (doseSlotAppSources() as $path => $source) {
        if (str_contains($source, 'new ClientMedicationAdministration;')
            && preg_match('/new ClientMedicationAdministration;\s*\n\s*\$\w+->(?!setAttribute\(\$\w+->getKeyName\(\))/', $source) === 1) {
            $creators[] = $path;
        }
        if (preg_match('/ClientMedicationAdministration::(?:query\(\)\s*->\s*)?(?:create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate)\(/', $source) === 1) {
            $creators[] = $path;
        }
        if (preg_match('/\$\w*[aA]dministration\w*->replicate\(/', $source) === 1) {
            $replicators[] = $path;
        }
        if (preg_match("/table\\(\\s*'client_medication_administrations'\\s*\\)[^;]*->(?:insert|update|delete|upsert|truncate)\\(/s", $source) === 1) {
            $rawWrites[] = $path;
        }
    }

    expect(array_values(array_unique($creators)))->toBe(['app/Services/EnhancedMarService.php'])
        ->and($replicators)->toBe([
            'app/Http/Controllers/Api/MedicationsApiController.php',
            'app/Http/Controllers/MedicationAdministrationCorrectionController.php',
        ])
        ->and($rawWrites)->toBe([]);
});

it('writes the slot table only from the generator, the outcome writer and the backfill', function (): void {
    $writers = [];
    foreach (doseSlotAppSources() as $path => $source) {
        if (doseSlotSourceWritesTable($source)) {

            $writers[] = $path;
        }
    }

    expect($writers)->toBe([
        // C5: adds missing past slots (reconstructed) and fills missing outcomes only.
        'app/Services/Medication/DoseSlots/DoseSlotBackfill.php',
        'app/Services/Medication/DoseSlots/DoseSlotGenerator.php',
        'app/Services/Medication/DoseSlots/DoseSlotOutcomeWriter.php',
    ]);
});

it('detects actual slot mutations from chains, aliases and typed instances', function (string $statement): void {
    $source = '<?php use App\Models\MedicationDoseSlot; use Illuminate\Support\Facades\DB; '.$statement;

    expect(doseSlotSourceWritesTable($source))->toBeTrue();
})->with([
    'model query update' => "MedicationDoseSlot::query()->whereKey(1)->update(['outcome' => 'given']);",
    'static model create' => "MedicationDoseSlot::create(['outcome' => 'given']);",
    'raw table delete' => "DB::table('medication_dose_slots')->where('id', 1)->delete();",
    'connection table insert' => 'DB::connection()->table("medication_dose_slots")->insert([]);',
    'builder alias' => '$query = MedicationDoseSlot::query(); $query->whereKey(1)->delete();',
    'new model instance' => '$slot = new MedicationDoseSlot; $slot->save();',
    'typed instance' => 'function mutate(MedicationDoseSlot $slot) { $slot->saveQuietly(); }',
    'collection element' => '$slots = MedicationDoseSlot::query()->get(); foreach ($slots as $slot) { $slot->delete(); }',
    'captured builder' => '$query = MedicationDoseSlot::query(); $callback = function () use ($query) { $query->update([]); };',
    'import alias' => 'use App\Models\MedicationDoseSlot as Slot; Slot::query()->update([]);',
]);

it('does not confuse slot reads with unrelated model writes', function (string $statement): void {
    $source = '<?php use App\Models\MedicationDoseSlot; '.$statement;

    expect(doseSlotSourceWritesTable($source))->toBeFalse();
})->with([
    'read then different model write' => '$next = MedicationDoseSlot::query()->first(); MedicationReconciliation::query()->create([]);',
    'locking read then different instance write' => '$next = MedicationDoseSlot::query()->lockForUpdate()->get(); $transport->save();',
    'same variable in separate methods' => 'class Example { function read() { $row = MedicationDoseSlot::query()->first(); } function write() { $row = Other::query()->first(); $row->save(); } }',
    'unrelated write in a query callback' => 'MedicationDoseSlot::query()->where(function ($query) { Other::query()->update([]); })->exists();',
    'comment and string' => '// MedicationDoseSlot::query()->update([]);'.PHP_EOL.'$text = "MedicationDoseSlot::query()->delete()"; Other::query()->create([]);',
]);
