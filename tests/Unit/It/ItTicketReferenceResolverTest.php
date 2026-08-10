<?php

use App\Domain\It\ItTicketReferenceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->originalConnection = DB::getDefaultConnection();
    $this->databasePath = tempnam(sys_get_temp_dir(), 'oblivion-ticket-reference-');
    if ($this->databasePath === false) {
        throw new RuntimeException('Could not create a temporary ticket reference database.');
    }

    config()->set('database.connections.ticket_reference_test', [
        'driver' => 'sqlite',
        'database' => $this->databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('ticket_reference_test');
    DB::setDefaultConnection('ticket_reference_test');

    Schema::create('it_tickets', function (Blueprint $table): void {
        $table->id();
        $table->string('reference')->nullable();
    });
});

afterEach(function (): void {
    DB::setDefaultConnection($this->originalConnection);
    DB::disconnect('ticket_reference_test');
    @unlink($this->databasePath);
});

it('fails closed when a rolling deployment still contains an ambiguous ticket reference', function (): void {
    DB::table('it_tickets')->insert([
        ['reference' => 'IT-900003'],
        ['reference' => 'IT-900003'],
    ]);

    $resolution = app(ItTicketReferenceResolver::class)->resolve('IT-900003');

    expect($resolution['ticket'])->toBeNull()
        ->and($resolution['failure'])->toBe('reference_ambiguous');
});

it('distinguishes one canonical ticket from a missing reference', function (): void {
    DB::table('it_tickets')->insert(['reference' => 'IT-900004']);

    $resolved = app(ItTicketReferenceResolver::class)->resolve('IT-900004');
    $missing = app(ItTicketReferenceResolver::class)->resolve('IT-999999');

    expect($resolved['ticket']?->getKey())->toBe(1)
        ->and($resolved['failure'])->toBeNull()
        ->and($missing)->toBe([
            'ticket' => null,
            'failure' => 'reference_not_found',
        ]);
});
