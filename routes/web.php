<?php

use App\Http\Controllers\AuctionController;
use Illuminate\Support\Facades\Route;

Route::controller(AuctionController::class)->group(function () {
    Route::get('/', 'index')->name('auction.index');
    Route::get('/emisor', 'emisor')->name('auction.emisor');
    Route::get('/receptor', 'receptor')->name('auction.receptor');
});
