<#
.SYNOPSIS
    Demo portable de Subasta en Vivo para Windows, sin instalar nada en el sistema.

.DESCRIPTION
    Baja PHP 8.4, Node.js 22 y Composer en versiones portables a una carpeta
    `demo-tools` junto al proyecto, prepara la app y la levanta. No toca el PATH
    del sistema ni el registro: el PATH se cambia solo dentro de las ventanas que
    abre este script, y los cachés de Composer y npm quedan en `demo-tools`.
    Al terminar, `clean` borra el proyecto y las herramientas.

    Uso (desde la carpeta del proyecto):
        powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 setup
        powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 start
        powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 stop
        powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 clean

    `-ExecutionPolicy Bypass` vale solo para ese comando: no cambia la
    configuración de Windows.

    Compatible con Windows PowerShell 5.1 (el que trae Windows).
#>
param(
    [ValidateSet('setup', 'start', 'stop', 'clean')]
    [string] $Action = 'setup'
)

$ErrorActionPreference = 'Stop'
# La barra de progreso de Invoke-WebRequest hace muy lentas las descargas en 5.1.
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Project = Split-Path -Parent $PSScriptRoot
$Root = Split-Path -Parent $Project
$Tools = Join-Path $Root 'demo-tools'
$Downloads = Join-Path $Tools 'downloads'
$PhpDir = Join-Path $Tools 'php'
$NodeDir = Join-Path $Tools 'node'
$Composer = Join-Path $Tools 'composer.phar'
$AppUrl = 'http://127.0.0.1:8000'

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Fail([string] $Message) {
    Write-Host ''
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

# Comandos que cada ventana corre antes de usar php, node o composer: PATH y
# cachés solo para esa ventana.
function Get-EnvPrelude {
    return "`$env:PATH = '$PhpDir;$NodeDir;' + `$env:PATH; " +
        "`$env:COMPOSER_HOME = '$Tools\cache\composer'; " +
        "`$env:npm_config_cache = '$Tools\cache\npm'; " +
        "Set-Location '$Project'"
}

function Use-Tools {
    $env:PATH = "$PhpDir;$NodeDir;" + $env:PATH
    $env:COMPOSER_HOME = Join-Path $Tools 'cache\composer'
    $env:npm_config_cache = Join-Path $Tools 'cache\npm'
    Set-Location $Project
}

# Corre un programa externo y corta si falla (5.1 no lo hace solo).
function Invoke-Checked([string] $What, [scriptblock] $Command) {
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Fail "$What terminó con código $LASTEXITCODE."
    }
}

function Get-Download([string] $Url, [string] $Name, [string] $Sha256) {
    New-Item -ItemType Directory -Force -Path $Downloads | Out-Null
    $file = Join-Path $Downloads $Name

    if (-not (Test-Path $file)) {
        Write-Host "    Bajando $Name..."
        try {
            Invoke-WebRequest -Uri $Url -OutFile $file -UseBasicParsing
        } catch {
            Remove-Item $file -ErrorAction SilentlyContinue
            Fail "No se pudo bajar $Url. Bajalo a mano y guardalo como $file, y volvé a correr setup."
        }
    }

    if ($Sha256) {
        $actual = (Get-FileHash -Algorithm SHA256 -Path $file).Hash
        if ($actual -ne $Sha256.ToUpper()) {
            Remove-Item $file
            Fail "El SHA-256 de $Name no coincide (descarga corrupta). Volvé a correr setup."
        }
    }

    return $file
}

function Install-Php {
    if (Test-Path (Join-Path $PhpDir 'php.exe')) {
        return
    }

    Write-Step 'PHP 8.4 portable'
    $zip = Get-ChildItem $Downloads -Filter 'php-8.4*-nts-Win32-vs17-x64.zip' -ErrorAction SilentlyContinue |
        Select-Object -First 1

    if ($zip) {
        $file = $zip.FullName
        Write-Host "    Uso el zip que ya estaba: $($zip.Name)"
    } else {
        try {
            $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
        } catch {
            Fail ('No se pudo consultar windows.php.net. Bajá a mano el zip "VS17 x64 Non Thread Safe" de PHP 8.4 ' +
                "desde https://windows.php.net/download/ y guardalo en $Downloads, y volvé a correr setup.")
        }
        $build = $releases.'8.4'.'nts-vs17-x64'.zip
        $file = Get-Download "https://windows.php.net/downloads/releases/$($build.path)" $build.path $build.sha256
    }

    Expand-Archive -Path $file -DestinationPath $PhpDir -Force

    # php.ini con las extensiones que necesitan Laravel, Composer y SQLite.
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-development')
    $extensions = 'curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip'
    $ini = $ini | ForEach-Object {
        $line = $_
        if ($line -match '^;\s*extension_dir\s*=\s*"ext"') {
            $line = 'extension_dir = "ext"'
        }
        foreach ($extension in $extensions) {
            if ($line -match "^;extension=$extension\s*$") {
                $line = "extension=$extension"
            }
        }
        $line
    }
    Set-Content -Path (Join-Path $PhpDir 'php.ini') -Value $ini -Encoding ASCII
}

