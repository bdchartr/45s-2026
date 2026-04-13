$server    = "wkapp.com"
$username  = "chartb"
$port      = 2222
$remoteDir = "/home/chartb/public_html/45s"
$keyPath   = "$env:USERPROFILE\.ssh\id_ed25519"

$excludePatterns = @(
    "server/config.php",
    "vendor/*",
    "node_modules/*",
    ".git/*"
)

function Get-PosixParent([string]$path) {
    if ([string]::IsNullOrWhiteSpace($path)) { return "" }
    $trimmed = $path.TrimEnd('/')
    $idx = $trimmed.LastIndexOf('/')
    if ($idx -lt 0) { return "" }
    if ($idx -eq 0) { return "/" }
    return $trimmed.Substring(0, $idx)
}

if (-not (Test-Path "composer.json")) {
    Write-Host "Run this from the 45s project root." -ForegroundColor Red
    exit 1
}

# Ensure ssh-agent is running and the key is loaded once for this deploy.
$agentService = Get-Service ssh-agent -ErrorAction SilentlyContinue
if (-not $agentService) {
    Write-Host "ERROR: OpenSSH (ssh-agent service) is not installed." -ForegroundColor Red
    exit 1
}

if ($agentService.Status -ne "Running") {
    if ($agentService.StartType -eq "Disabled") {
        Write-Host "ERROR: ssh-agent is disabled. Enable it once as Administrator:" -ForegroundColor Red
        Write-Host "  Set-Service ssh-agent -StartupType Manual" -ForegroundColor DarkGray
        Write-Host "  Start-Service ssh-agent" -ForegroundColor DarkGray
        exit 1
    }

    Start-Service ssh-agent -ErrorAction SilentlyContinue
    if ((Get-Service ssh-agent).Status -ne "Running") {
        Write-Host "ERROR: Could not start ssh-agent." -ForegroundColor Red
        exit 1
    }
}

if (-not (Test-Path $keyPath)) {
    Write-Host "ERROR: SSH key not found at $keyPath" -ForegroundColor Red
    exit 1
}

$loadedKeys = ssh-add -l 2>&1
$fingerprint = ssh-keygen -lf $keyPath 2>&1 | Select-String -Pattern "SHA256:[A-Za-z0-9+/]+"
$alreadyLoaded = $fingerprint -and ($loadedKeys -match ($fingerprint.Matches[0].Value))

if (-not $alreadyLoaded) {
    Write-Host "Loading SSH key into agent (enter passphrase once)..." -ForegroundColor Yellow
    ssh-add "$keyPath"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to add SSH key to agent." -ForegroundColor Red
        exit 1
    }
}

ssh -p $port "${username}@${server}" "mkdir -p $remoteDir"

$allFiles = Get-ChildItem -Path "." -Recurse -File
$files = $allFiles | Where-Object {
    $rel = $_.FullName.Substring((Get-Location).Path.Length + 1).Replace("\", "/")
    foreach ($pattern in $excludePatterns) {
        if ($rel -like $pattern) { return $false }
    }
    return $true
}

$stagingDir = Join-Path $env:TEMP ("45s_deploy_" + [Guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $stagingDir | Out-Null

foreach ($f in $files) {
    $rel = $f.FullName.Substring((Get-Location).Path.Length + 1)
    $dest = Join-Path $stagingDir $rel
    $destParent = Split-Path $dest -Parent
    if (-not (Test-Path $destParent)) {
        New-Item -ItemType Directory -Path $destParent -Force | Out-Null
    }
    Copy-Item -LiteralPath $f.FullName -Destination $dest -Force
}

Write-Host "Uploading staged files in one pass..." -ForegroundColor Yellow

$dirs = New-Object System.Collections.Generic.HashSet[string]
$dirs.Add($remoteDir) | Out-Null

foreach ($f in $files) {
    $rel = $f.FullName.Substring((Get-Location).Path.Length + 1).Replace("\", "/")
    $remotePath = "$remoteDir/$rel"
    $remoteParent = Get-PosixParent $remotePath

    while ($remoteParent -and $remoteParent.StartsWith($remoteDir)) {
        $dirs.Add($remoteParent) | Out-Null
        if ($remoteParent -eq $remoteDir) {
            break
        }
        $remoteParent = Get-PosixParent $remoteParent
    }
}

$mkdirCommands = New-Object System.Collections.Generic.List[string]
$orderedDirs = @($dirs) | Sort-Object { ($_ -split '/').Count }
foreach ($d in $orderedDirs) {
    $mkdirCommands.Add("-mkdir $d") | Out-Null
}

$putCommands = New-Object System.Collections.Generic.List[string]
foreach ($f in $files) {
    $rel = $f.FullName.Substring((Get-Location).Path.Length + 1)
    $localStaged = (Join-Path $stagingDir $rel).Replace("\", "/")
    $remotePath = ("$remoteDir/" + $rel.Replace("\", "/"))
    $putCommands.Add(('put "{0}" {1}' -f $localStaged, $remotePath)) | Out-Null
}

$chunkSize = 40
$maxRetriesPerChunk = 3
$total = $putCommands.Count
$uploaded = 0

for ($start = 0; $start -lt $total; $start += $chunkSize) {
    $endExclusive = [Math]::Min($start + $chunkSize, $total)
    $chunkLines = New-Object System.Collections.Generic.List[string]

    foreach ($line in $mkdirCommands) {
        $chunkLines.Add($line) | Out-Null
    }

    for ($i = $start; $i -lt $endExclusive; $i++) {
        $chunkLines.Add($putCommands[$i]) | Out-Null
    }

    $chunkBatchFile = Join-Path $env:TEMP ("45s_sftp_" + [Guid]::NewGuid().ToString("N") + ".txt")
    Set-Content -Path $chunkBatchFile -Value $chunkLines -Encoding ascii

    $chunkSucceeded = $false
    for ($attempt = 1; $attempt -le $maxRetriesPerChunk; $attempt++) {
        sftp -b $chunkBatchFile -P $port "${username}@${server}"
        if ($LASTEXITCODE -eq 0) {
            $chunkSucceeded = $true
            break
        }
        Write-Host "Chunk upload failed (attempt $attempt/$maxRetriesPerChunk). Retrying..." -ForegroundColor Yellow
    }

    Remove-Item -Path $chunkBatchFile -Force -ErrorAction SilentlyContinue

    if (-not $chunkSucceeded) {
        Remove-Item -Path $stagingDir -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "Deployment upload failed (SFTP chunk mode)." -ForegroundColor Red
        exit 1
    }

    $uploaded += ($endExclusive - $start)
    Write-Host "Uploaded $uploaded/$total files..." -ForegroundColor DarkGray
}

Remove-Item -Path $stagingDir -Recurse -Force -ErrorAction SilentlyContinue

Write-Host "Deployment complete." -ForegroundColor Green
Write-Host "URL: https://wkapp.com/45s/" -ForegroundColor Cyan
