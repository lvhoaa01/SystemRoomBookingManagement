[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $Destination,

    [string] $ProjectRoot = '',

    [string] $MySqlDumpPath = '',

    [switch] $ValidateOnly
)

$ErrorActionPreference = 'Stop'

function Read-EnvFile {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Không tìm thấy file cấu hình: $Path"
    }

    $values = @{}
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
            continue
        }
        $parts = $trimmed.Split('=', 2)
        $value = $parts[1].Trim()
        if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'")))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $values[$parts[0].Trim()] = $value
    }

    return $values
}

function Resolve-MySqlDump {
    param([string] $RequestedPath, [string] $Root)

    if ($RequestedPath -ne '') {
        return (Resolve-Path -LiteralPath $RequestedPath).Path
    }

    $xamppRoot = Split-Path (Split-Path $Root -Parent) -Parent
    $xamppCandidate = Join-Path $xamppRoot 'mysql\bin\mysqldump.exe'
    if (Test-Path -LiteralPath $xamppCandidate -PathType Leaf) {
        return (Resolve-Path -LiteralPath $xamppCandidate).Path
    }

    $command = Get-Command 'mysqldump' -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw 'Không tìm thấy mysqldump. Hãy truyền -MySqlDumpPath.'
    }
    return $command.Source
}

if ($ProjectRoot -eq '') {
    $ProjectRoot = Join-Path $PSScriptRoot '..\..'
}
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$uploadsPath = Join-Path $resolvedRoot 'storage\uploads'
if (-not (Test-Path -LiteralPath $uploadsPath -PathType Container)) {
    throw "Không tìm thấy thư mục uploads: $uploadsPath"
}

$envValues = Read-EnvFile (Join-Path $resolvedRoot '.env')
foreach ($requiredKey in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME')) {
    if (-not $envValues.ContainsKey($requiredKey) -or $envValues[$requiredKey] -eq '') {
        throw "Thiếu cấu hình bắt buộc $requiredKey trong .env."
    }
}
$dumpExecutable = Resolve-MySqlDump $MySqlDumpPath $resolvedRoot
$destinationRoot = [System.IO.Path]::GetFullPath($Destination)
if ([string]::IsNullOrWhiteSpace($destinationRoot)) {
    throw 'Đường dẫn đích backup không hợp lệ.'
}

if ($ValidateOnly) {
    Write-Output 'Backup prerequisites: OK'
    Write-Output "Project root: $resolvedRoot"
    Write-Output "mysqldump: $dumpExecutable"
    Write-Output "Uploads: $uploadsPath"
    exit 0
}

New-Item -ItemType Directory -Path $destinationRoot -Force | Out-Null
$timestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmssZ')
$backupName = "hotel-booking-$timestamp"
$finalPath = Join-Path $destinationRoot $backupName
if (Test-Path -LiteralPath $finalPath) {
    throw "Backup đã tồn tại: $finalPath"
}
$temporaryPath = Join-Path $destinationRoot ('.backup-in-progress-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryPath | Out-Null

$databaseFile = Join-Path $temporaryPath 'database.sql'
$uploadsFile = Join-Path $temporaryPath 'uploads.zip'
$oldMySqlPassword = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $envValues['DB_PASSWORD']
    $dumpArguments = @(
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--default-character-set=utf8mb4',
        ("--host=$($envValues['DB_HOST'])"),
        ("--port=$($envValues['DB_PORT'])"),
        ("--user=$($envValues['DB_USERNAME'])"),
        ("--result-file=$databaseFile"),
        $envValues['DB_DATABASE']
    )
    & $dumpExecutable @dumpArguments
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $databaseFile -PathType Leaf)) {
        throw "mysqldump thất bại với exit code $LASTEXITCODE."
    }

    Compress-Archive -LiteralPath $uploadsPath -DestinationPath $uploadsFile -CompressionLevel Optimal
    $manifest = [ordered]@{
        formatVersion = 1
        createdAtUtc = (Get-Date).ToUniversalTime().ToString('o')
        database = $envValues['DB_DATABASE']
        databaseFile = 'database.sql'
        databaseSha256 = (Get-FileHash -LiteralPath $databaseFile -Algorithm SHA256).Hash.ToLowerInvariant()
        uploadsFile = 'uploads.zip'
        uploadsSha256 = (Get-FileHash -LiteralPath $uploadsFile -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $temporaryPath 'manifest.json') -Encoding utf8
    Move-Item -LiteralPath $temporaryPath -Destination $finalPath
    Write-Output "Backup completed: $finalPath"
} catch {
    if (Test-Path -LiteralPath $temporaryPath) {
        Remove-Item -LiteralPath $temporaryPath -Recurse -Force
    }
    throw
} finally {
    $env:MYSQL_PWD = $oldMySqlPassword
}
