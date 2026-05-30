# Локальный сервер чата: http://127.0.0.1:8080
$ErrorActionPreference = "Stop"
$Root = Split-Path $PSScriptRoot -Parent
$ToolsPhp = Join-Path $Root "tools\php\php.exe"

function Get-PhpExe {
    if (Test-Path $ToolsPhp) {
        return $ToolsPhp
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }
    return $null
}

function Install-PortablePhp {
    $zipUrl = "https://windows.php.net/downloads/releases/php-8.5.6-nts-Win32-vs17-x64.zip"
    $dest = Join-Path $Root "tools\php"
    $zipPath = Join-Path $env:TEMP "php-portable.zip"

    Write-Host "Скачиваю PHP 8.3..."
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath -UseBasicParsing
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Expand-Archive -Path $zipPath -DestinationPath $dest -Force
    Remove-Item $zipPath -Force

    $iniProd = Join-Path $dest "php.ini-production"
    $ini = Join-Path $dest "php.ini"
    if (Test-Path $iniProd) {
        Copy-Item $iniProd $ini -Force
        Add-Content $ini "`nextension_dir = `"ext`"`n"
        Add-Content $ini "extension=curl`n"
        Add-Content $ini "extension=mbstring`n"
        Add-Content $ini "extension=openssl`n"
    }

    if (-not (Test-Path $ToolsPhp)) {
        throw "PHP не найден после распаковки в tools/php/"
    }
}

$php = Get-PhpExe
if (-not $php) {
    Install-PortablePhp
    $php = Get-PhpExe
}

Set-Location $PSScriptRoot
Write-Host "PHP: $php"
Write-Host "Чат: http://127.0.0.1:8080/"
& $php -S 127.0.0.1:8080 -t public router.php
