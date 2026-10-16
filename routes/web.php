<?php

use App\Http\Controllers\AuctionController;
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
    ->middleware('throttle:10,1')
    ->name('auction.join');

Route::post('/host/login', [HostAuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('host.login');

Route::post('/host/logout', [HostAuthController::class, 'logout'])
    ->middleware('host')
    ->name('host.logout');

// Emisor de eventos de prueba: solo se registra en local, así que en
// producción la ruta no existe.
if (app()->environment('local')) {
    Route::post('/dev/broadcast', BroadcastController::class)->name('dev.broadcast');
}
