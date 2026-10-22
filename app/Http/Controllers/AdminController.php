<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboard;
use Illuminate\Contracts\View\View;

/**
 * Panel del subastador (RF-18): pujas por minuto, últimas acciones y
 * consultas lentas. Solo lectura y solo con el rol de subastador.
 */
class AdminController extends Controller
{
    public function __invoke(AdminDashboard $dashboard): View
    {
        return view('auction.admin', [
            'isHost' => true,
            'viewer' => null,
            'bidsPerMinute' => $dashboard->bidsPerMinute(),
            'actions' => $dashboard->recentActions(),
            'slowQueries' => $dashboard->slowQueries(),
            'slowMs' => config('auction.sql.slow_ms'),
        ]);
    }
}
