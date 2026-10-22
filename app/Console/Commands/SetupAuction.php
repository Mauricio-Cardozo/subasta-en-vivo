<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Completa el `.env` para correr la subasta en una máquina nueva.
 *
 * `.env.example` trae las claves de Reverb y el PIN vacíos (RNF-08: el
 * repositorio no lleva secretos). Sin ellos Echo no conecta (la URL del
 * WebSocket queda sin key) y nadie puede entrar como subastador. Este comando
 * genera solo los valores que están vacíos: nunca pisa uno ya configurado.
 */
class SetupAuction extends Command
{
    protected $signature = 'auction:setup';

    protected $description = 'Genera las claves de Reverb y el PIN del subastador que falten en .env';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->error('No hay .env: copiá .env.example primero (cp .env.example .env).');

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);
        $pin = (string) random_int(100_000, 999_999);

        $generated = [
            'REVERB_APP_ID' => (string) random_int(100_000, 999_999),
            'REVERB_APP_KEY' => Str::lower(Str::random(20)),
            'REVERB_APP_SECRET' => Str::lower(Str::random(20)),
            'AUCTION_HOST_PIN' => $pin,
        ];

        $filled = [];

        foreach ($generated as $key => $value) {
            // Solo `CLAVE=` sin valor (o con comillas vacías) al principio de línea.
            $pattern = '/^'.preg_quote($key, '/').'=(""|\'\')?[ \t]*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, "{$key}={$value}", $contents, 1);
                $filled[] = $key;
            }
        }

        if ($filled === []) {
            $this->info('El .env ya estaba completo: no se cambió nada.');

            return self::SUCCESS;
        }

        file_put_contents($path, $contents);

        $this->info('Completado en .env: '.implode(', ', $filled).'.');

        if (in_array('AUCTION_HOST_PIN', $filled, true)) {
            $this->line("PIN del subastador: <comment>{$pin}</comment>");
        }

        $this->line('Recompilá los assets (npm run build) para que el navegador use la clave nueva.');

        return self::SUCCESS;
    }
}
