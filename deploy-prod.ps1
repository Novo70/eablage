# Keep this script ASCII-compatible for Windows PowerShell 5.1 (UTF-8 without BOM).

# Kopiert nur Anwendungscode; kein Git-Pull, SQL-Update oder Container-Neustart.
# Vorher die main-Arbeitskopie aktualisieren (git pull --ff-only).
# Aufruf: .\deploy-prod.ps1 -Source W:\eablage -Target <Produktiv-Codeverzeichnis>

# Falls Ausfuehrung von Skripten nicht erlaubt ist:
# Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned

param(
    [string]$Source = "W:\eablage",
    [Parameter(Mandatory = $true)]
    [string]$Target
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "eAblage Produktiv-Deployment"
Write-Host "======================="
Write-Host "Quelle : $Source"
Write-Host "Ziel   : $Target"
Write-Host ""

# ----------------------------------------------------------
# 1. Quelle pruefen
# ----------------------------------------------------------

if (-not (Test-Path $Source)) {
    throw "Quellverzeichnis nicht gefunden: $Source"
}

if (-not (Test-Path (Join-Path $Source ".git"))) {
    throw "Das Quellverzeichnis ist kein Git-Arbeitsverzeichnis."
}

# ----------------------------------------------------------
# 2. Git-Branch pruefen
# ----------------------------------------------------------

$branch = git -C $Source branch --show-current

if ($LASTEXITCODE -ne 0) {
    throw "Git-Branch konnte nicht ermittelt werden."
}

if ($branch.Trim() -ne "main") {
    throw "Deployment abgebrochen: Aktiver Branch ist '$branch', erwartet wird 'main'."
}

Write-Host "Git-Branch: main"

# ----------------------------------------------------------
# 3. Arbeitsverzeichnis pruefen
# ----------------------------------------------------------

$gitStatus = git -C $Source status --porcelain
if ($LASTEXITCODE -ne 0) { throw "Git-Status konnte nicht ermittelt werden." }

if ($gitStatus) {
    Write-Host ""
    Write-Host "Nicht committete Aenderungen gefunden:" -ForegroundColor Yellow
    Write-Host $gitStatus
    Write-Host ""
    throw "Deployment abgebrochen. Aenderungen zuerst committen."
}

Write-Host "Git-Arbeitsverzeichnis ist sauber."

# ----------------------------------------------------------
# 4. Ziel pruefen
# ----------------------------------------------------------

if (-not (Test-Path $Target)) {
    throw "Zielverzeichnis nicht erreichbar: $Target"
}

$targetEnv = Join-Path $Target ".env"
$targetConfig = Join-Path $Target "web\konfiguration.php"

if (-not (Test-Path $targetEnv)) {
    throw "Die Laufzeitkonfiguration fehlt: $targetEnv"
}

if (-not (Test-Path $targetConfig)) {
    throw "Die PHP-Konfiguration fehlt: $targetConfig"
}

# Mirror only the two code directories. Validate the complete source before
# copying anything; never mirror the deployment root containing runtime data.
foreach ($part in @("web", "backend")) {
    if (-not (Test-Path (Join-Path $Source "$part\Dockerfile") -PathType Leaf)) {
        throw "Unvollstaendige Quelle: $part/Dockerfile fehlt."
    }
}
$sourceFull = [IO.Path]::GetFullPath($Source).TrimEnd('\', '/')
$targetFull = [IO.Path]::GetFullPath($Target).TrimEnd('\', '/')
if ($sourceFull -eq $targetFull -or $targetFull.StartsWith($sourceFull + '\', [StringComparison]::OrdinalIgnoreCase) -or $sourceFull.StartsWith($targetFull + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw "Quelle und Ziel duerfen nicht identisch oder ineinander verschachtelt sein."
}
# The local marker identifies the deployment target; resource paths stay in .env.
$instanceLines = @(Get-Content -LiteralPath $targetEnv | Where-Object {
    $_ -match '^[ \t]*EABLAGE_INSTANCE[ \t]*='
})
if ($instanceLines.Count -ne 1 -or
    $instanceLines[0] -cnotmatch '^[ \t]*EABLAGE_INSTANCE[ \t]*=[ \t]*(?:prod|"prod"|''prod'')[ \t]*(?:#.*)?$') {
    throw "Deployment abgebrochen: Ziel-.env muss genau einmal EABLAGE_INSTANCE=prod enthalten."
}
Write-Host "UGREEN-Laufzeitkonfiguration vorhanden."

# ----------------------------------------------------------
# 5. compose.yaml kopieren
# ----------------------------------------------------------

Write-Host ""
Write-Host "Kopiere compose.yaml ..."

Copy-Item `
    -Path (Join-Path $Source "compose.yaml") `
    -Destination (Join-Path $Target "compose.yaml") `
    -Force

# ----------------------------------------------------------
# Hilfsfunktion fuer Robocopy
# ----------------------------------------------------------

function Invoke-Robocopy {
    param(
        [string]$From,
        [string]$To,
        [string[]]$ExcludeFiles = @(),
        [string[]]$ExcludeDirs = @()
    )

    if (-not (Test-Path $To)) {
        New-Item -ItemType Directory -Path $To | Out-Null
    }

    $robocopyArgs = @(
        $From,
        $To,
        "/MIR",
        "/XJ",
        "/R:2",
        "/W:1",
        "/COPY:DAT",
        "/DCOPY:DAT",
        "/NP"
    )

    if ($ExcludeFiles.Count -gt 0) {
        $robocopyArgs += "/XF"
        $robocopyArgs += $ExcludeFiles
    }

    if ($ExcludeDirs.Count -gt 0) {
        $robocopyArgs += "/XD"
        $robocopyArgs += $ExcludeDirs
    }

    & robocopy @robocopyArgs

    $code = $LASTEXITCODE

    # Robocopy: 0 bis 7 = kein schwerer Fehler
    if ($code -ge 8) {
        throw "Robocopy ist mit Fehlercode $code fehlgeschlagen."
    }
}

# ----------------------------------------------------------
# 6. Webanwendung kopieren
# ----------------------------------------------------------

Write-Host ""
Write-Host "Synchronisiere web/ ..."

Invoke-Robocopy `
    -From (Join-Path $Source "web") `
    -To (Join-Path $Target "web") `
    -ExcludeFiles @(
        "konfiguration.php",
        "konfiguration_test.php",
        "konfiguration_prod.php",
        ".env",
        ".env.*"
    )

# ----------------------------------------------------------
# 7. Backend kopieren
# ----------------------------------------------------------

Write-Host ""
Write-Host "Synchronisiere backend/ ..."

Invoke-Robocopy `
    -From (Join-Path $Source "backend") `
    -To (Join-Path $Target "backend") `
    -ExcludeFiles @("*.pyc", ".env", ".env.*") `
    -ExcludeDirs @(
        "__pycache__",
        ".venv",
        "venv"
    )

# ----------------------------------------------------------
# Fertig
# ----------------------------------------------------------

Write-Host ""
Write-Host "Deployment abgeschlossen." -ForegroundColor Green
Write-Host ""
Write-Host "Nicht veraendert wurden:"
Write-Host "  $targetEnv"
Write-Host "  $targetConfig"
Write-Host ""
Write-Host "Auf dem UGREEN anschliessend:"
Write-Host "  cd /volume1/docker/eablage"
Write-Host "  sudo docker compose build"
Write-Host "  sudo docker compose up -d --force-recreate"
Write-Host ""
