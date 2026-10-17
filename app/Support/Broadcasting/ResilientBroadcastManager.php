<?php

namespace App\Support\Broadcasting;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;

/**
 * Envuelve cada emisor resuelto en `ResilientBroadcaster`: cualquier fallo de
 * difusión se registra y no escala a la petición que ya confirmó su acción.
 */
class ResilientBroadcastManager extends BroadcastManager
{
    public function driver($name = null): Broadcaster
    {
        return new ResilientBroadcaster(parent::driver($name));
    }
}
