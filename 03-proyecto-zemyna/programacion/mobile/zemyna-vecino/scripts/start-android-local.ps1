$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$xamppRoot = 'C:\xampp'
$expectedIp = '172.28.57.181'
$apiPath = '/zentrax-project/03-proyecto-zemyna/programacion/backend/api'
$httpApiUrl = "http://127.0.0.1$apiPath/tipos_residuo.php"
$mapQuery = '/contenedores.php?view=map&south=-34.92&north=-34.88&west=-56.19&east=-56.14&zoom=15'
$mysqlAdmin = Join-Path $xamppRoot 'mysql\bin\mysqladmin.exe'
$mysqlServer = Join-Path $xamppRoot 'mysql\bin\mysqld.exe'
$mysqlIni = Join-Path $xamppRoot 'mysql\bin\my.ini'
$apacheServer = Join-Path $xamppRoot 'apache\bin\httpd.exe'
$apacheStartScript = Join-Path $xamppRoot 'apache_start.bat'
$mysqlStartScript = Join-Path $xamppRoot 'mysql_start.bat'
$apacheErrorLog = Join-Path $xamppRoot 'apache\logs\error.log'
$mysqlErrorLog = Join-Path $xamppRoot 'mysql\data\mysql_error.log'

function Stop-WithError([string]$message) {
    Write-Host $message -ForegroundColor Red
    exit 1
}

function Get-PortListeners([int]$port) {
    @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
}

function Get-XamppProcesses([string]$name, [string]$executable) {
    @(Get-CimInstance Win32_Process -Filter "Name = '$name'" -ErrorAction SilentlyContinue |
        Where-Object { $_.ExecutablePath -ieq $executable })
}

