<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class AuctionController extends Controller
{
    /**
     * Selector de rol: punto de entrada a la subasta en vivo.
     */
    public function index(): View
    {
        return view('auction.index');
    }

    /**
     * Pantalla del subastador (emisor).
     */
    public function emisor(): View
    {
        return view('auction.emisor');
    }

    /**
     * Pantalla del pujador (receptor).
     */
    public function receptor(): View
    {
        return view('auction.receptor');
    }
}
