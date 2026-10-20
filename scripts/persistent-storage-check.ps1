param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('capture', 'verify')]
    [string]$Mode,

    [Parameter(Mandatory = $true)]
    [string]$StorageRoot,

    [Parameter(Mandatory = $true)]
    [string]$ManifestPath,

    [string]$AfterManifestPath
)

$ErrorActionPreference = 'Stop'
$resolvedRoot = (Resolve-Path -LiteralPath $StorageRoot).Path.TrimEnd('\')

function Get-Inventory {
    param([string]$Root)

    $rootUri = [Uri]((Get-Item -LiteralPath $Root).FullName.TrimEnd('\') + '\')
    @(Get-ChildItem -LiteralPath $Root -Recurse -File | ForEach-Object {
        $fileUri = [Uri]((Get-Item -LiteralPath $_.FullName).FullName)
        $relative = $rootUri.MakeRelativeUri($fileUri).ToString()
        [pscustomobject]@{
            path = $relative
            bytes = [int64]$_.Length
            sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
        }
    })
}

if ($Mode -eq 'capture') {
    $inventory = @(Get-Inventory -Root $resolvedRoot)
    ConvertTo-Json -InputObject $inventory -Depth 4 | Set-Content -LiteralPath $ManifestPath -Encoding UTF8
    Write-Output "Captured $($inventory.Count) persistent files."
    exit 0
}

if ([string]::IsNullOrWhiteSpace($AfterManifestPath)) {
    throw 'AfterManifestPath is required in verify mode.'
}

$before = @((Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json) | ForEach-Object { $_ })
$after = @((Get-Content -LiteralPath $AfterManifestPath -Raw | ConvertFrom-Json) | ForEach-Object { $_ })
$afterByPath = @{}
foreach ($item in $after) { $afterByPath[$item.path] = $item }

$missing = @($before | Where-Object { -not $afterByPath.ContainsKey($_.path) })
$changed = @($before | Where-Object {
    $current = $afterByPath[$_.path]
    $null -ne $current -and ($current.bytes -ne $_.bytes -or $current.sha256 -ne $_.sha256)
})

Write-Output "Before files: $($before.Count)"
Write-Output "After files: $($after.Count)"
Write-Output "Missing persistent files: $($missing.Count)"
Write-Output "Changed persistent files: $($changed.Count)"

if ($missing.Count -gt 0 -or $changed.Count -gt 0) {
    Write-Output 'Persistent-storage verification FAILED.'
    if ($missing.Count -gt 0) { $missing | ForEach-Object { Write-Output "MISSING $($_.path)" } }
    if ($changed.Count -gt 0) { $changed | ForEach-Object { Write-Output "CHANGED $($_.path)" } }
    exit 1
}

Write-Output 'Persistent-storage verification PASSED.'
