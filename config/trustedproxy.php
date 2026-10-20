<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confianza
    |--------------------------------------------------------------------------
    |
    | Detrás de un túnel (cloudflared, ngrok) o de un proxy inverso, la petición
    | llega desde el agente local con cabeceras `X-Forwarded-*`. Sin confiar en
    | él, Laravel arma las URLs de los assets con `http://` en una página
    | `https://` (el navegador las bloquea por contenido mixto) y todos los
    | límites por IP se comparten, porque cada visitante aparece como
    | 127.0.0.1.
    |
    | Lista separada por comas (`127.0.0.1` para un túnel en la misma máquina).
    | Vacío = no se confía en nadie: así un cliente expuesto directamente no
    | puede falsificar su IP con `X-Forwarded-For`.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
