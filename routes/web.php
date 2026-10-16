<?php

use App\Http\Controllers\AuctionController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\Dev\BroadcastController;
use App\Http\Controllers\HostAuthController;
use App\Http\Controllers\ParticipantController;
use Illuminate\Support\Facades\Route;

Route::controller(AuctionController::class)->group(function () {
    Route::get('/', 'index')->name('auction.index');
    Route::get('/emisor', 'emisor')->name('auction.emisor');
    Route::get('/receptor', 'receptor')->name('auction.receptor');
});

// Identidad: un pujador se une con un nombre y el subastador inicia sesión con
// el PIN. Ambas acciones renuevan la sesión del servidor (5.5).
Route::post('/join', [ParticipantController::class, 'store'])
    ->middleware('throttle:join')
    ->name('auction.join');

Route::post('/host/login', [HostAuthController::class, 'login'])
    ->middleware('throttle:host-login')
    ->name('host.login');

Route::post('/host/logout', [HostAuthController::class, 'logout'])
    ->middleware('host')
    ->name('host.logout');

// Estado y acciones de la subasta.
Route::get('/auction/state', [AuctionController::class, 'state'])->name('auction.state');

Route::post('/auction/lots', [AuctionController::class, 'openLot'])
    ->middleware(['throttle:lot-opens', 'host'])
    ->name('auction.lots.store');

// Cierre manual del subastador (RF-03).
Route::post('/auction/lots/close', [AuctionController::class, 'closeLot'])
    ->middleware(['throttle:lot-closes', 'host'])
    ->name('auction.lots.close');

// Puja de un participante con identidad (RF-04).
Route::post('/auction/bids', [BidController::class, 'store'])
    ->middleware(['throttle:bids', 'participant'])
    ->name('auction.bids.store');

// Emisor de eventos de prueba: solo se registra en local, así que en
// producción la ruta no existe.
if (app()->environment('local')) {
    Route::post('/dev/broadcast', BroadcastController::class)->name('dev.broadcast');
}
