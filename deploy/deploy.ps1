# Быстрый деплoy модуля draxter.aichat на Bitrix-сайт по SSH (без ISPmanager).
# Требуется: OpenSSH-клиент (Windows 10+) и SSH-доступ к хостингу.
#
# Один раз:
#   1. copy deploy\deploy.config.example.ps1 deploy\deploy.config.ps1
#   2. Заполните SshHost и RemoteRoot
#   3. Добавьте SSH-ключ в ISPmanager → SSH-ключи (или используйте пароль)
#
# Деплой:
#   powershell -ExecutionPolicy Bypass -File deploy\deploy.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path $PSScriptRoot -Parent
$ConfigPath = Join-Path $PSScriptRoot "deploy.config.ps1"

if (-not (Test-Path $ConfigPath)) {
    Write-Host "Создайте deploy\deploy.config.ps1 из deploy.config.example.ps1" -ForegroundColor Yellow
    exit 1
}

. $ConfigPath

$sshHost = $DeployConfig.SshHost
$remoteRoot = ($DeployConfig.RemoteRoot -replace '/+$', '')
$sshPort = if ($DeployConfig.SshPort) { [int]$DeployConfig.SshPort } else { 22 }
$identity = $DeployConfig.IdentityFile

if ([string]::IsNullOrWhiteSpace($sshHost) -or [string]::IsNullOrWhiteSpace($remoteRoot)) {
    throw "Укажите SshHost и RemoteRoot в deploy.config.ps1"
}

$scp = Get-Command scp -ErrorAction SilentlyContinue
$ssh = Get-Command ssh -ErrorAction SilentlyContinue
if (-not $scp -or -not $ssh) {
    throw "Не найден scp/ssh. Включите: Параметры Windows → Приложения → Доп. компоненты → OpenSSH Client"
}

function Invoke-ScpRecursive {
    param(
        [string]$LocalPath,
        [string]$RemotePath
    )
    $args = @("-P", $sshPort, "-r")
    if ($identity -and (Test-Path $identity)) {
        $args += @("-i", $identity)
    }
    $args += @("$LocalPath", "${sshHost}:$RemotePath")
    Write-Host ">> scp $($args -join ' ')" -ForegroundColor Cyan
    & scp @args
    if ($LASTEXITCODE -ne 0) {
        throw "scp завершился с кодом $LASTEXITCODE"
    }
}

function Invoke-Ssh {
    param([string]$Command)
    $args = @("-p", $sshPort)
    if ($identity -and (Test-Path $identity)) {
        $args += @("-i", $identity)
    }
    $args += @($sshHost, $Command)
    & ssh @args
}

Write-Host "Деплой draxter.aichat → $sshHost : $remoteRoot" -ForegroundColor Green

$paths = @(
    @{
        Local  = Join-Path $Root "local\modules\draxter.aichat"
        Remote = "$remoteRoot/local/modules/draxter.aichat"
    },
    @{
        Local  = Join-Path $Root "local\components\draxter"
        Remote = "$remoteRoot/local/components/draxter"
    },
    @{
        Local  = Join-Path $Root "local\ajax\draxter_aichat.php"
        Remote = "$remoteRoot/local/ajax/draxter_aichat.php"
    }
)

foreach ($p in $paths) {
    if (-not (Test-Path $p.Local)) {
        throw "Не найден локальный путь: $($p.Local)"
    }
}

# Создать каталоги на сервере
Invoke-Ssh "mkdir -p '$remoteRoot/local/modules' '$remoteRoot/local/components' '$remoteRoot/local/ajax'"

Invoke-ScpRecursive -LocalPath $paths[0].Local -RemotePath $paths[0].Remote
Invoke-ScpRecursive -LocalPath $paths[1].Local -RemotePath $paths[1].Remote
Invoke-ScpRecursive -LocalPath $paths[2].Local -RemotePath $paths[2].Remote

Write-Host ""
Write-Host "Готово. На сайте:" -ForegroundColor Green
Write-Host "  1. Настройки → Настройки модулей → draxter.aichat (если менялись опции)"
Write-Host "  2. Настройки → Производительность → Очистить кэш (или Ctrl+F5 на странице)"
Write-Host "  3. Проверка: https://ВАШ-ДОМЕН/local/ajax/draxter_aichat.php?action=health"
Write-Host ""
Write-Host "config/aichat.config.php на сервер НЕ копируется (секреты остаются на хостинге)."