function Install-Node {
    if (Test-Path (Join-Path $NodeDir 'node.exe')) {
        return
    }

    Write-Step 'Node.js 22 portable'
    $sums = (Invoke-WebRequest -Uri 'https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt' -UseBasicParsing).Content
    $line = ($sums -split "`n") | Where-Object { $_ -match 'node-v[\d.]+-win-x64\.zip\s*$' } | Select-Object -First 1
    if (-not $line) {
        Fail 'No encontré el zip de Node 22 para Windows en nodejs.org.'
    }
    $hash, $name = ($line.Trim() -split '\s+')
    $file = Get-Download "https://nodejs.org/dist/latest-v22.x/$name" $name $hash

    $extract = Join-Path $Tools 'node-extract'
    Expand-Archive -Path $file -DestinationPath $extract -Force
    Move-Item -Path (Join-Path $extract ($name -replace '\.zip$', '')) -Destination $NodeDir
    Remove-Item $extract -Recurse -Force
}

function Install-Composer {
    if (Test-Path $Composer) {
        return
    }

    Write-Step 'Composer'
    $hash = $null
    try {
        $hash = ((Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar.sha256' -UseBasicParsing).Content.Trim() -split '\s+')[0]
    } catch {
        Fail ('No se pudo consultar getcomposer.org. Bajá composer.phar a mano desde https://getcomposer.org/download/ ' +
            "y guardalo como $Composer, y volvé a correr setup.")
    }
    $file = Get-Download 'https://getcomposer.org/download/latest-stable/composer.phar' 'composer.phar' $hash
    Copy-Item $file $Composer
}

# Valor de una clave del .env (o $null).
function Get-EnvValue([string] $Key) {
    $line = Get-Content (Join-Path $Project '.env') | Where-Object { $_ -match "^$Key=" } | Select-Object -First 1
    if (-not $line) {
        return $null
    }
    return ($line -replace "^$Key=", '').Trim('"')
}

function Invoke-Setup {
    New-Item -ItemType Directory -Force -Path $Tools | Out-Null

    Install-Php
    Install-Node
    Install-Composer
    Use-Tools

    Write-Step 'Probando PHP'
    & php -v
    if ($LASTEXITCODE -ne 0) {
        Fail ('PHP no arranca. Si Windows menciona VCRUNTIME140.dll, instalá el "Microsoft Visual C++ Redistributable" ' +
            '(x64) desde https://aka.ms/vs/17/release/vc_redist.x64.exe y volvé a correr setup.')
    }
    $modules = & php -m
    foreach ($module in 'pdo_sqlite', 'mbstring', 'openssl') {
        if ($modules -notcontains $module) {
            Fail "PHP no cargó la extensión ${module}: revisá $PhpDir\php.ini."
        }
    }

    Write-Step 'Dependencias de PHP (composer install)'
    Invoke-Checked 'composer install' { & php $Composer install --no-interaction --no-progress }

    Write-Step 'Dependencias de JavaScript (npm ci)'
    Invoke-Checked 'npm ci' { & npm ci --no-audit --no-fund }

    Write-Step 'Configuración (.env)'
    $envFile = Join-Path $Project '.env'
    if (-not (Test-Path $envFile)) {
        Copy-Item (Join-Path $Project '.env.example') $envFile
    }
    if (-not (Get-EnvValue 'APP_KEY')) {
        Invoke-Checked 'key:generate' { & php artisan key:generate --ansi }
    }
    Invoke-Checked 'auction:setup' { & php artisan auction:setup }

    # Reverb en 127.0.0.1: el firewall de Windows no pregunta nada.
    if (-not (Get-EnvValue 'REVERB_SERVER_HOST')) {
        Add-Content -Path $envFile -Value "`nREVERB_SERVER_HOST=127.0.0.1"
    }

    Write-Step 'Base de datos (SQLite)'
    $database = Join-Path $Project 'database\database.sqlite'
    if (-not (Test-Path $database)) {
        New-Item -ItemType File -Path $database | Out-Null
    }
    Invoke-Checked 'migrate' { & php artisan migrate:fresh --seed --force }

    Write-Step 'Compilando los assets (npm run build)'
    Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue
    Invoke-Checked 'npm run build' { & npm run build }

    Write-Host ''
    Write-Host 'Listo. PIN del subastador: ' -NoNewline
    Write-Host (Get-EnvValue 'AUCTION_HOST_PIN') -ForegroundColor Yellow
    Write-Host 'Para levantar la demo:  powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 start'
}

function Start-Window([string] $Title, [string] $Command) {
    $script = "`$Host.UI.RawUI.WindowTitle = '$Title'; " + (Get-EnvPrelude) + "; $Command"
    Start-Process powershell -ArgumentList '-NoExit', '-ExecutionPolicy', 'Bypass', '-Command', $script | Out-Null
}

function Open-Browser([string] $Exe, [string[]] $Arguments) {
    try {
        Start-Process $Exe -ArgumentList $Arguments -ErrorAction Stop | Out-Null
        return $true
    } catch {
        return $false
    }
}

function Invoke-Start {
    if (-not (Test-Path (Join-Path $PhpDir 'php.exe')) -or -not (Test-Path (Join-Path $Project 'vendor'))) {
        Fail 'Falta preparar la demo: corré primero  .\scripts\demo.ps1 setup'
    }

    Write-Step 'Levantando la app y Reverb (dos ventanas nuevas)'
    Start-Window 'Subasta - app (no cerrar)' 'php artisan serve --host=127.0.0.1 --port=8000'
    Start-Window 'Subasta - Reverb (no cerrar)' 'php artisan reverb:start --debug'

    Write-Host '    Esperando que la app responda...'
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        try {
            Invoke-WebRequest -Uri "$AppUrl/up" -UseBasicParsing -TimeoutSec 2 | Out-Null
            $ready = $true
            break
        } catch {
            Start-Sleep -Seconds 1
        }
    }
    if (-not $ready) {
        Fail 'La app no respondió en 60 s. Mirá la ventana "Subasta - app" para ver el error.'
    }

    # Tres sesiones distintas: Chrome normal, Chrome incógnito y Edge (las
    # ventanas de incógnito de Chrome comparten sesión entre sí).
    Write-Step 'Abriendo los navegadores'
    $chrome = Open-Browser 'chrome.exe' @('--new-window', "$AppUrl/emisor")
    if ($chrome) {
        Open-Browser 'chrome.exe' @('--incognito', "$AppUrl/receptor") | Out-Null
    } else {
        Start-Process "$AppUrl/emisor" | Out-Null
    }
    Open-Browser 'msedge.exe' @('--new-window', "$AppUrl/receptor") | Out-Null

    Write-Host ''
    Write-Host "App: $AppUrl   PIN del subastador: " -NoNewline
    Write-Host (Get-EnvValue 'AUCTION_HOST_PIN') -ForegroundColor Yellow
    Write-Host 'Subastador: /emisor en Chrome. Pujadores: /receptor en Chrome incógnito y en Edge.'
    Write-Host 'Para cortar todo:  powershell -ExecutionPolicy Bypass -File .\scripts\demo.ps1 stop'
}

function Invoke-Stop {
    Write-Step 'Cerrando la app y Reverb'
    $processes = Get-Process php, node -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.StartsWith($Tools, [StringComparison]::OrdinalIgnoreCase) }
    if ($processes) {
        $processes | Stop-Process -Force
        Write-Host "    Cerrados: $(@($processes).Count) procesos."
    } else {
        Write-Host '    No había nada corriendo.'
    }
}

