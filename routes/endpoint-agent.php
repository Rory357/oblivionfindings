<?php

use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointAgentController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointRestartCommandController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointServiceCommandController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointSoftwareCommandController;
use App\Domain\SecurityDevices\Endpoints\Http\Middleware\EndpointTransport;
use App\Http\Controllers\It\ItRemoteSessionController;
use App\Http\Controllers\Obli\ObliComputerContextController;
use Illuminate\Support\Facades\Route;

Route::prefix('endpoints/agent')->name('api.endpoints.agent.')->middleware(EndpointTransport::class)->group(function (): void {
    Route::post('obli/ticket-computer-context', [ObliComputerContextController::class, 'confirm'])->middleware('throttle:30,1')->name('obli.computer-context.confirm');
    Route::post('enrol', [EndpointAgentController::class, 'enrol'])->middleware('throttle:10,1')->name('enrol');
    $commands = EndpointServiceCommandController::class;
    Route::get('commands', [$commands, 'poll'])->name('commands.poll');
    Route::post('commands/{attempt}/claim', [$commands, 'claim'])->whereUuid('attempt')->name('commands.claim');
    Route::post('commands/{attempt}/result', [$commands, 'result'])->whereUuid('attempt')->name('commands.result');
    $restarts = EndpointRestartCommandController::class;
    Route::get('restarts', [$restarts, 'poll'])->name('restarts.poll');
    Route::post('restarts/{attempt}/claim', [$restarts, 'claim'])->whereUuid('attempt')->name('restarts.claim');
    Route::post('restarts/{attempt}/result', [$restarts, 'result'])->whereUuid('attempt')->name('restarts.result');
    $software = EndpointSoftwareCommandController::class;
    Route::get('software', [$software, 'poll'])->name('software.poll');
    Route::get('software/{attempt}/artifact', [$software, 'artifact'])->whereUuid('attempt')->name('software.artifact');
    Route::post('software/{attempt}/claim', [$software, 'claim'])->whereUuid('attempt')->name('software.claim');
    Route::post('software/{attempt}/result', [$software, 'result'])->whereUuid('attempt')->name('software.result');
    Route::post('heartbeat', [EndpointAgentController::class, 'heartbeat'])->name('heartbeat');
});

Route::prefix('endpoints/agent/remote')->name('api.endpoints.agent.remote.')->middleware(EndpointTransport::class)->group(function (): void {
    $controller = ItRemoteSessionController::class;
    Route::get('offer', [$controller, 'offer'])->name('offer');
    Route::post('{session}/decision', [$controller, 'decide'])->whereUuid('session')->name('decision');
    Route::post('{session}/lease', [$controller, 'lease'])->whereUuid('session')->name('lease');
    Route::get('{session}/relay', [$controller, 'agentRelay'])->whereUuid('session')->name('relay');
    Route::get('{session}/media', [$controller, 'agentMedia'])->whereUuid('session')->name('media');
    Route::post('{session}/media/offer', [$controller, 'mediaOffer'])->whereUuid('session')->name('media.offer');
    Route::post('{session}/stop', [$controller, 'agentStop'])->whereUuid('session')->name('stop');
});