function Start-XamppScript([string]$scriptPath) {
    $arguments = '/c ""{0}""' -f $scriptPath
    Start-Process -FilePath $env:ComSpec -ArgumentList $arguments `
        -WorkingDirectory $xamppRoot -PassThru -WindowStyle Hidden
}

function Assert-MariaDbLocalBinding {
    if (-not (Test-Path $mysqlIni)) { Stop-WithError "MariaDB ERROR: no se encontro $mysqlIni." }
    $settings = @([regex]::Matches((Get-Content $mysqlIni -Raw), '(?im)^\s*bind-address\s*=\s*"?([^"\s#]+)') |
        ForEach-Object { $_.Groups[1].Value })
    if ($settings.Count -ne 1 -or $settings[0] -ne '127.0.0.1') {
        Stop-WithError 'MariaDB ERROR: my.ini debe definir una sola vez bind-address=127.0.0.1. No se modifico la configuracion.'
    }
}

function Test-MariaDbReady {
    $listeners = @(Get-PortListeners 3310)
    if (-not $listeners.Count) { return $false }
    if ($listeners | Where-Object LocalAddress -NE '127.0.0.1') {
        Stop-WithError 'MariaDB ERROR: el puerto 3310 no esta limitado a 127.0.0.1.'
    }

    $processIds = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($processId in $processIds) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
        if (-not $process -or $process.Name -ne 'mysqld.exe' -or $process.ExecutablePath -ine $mysqlServer -or
            $process.CommandLine -notmatch '(?i)--defaults-file="?(?:C:\\xampp\\)?mysql[\\/]bin[\\/]my\.ini"?') {
            Stop-WithError "MariaDB ERROR: 127.0.0.1:3310 pertenece a un proceso ajeno a XAMPP (PID $processId)."
        }
    }

    $result = & $mysqlAdmin --connect-timeout=2 --protocol=tcp --host=127.0.0.1 --port=3310 --user=root ping 2>&1
    return $LASTEXITCODE -eq 0 -and ($result -join ' ') -match 'mysqld is alive'
}

function Test-ApacheBackendReady {
    $listeners = @(Get-PortListeners 80)
    if (-not $listeners.Count) { return $false }

    $processIds = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($processId in $processIds) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
        if (-not $process -or $process.Name -ne 'httpd.exe' -or $process.ExecutablePath -ine $apacheServer) {
            Stop-WithError "Apache ERROR: puerto 80 pertenece a un proceso ajeno a XAMPP (PID $processId)."
        }
    }

    try {
        foreach ($url in @($httpApiUrl, ('http://127.0.0.1' + $apiPath + $mapQuery))) {
            $response = Invoke-WebRequest -Uri $url -TimeoutSec 6 -UseBasicParsing
            $payload = $response.Content | ConvertFrom-Json
            if ($response.StatusCode -ne 200 -or $payload.success -ne $true) { return $false }
        }
        return $true
    } catch {
        return $false
    }
}

function Wait-ForComponent([string]$name, [scriptblock]$ready, [int]$timeoutSeconds, [string]$logPath) {
    $deadline = (Get-Date).AddSeconds($timeoutSeconds)
    do {
        if (& $ready) { return }
        [System.Threading.Thread]::Sleep(500)
    } while ((Get-Date) -lt $deadline)

    Write-Host "$name ERROR: no respondio dentro de $timeoutSeconds segundos." -ForegroundColor Red
    if (Test-Path $logPath) {
        Write-Host "Ultimo registro de diagnostico ($logPath):" -ForegroundColor Yellow
        Get-Content $logPath -Tail 12 | ForEach-Object { Write-Host $_ }
    }
    exit 1
}

$launcherMutex = New-Object System.Threading.Mutex($false, 'Local\ZemynaAndroidLocalLauncher')
$launcherLock = $false
try {
    try { $launcherLock = $launcherMutex.WaitOne(120000) }
    catch [System.Threading.AbandonedMutexException] { $launcherLock = $true }
    if (-not $launcherLock) { Stop-WithError 'Otro launcher sigue preparando Zemyna.' }
$address = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object IPAddress -EQ $expectedIp
if (-not $address -or $address.PrefixLength -ne 24 -or $address.InterfaceAlias -ne 'Wi-Fi') {
    Stop-WithError "IP ERROR: Wi-Fi debe tener $expectedIp/24. No se cambio la configuracion de red."
}
Write-Host "IP OK: $expectedIp/24 (Wi-Fi)" -ForegroundColor Green

foreach ($requiredPath in @($mysqlAdmin, $mysqlServer, $mysqlIni, $apacheServer,
        $apacheStartScript, $mysqlStartScript)) {
    if (-not (Test-Path $requiredPath)) { Stop-WithError "XAMPP ERROR: no se encontro $requiredPath." }
}

Assert-MariaDbLocalBinding
$mariaDbListeners = @(Get-PortListeners 3310)
if (-not $mariaDbListeners.Count) {
    $existingMariaDb = Get-XamppProcesses 'mysqld.exe' $mysqlServer
    if (-not $existingMariaDb.Count) {
        try { $null = Start-XamppScript $mysqlStartScript }
        catch { Stop-WithError "MariaDB ERROR: no se pudo iniciar mysql_start.bat: $($_.Exception.Message)" }
        Write-Host 'Iniciando MariaDB con C:\xampp\mysql_start.bat...' -ForegroundColor Yellow
    }
}
Wait-ForComponent 'MariaDB' { Test-MariaDbReady } 45 $mysqlErrorLog
Write-Host 'MariaDB OK: handshake/ping en 127.0.0.1:3310 (solo localhost).' -ForegroundColor Green

$apacheListeners = @(Get-PortListeners 80)
if (-not $apacheListeners.Count) {
    $existingApache = Get-XamppProcesses 'httpd.exe' $apacheServer
    if (-not $existingApache.Count) {
        try { $null = Start-XamppScript $apacheStartScript }
        catch { Stop-WithError "Apache ERROR: no se pudo iniciar apache_start.bat: $($_.Exception.Message)" }
        Write-Host 'Iniciando Apache con C:\xampp\apache_start.bat...' -ForegroundColor Yellow
    }
}
Wait-ForComponent 'Apache/backend API' { Test-ApacheBackendReady } 45 $apacheErrorLog
Write-Host 'Apache: OK' -ForegroundColor Green
Write-Host 'Backend: OK (tipos_residuo.php por HTTP 127.0.0.1)' -ForegroundColor Green

Write-Host "URL Android activa: http://$expectedIp$apiPath" -ForegroundColor Cyan
Write-Host 'ZEMYNA LOCAL LISTA: MariaDB OK, Apache OK, API contenedores OK.' -ForegroundColor Green
Write-Host 'Los procesos siguen activos al cerrar este lanzador.' -ForegroundColor Cyan
} catch {
    Write-Host "Zemyna ERROR: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
} finally {
    if ($launcherLock) { $launcherMutex.ReleaseMutex() }
    $launcherMutex.Dispose()
}