function Invoke-Clean {
    Invoke-Stop

    Write-Step 'Borrar todo'
    Write-Host 'Se van a borrar estas carpetas:'
    Write-Host "    $Tools"
    Write-Host "    $Project"
    $answer = Read-Host 'Escribí BORRAR para confirmar'
    if ($answer -cne 'BORRAR') {
        Write-Host 'No se borró nada.'
        return
    }

    # Salir de las carpetas antes de borrarlas.
    Set-Location $env:TEMP
    Remove-Item $Tools -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item $Project -Recurse -Force -ErrorAction SilentlyContinue

    # La carpeta de la demo se borra solo si quedó vacía (nunca otra cosa).
    if ((Test-Path $Root) -and -not (Get-ChildItem $Root -Force)) {
        Remove-Item $Root -Force
    }

    $left = @($Tools, $Project) | Where-Object { Test-Path $_ }
    if ($left) {
        Write-Host "No se pudo borrar: $($left -join ', '). Cerrá las ventanas de la demo y borralo a mano." -ForegroundColor Yellow
    } else {
        Write-Host 'Listo: no quedó nada de la demo. Borrá también el zip del proyecto de la carpeta Descargas.' -ForegroundColor Green
    }
}

switch ($Action) {
    'setup' { Invoke-Setup }
    'start' { Invoke-Start }
    'stop' { Invoke-Stop }
    'clean' { Invoke-Clean }
}
