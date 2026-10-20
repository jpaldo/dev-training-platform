param(
    [ValidateSet('start', 'stop', 'restart', 'status')]
    [string]$Action = 'start'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$StateDir = Join-Path $Root '.dev'
$StateFile = Join-Path $StateDir 'processes.json'
$LogDir = Join-Path $StateDir 'logs'
$RunId = [DateTime]::UtcNow.ToString('yyyyMMddHHmmssfff')
$ServiceMarkers = @{
    'qa-lab' = 'qa-lab'
    'queue-worker' = 'queue-worker'
}
$ManagedProcessNames = @('cmd.exe', 'node.exe', 'php.exe')

function Ensure-StateDirectory {
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
}

function Read-State {
    if (-not (Test-Path $StateFile)) { return @() }
    $raw = Get-Content $StateFile -Raw
    if ([string]::IsNullOrWhiteSpace($raw)) { return @() }
    $value = ConvertFrom-Json $raw
    if ($value -is [array]) { return @($value) }
    return @($value)
}

function Write-State($entries) {
    Ensure-StateDirectory
    @($entries) | ConvertTo-Json | Set-Content -Encoding UTF8 $StateFile
}

function Get-OwnedProcess($entry) {
    try {
        $process = Get-Process -Id ([int]$entry.pid) -ErrorAction Stop
        if ($entry.startTime -and ([DateTime]$process.StartTime).ToUniversalTime().Ticks -ne [int64]$entry.startTime) { return $null }
        return $process
    } catch { return $null }
}

function Get-ProcessInventory {
    try {
        return @(Get-CimInstance Win32_Process -ErrorAction Stop | Select-Object ProcessId, ParentProcessId, Name, CommandLine, ExecutablePath)
    } catch {
        return @()
    }
}

function Get-DescendantProcessIds($rootId, $inventory) {
    $ids = New-Object 'System.Collections.Generic.List[int]'
    $pending = New-Object 'System.Collections.Generic.Queue[int]'
    $pending.Enqueue([int]$rootId)
    while ($pending.Count -gt 0) {
        $parentId = $pending.Dequeue()
        foreach ($child in @($inventory | Where-Object { [int]$_.ParentProcessId -eq $parentId })) {
            if (-not $ids.Contains([int]$child.ProcessId)) {
                $ids.Add([int]$child.ProcessId)
                $pending.Enqueue([int]$child.ProcessId)
            }
        }
    }
    return @($ids)
}

function Get-AncestorProcessIds($rootId, $inventory) {
    $ids = New-Object 'System.Collections.Generic.List[int]'
    $currentId = [int]$rootId
    while ($true) {
        $current = @($inventory | Where-Object { [int]$_.ProcessId -eq $currentId } | Select-Object -First 1)
        if (-not $current -or [int]$current.ParentProcessId -le 0) { break }
        $parent = @($inventory | Where-Object { [int]$_.ProcessId -eq [int]$current.ParentProcessId } | Select-Object -First 1)
        if (-not $parent -or $parent.Name.ToLowerInvariant() -notin $ManagedProcessNames) { break }
        if ($ids.Contains([int]$parent.ProcessId)) { break }
        $ids.Add([int]$parent.ProcessId)
        $currentId = [int]$parent.ProcessId
    }
    return @($ids)
}

function Test-ServiceProcess($serviceName, $process) {
    if (-not $process.CommandLine) { return $false }
    $command = $process.CommandLine.ToLowerInvariant().Replace('/', '\\')
    $rootPath = $Root.ToLowerInvariant().Replace('/', '\\')
    switch ($serviceName) {
        'qa-lab' { return $command.Contains($rootPath) -and ($command.Contains('artisan serve') -or $command.Contains('php artisan')) }
        'queue-worker' { return $command.Contains($rootPath) -and $command.Contains('artisan queue:work') }
        'reverse-proxy' { return $command.Contains($rootPath) -and $command.Contains('scripts\\dev-proxy.cjs') }
        default { return $false }
    }
}

function Get-EnvironmentProcessIds {
    $inventory = Get-ProcessInventory
    $ids = New-Object 'System.Collections.Generic.HashSet[int]'

    foreach ($entry in @(Read-State | Where-Object { $ServiceMarkers.ContainsKey($_.name) })) {
        if (Get-OwnedProcess $entry) { [void]$ids.Add([int]$entry.pid) }
    }

    if ($inventory.Count -gt 0) {
        foreach ($marker in $ServiceMarkers.GetEnumerator()) {
            $needle = (Join-Path $LogDir "$($marker.Value)").ToLowerInvariant().Replace('/', '\')
            foreach ($process in @($inventory | Where-Object {
                $_.Name -and $ManagedProcessNames -contains $_.Name.ToLowerInvariant() -and
                $_.CommandLine -and $_.CommandLine.ToLowerInvariant().Replace('/', '\').Contains($needle)
            })) {
                [void]$ids.Add([int]$process.ProcessId)
                foreach ($childId in @(Get-DescendantProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($childId) }
            }
        }
        foreach ($serviceName in @('qa-lab', 'queue-worker')) {
            foreach ($process in @($inventory | Where-Object { $_.Name -and $ManagedProcessNames -contains $_.Name.ToLowerInvariant() -and (Test-ServiceProcess $serviceName $_) })) {
                [void]$ids.Add([int]$process.ProcessId)
                foreach ($ancestorId in @(Get-AncestorProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($ancestorId) }
                foreach ($childId in @(Get-DescendantProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($childId) }
            }
        }
    }

    return @($ids)
}

function Get-EnvironmentServiceProcessIds($serviceName) {
    $inventory = Get-ProcessInventory
    $ids = New-Object 'System.Collections.Generic.HashSet[int]'
    foreach ($entry in @(Read-State | Where-Object { $_.name -eq $serviceName })) {
        if (Get-OwnedProcess $entry) { [void]$ids.Add([int]$entry.pid) }
    }
    if ($inventory.Count -gt 0 -and $ServiceMarkers.ContainsKey($serviceName)) {
        $needle = (Join-Path $LogDir $ServiceMarkers[$serviceName]).ToLowerInvariant().Replace('/', '\')
        foreach ($process in @($inventory | Where-Object {
            $_.Name -and $ManagedProcessNames -contains $_.Name.ToLowerInvariant() -and
            $_.CommandLine -and $_.CommandLine.ToLowerInvariant().Replace('/', '\').Contains($needle)
        })) {
            [void]$ids.Add([int]$process.ProcessId)
            foreach ($childId in @(Get-DescendantProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($childId) }
        }
        foreach ($process in @($inventory | Where-Object { $_.Name -and $ManagedProcessNames -contains $_.Name.ToLowerInvariant() -and (Test-ServiceProcess $serviceName $_) })) {
            [void]$ids.Add([int]$process.ProcessId)
            foreach ($ancestorId in @(Get-AncestorProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($ancestorId) }
            foreach ($childId in @(Get-DescendantProcessIds $process.ProcessId $inventory)) { [void]$ids.Add($childId) }
        }
    }
    return @($ids)
}

function Stop-EnvironmentProcess($processId, $inventory) {
    $descendants = @(Get-DescendantProcessIds $processId $inventory)
    foreach ($childId in ($descendants | Sort-Object -Descending)) {
        Stop-Process -Id $childId -Force -ErrorAction SilentlyContinue
    }
    Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
}

function Stop-OwnedProcess($entry) {
    $process = Get-OwnedProcess $entry
    if (-not $process) { return $false }
    # WMI/CIM process-tree enumeration is not available in every non-elevated
    # PowerShell session. taskkill can terminate the owned launcher and its
    # descendants without relying on that inventory. The PID and start-time
    # check above still prevents us from targeting a recycled/unrelated PID.
    & taskkill.exe /PID $process.Id /T /F *> $null
    if (Get-Process -Id $process.Id -ErrorAction SilentlyContinue) {
        Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
    }
    return -not (Get-Process -Id $process.Id -ErrorAction SilentlyContinue)
}

function Port-InUse([int]$port) {
    if ($port -le 0) { return $false }
    return @(netstat -ano | Select-String -Pattern (":$port\s+.*LISTENING\s+\d+$")).Count -gt 0
}

function Get-ListeningProcessId([int]$port) {
    $match = netstat -ano | Select-String -Pattern (":$port\s+.*LISTENING\s+(\d+)$") | Select-Object -First 1
    if (-not $match) { return $null }
    return [int]$match.Matches[0].Groups[1].Value
}

function Get-ListeningProcessIds([int]$port) {
    $matches = netstat -ano | Select-String -Pattern (":$port\s+.*LISTENING\s+(\d+)$")
    return @($matches | ForEach-Object { [int]$_.Matches[0].Groups[1].Value } | Sort-Object -Unique)
}

function Stop-PortListeners([int]$port) {
    foreach ($listenerId in @(Get-ListeningProcessIds $port)) {
        if ($listenerId -le 0) { continue }
        $process = Get-Process -Id $listenerId -ErrorAction SilentlyContinue
        $name = if ($process) { $process.ProcessName } else { 'unknown' }
        Write-Host "[STOP] localhost:$port PID $listenerId ($name)"
        # These ports are reserved for this QA development environment. The
        # port-scoped force-stop is deliberately limited to this configured
        # set; it never targets PHP/Node processes by name globally.
        try { & taskkill.exe /PID $listenerId /T /F 2>&1 | Out-Null } catch { }
        if (Get-Process -Id $listenerId -ErrorAction SilentlyContinue) {
            Stop-Process -Id $listenerId -Force -ErrorAction SilentlyContinue
        }
    }
}

function Wait-HttpHealthy($name, $url) {
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Milliseconds 500
    }
    throw "$name is listening but did not become healthy at $url."
}

function Start-Managed($name, $file, $arguments, $workingDirectory, $port) {
    $existing = (Read-State | Where-Object { $_.name -eq $name } | Select-Object -First 1)
    if ($existing -and (Get-OwnedProcess $existing) -and ($port -le 0 -or (Port-InUse $port))) { return $existing }
    if ($existing -and (Get-OwnedProcess $existing)) { Stop-OwnedProcess $existing | Out-Null }
    if (Port-InUse $port) {
        # A developer may have started `php artisan serve` manually. Reuse
        # that local Laravel listener instead of aborting the whole startup;
        # otherwise make restart exits before the queue worker is started and
        # participant Drive syncs remain stuck in the UI.
        if ($name -eq 'qa-lab') {
            $listenerId = Get-ListeningProcessId $port
            if ($listenerId) {
                try {
                    $listener = Get-Process -Id $listenerId -ErrorAction Stop
                    return [pscustomobject]@{ name = $name; pid = $listener.Id; startTime = ([DateTime]$listener.StartTime).ToUniversalTime().Ticks; port = $port }
                } catch { }
            }
        }
        throw "$name cannot start: localhost:$port is already in use by a process not owned by this environment."
    }

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $file
    if ([IO.Path]::GetFileName($file) -ieq 'cmd.exe') {
    $logPath = Join-Path $LogDir "$name-$RunId.log"
    $launcherPath = Join-Path $LogDir "$name-$RunId.cmd"
    Remove-Item -LiteralPath $logPath -Force -ErrorAction SilentlyContinue
    $command = $arguments[-1]
    # npm.cmd is itself a batch file; CALL is required when invoking it from
    # our launcher or cmd.exe will stop tracking the long-running child.
    $command = $command -replace 'npm\.cmd', 'call npm.cmd'
    @('@echo off', "$command > `"$logPath`" 2>&1") | Set-Content -LiteralPath $launcherPath -Encoding ascii
    $startInfo.Arguments = "/d /s /c call `"$launcherPath`""
    } else {
        $startInfo.Arguments = (($arguments | ForEach-Object { '"' + $_.ToString().Replace('"', '\"') + '"' }) -join ' ')
    }
    $startInfo.WorkingDirectory = $workingDirectory
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $false
    $startInfo.RedirectStandardError = $false
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    [void]$process.Start()
    Start-Sleep -Milliseconds 400
    $startTicks = $null
    for ($attempt = 0; $attempt -lt 10 -and -not $startTicks; $attempt++) {
        try { $startTicks = ([DateTime]$process.StartTime).ToUniversalTime().Ticks } catch { Start-Sleep -Milliseconds 100 }
    }
    if (-not $startTicks) {
        $details = if (Test-Path $logPath) { (Get-Content $logPath -Raw).Trim() } else { 'No process output was captured.' }
        throw "$name exited before its process could be recorded. $details"
    }
    if ($port -le 0) {
        Start-Sleep -Milliseconds 1000
        if ($process.HasExited) {
            $details = if (Test-Path $logPath) { (Get-Content $logPath -Raw).Trim() } else { 'No process output was captured.' }
            throw "$name exited during startup. $details"
        }
    }
    if ($port -gt 0) {
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            if (Port-InUse $port) { break }
            if ($process.HasExited) {
                $details = if (Test-Path $logPath) { (Get-Content $logPath -Raw).Trim() } else { 'No process output was captured.' }
                throw "$name exited before listening on localhost:$port. $details"
            }
            Start-Sleep -Milliseconds 500
        }
        if (-not (Port-InUse $port)) {
            $details = if (Test-Path $logPath) { (Get-Content $logPath -Raw).Trim() } else { 'No process output was captured.' }
            throw "$name did not open localhost:$port within 15 seconds. $details"
        }
        $listenerId = Get-ListeningProcessId $port
        if ($listenerId) {
            try {
                $listener = Get-Process -Id $listenerId -ErrorAction Stop
                if (([DateTime]$listener.StartTime).ToUniversalTime().Ticks -ge $startTicks) {
                    return [pscustomobject]@{ name = $name; pid = $listener.Id; startTime = ([DateTime]$listener.StartTime).ToUniversalTime().Ticks; port = $port }
                }
            } catch { }
        }
    }
    return [pscustomobject]@{ name = $name; pid = $process.Id; startTime = $startTicks; port = $port }
}

function Start-Environment {
    Ensure-StateDirectory
    $provisionOutput = & php artisan app:ensure-development-accounts --no-interaction 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Development account provisioning failed: $($provisionOutput -join ' ')"
    }
    $baselineOutput = & php artisan app:ensure-development-baseline --no-interaction 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Development baseline provisioning failed: $($baselineOutput -join ' ')"
    }
    $staleInventory = Get-ProcessInventory
    $staleIds = @(Get-EnvironmentProcessIds)
    if ($staleIds.Count -gt 0) {
        Write-Host 'Cleaning up existing QA training environment processes...'
        foreach ($staleId in $staleIds) {
            try {
                $staleProcess = Get-Process -Id $staleId -ErrorAction Stop
                Write-Host "[STOP] PID $staleId ($($staleProcess.ProcessName))"
            } catch { }
            Stop-EnvironmentProcess $staleId $staleInventory
        }
        Start-Sleep -Milliseconds 500
    }
    $entries = @()
    try {
        $entries += Start-Managed 'qa-lab' 'cmd.exe' @('/d', '/s', '/c', 'php artisan serve --host=127.0.0.1 --port=8080') $Root 8080
        $entries += Start-Managed 'queue-worker' 'cmd.exe' @('/d', '/s', '/c', 'php artisan queue:work --tries=3') $Root 0
        Write-State $entries
        Write-Host 'Development environment started locally.'
        Write-Host 'QA Training Platform: http://localhost:8080'
    } catch {
        foreach ($entry in $entries) { Stop-OwnedProcess $entry | Out-Null }
        $survivors = @($entries | Where-Object { Get-OwnedProcess $_ })
        if ($survivors.Count -gt 0) {
            Write-State $survivors
            Write-Host '[WARN] Startup cleanup could not terminate every process; ownership state was retained for a safe retry.'
        } elseif (Test-Path $StateFile) {
            Remove-Item -LiteralPath $StateFile -Force -ErrorAction SilentlyContinue
        }
        throw
    }
}

function Stop-Environment {
    Write-Host 'Stopping QA training environment...'
    $inventory = Get-ProcessInventory
    $ids = @(Get-EnvironmentProcessIds)
    if ($ids.Count -eq 0) {
        Write-Host '[INFO] No registered or discoverable QA training processes found.'
    } else {
        foreach ($id in $ids) {
            try {
                $process = Get-Process -Id $id -ErrorAction Stop
                Write-Host "[STOP] PID $id ($($process.ProcessName))"
            } catch { }
            Stop-EnvironmentProcess $id $inventory
        }
    }

    # Ownership metadata can be stale or unavailable after a prior launcher
    # crash. Force-clean only the reserved QA listener port so stale child
    # PHP processes cannot block the next `make dev`.
    foreach ($port in @(8080)) {
        Stop-PortListeners $port
    }

    Write-Host 'Verifying cleanup...'
    $remainingOwned = @()
    foreach ($entry in @(Read-State)) {
        if (Get-OwnedProcess $entry) { $remainingOwned += $entry }
    }
    $remainingPorts = @()
    $releaseFailures = @()
    foreach ($port in @(8080)) {
        if (Port-InUse $port) {
            $remainingPorts += $port
            $listenerId = Get-ListeningProcessId $port
            $releaseFailures += "localhost:$port PID $listenerId"
            if ($remainingOwned | Where-Object { [int]$_.pid -eq [int]$listenerId }) {
                Write-Host "[WARN] localhost:$port remains occupied by a QA Training process (PID $listenerId)."
            } else {
                Write-Host "[WARN] localhost:$port remains occupied by a process whose ownership could not be proven."
            }
        } else {
            Write-Host "[OK] localhost:$port free"
        }
    }
    if (@($remainingOwned).Count -gt 0) {
        Write-Host '[WARN] QA training environment is not fully stopped; ownership state was retained for a safe retry.'
        throw 'QA training environment stop failed because owned processes remain running.'
    }
    if (Test-Path $StateFile) { Remove-Item -LiteralPath $StateFile -Force }
    if (@($remainingPorts).Count -gt 0) {
        throw "QA training environment stop failed; configured ports could not be released: $($releaseFailures -join ', ')"
    }
    Write-Host 'QA training environment stopped.'
}

function Show-Status {
    $entries = Read-State
    $inventory = Get-ProcessInventory
    $environmentIds = @(Get-EnvironmentProcessIds)
    $known = @('qa-lab', 'queue-worker')
    foreach ($name in $known) {
        $entry = $entries | Where-Object { $_.name -eq $name } | Select-Object -First 1
        $port = switch ($name) { 'qa-lab' { 8080 }; default { 0 } }
        $listenerId = if ($port -gt 0) { Get-ListeningProcessId $port } else { $null }
        $serviceIds = @(Get-EnvironmentServiceProcessIds $name)
        $running = ($listenerId -and $environmentIds -contains $listenerId) -or ($serviceIds.Count -gt 0)
        $label = if ($running) { 'RUNNING' } elseif ($listenerId) { 'UNOWNED LISTENER' } else { 'STOPPED' }
        if ($port -gt 0) {
            $pidText = if ($listenerId) { " - PID $listenerId" } else { '' }
            Write-Host "$name`: $label - localhost:$port$pidText"
        } elseif ($name -eq 'queue-worker') {
            $workerPids = @($serviceIds | Sort-Object -Unique)
            if ($workerPids.Count -eq 0 -and $entry -and (Get-OwnedProcess $entry)) { $workerPids = @([int]$entry.pid) }
            $pidText = if ($workerPids.Count -gt 0) { " - PID(s) $($workerPids -join ', ')" } else { '' }
            Write-Host "$name`: $label$pidText"
        } else {
            Write-Host "$name`: $label"
        }
    }
}

switch ($Action) {
    'start' { Start-Environment }
    'stop' { Stop-Environment }
    'restart' { Stop-Environment; Start-Sleep -Seconds 1; Start-Environment }
    'status' { Show-Status }
}
