<?php

use App\Http\Controllers\AuctionController;
use App\Http\Controllers\Dev\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::controller(AuctionController::class)->group(function () {
    Route::get('/', 'index')->name('auction.index');
    Route::get('/emisor', 'emisor')->name('auction.emisor');
    Route::get('/receptor', 'receptor')->name('auction.receptor');
});

// Emisor de eventos de prueba: solo se registra en local, así que en
// producción la ruta no existe.
if (app()->environment('local')) {
    Route::post('/dev/broadcast', BroadcastController::class)->name('dev.broadcast');
}
