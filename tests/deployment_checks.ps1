# Run with Windows PowerShell 5.1 or PowerShell 7. No NAS access required.
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$temp = Join-Path ([IO.Path]::GetTempPath()) ('eablage-deploy-test-' + [guid]::NewGuid())
$source = Join-Path $temp 'source'
$target = Join-Path $temp 'target'

# Mock only external programs; execute both complete deployment scripts.
function git {
    if ($args -contains 'branch') { $global:eablageDeploymentTestBranch }
    $global:LASTEXITCODE = 0
}
function robocopy {
    $global:eablageDeploymentTestMirrorCalls++
    $global:LASTEXITCODE = 0
}

try {
    foreach ($path in @('.git', 'web', 'backend')) {
        New-Item -ItemType Directory -Path (Join-Path $source $path) -Force | Out-Null
    }
    New-Item -ItemType Directory -Path (Join-Path $target 'web') -Force | Out-Null
    foreach ($path in @('web/Dockerfile', 'backend/Dockerfile', 'compose.yaml')) {
        Set-Content -LiteralPath (Join-Path $source $path) -Value 'new-code'
    }
    Set-Content -LiteralPath (Join-Path $target 'web/konfiguration.php') -Value 'local-config'
    $envFile = Join-Path $target '.env'
    $composeFile = Join-Path $target 'compose.yaml'
    $checks = 0
    foreach ($instance in @('test', 'prod')) {
        $global:eablageDeploymentTestBranch = if ($instance -eq 'test') { 'develop' } else { 'main' }
        $other = if ($instance -eq 'test') { 'prod' } else { 'test' }
        $cases = @(
            @{ Text = "EABLAGE_INSTANCE=$instance"; Accept = $true },
            @{ Text = " EABLAGE_INSTANCE = `"$instance`" # local target"; Accept = $true },
            @{ Text = "EABLAGE_INSTANCE='$instance'"; Accept = $true },
            @{ Text = '# missing marker'; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=$other"; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=$instance`nEABLAGE_INSTANCE=$other"; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=$instance`nEABLAGE_INSTANCE=$instance"; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=${instance}extra"; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=`"$instance'"; Accept = $false },
            @{ Text = "EABLAGE_INSTANCE=$($instance.ToUpperInvariant())"; Accept = $false }
        )
        foreach ($case in $cases) {
            # Deliberately arbitrary names/paths: these are not deployment guards.
            $envText = $case.Text + "`nEABLAGE_DB_NAME=custom_archive`nEABLAGE_DATA_PATH=/data/custom"
            Set-Content -LiteralPath $envFile -Value $envText
            Set-Content -LiteralPath $composeFile -Value 'old-code'
            $originalEnv = Get-Content -LiteralPath $envFile -Raw
            $global:eablageDeploymentTestMirrorCalls = 0
            $failure = $null
            try { & (Join-Path $repo "deploy-$instance.ps1") -Source $source -Target $target }
            catch { $failure = $_.Exception.Message }
            if ($case.Accept) {
                if ($failure -or $global:eablageDeploymentTestMirrorCalls -ne 2 -or (Get-Content $composeFile) -ne 'new-code') {
                    throw "Valid $instance target rejected: $failure"
                }
            } else {
                if (-not $failure -or $failure -notlike '*EABLAGE_INSTANCE*' -or $global:eablageDeploymentTestMirrorCalls -ne 0 -or (Get-Content $composeFile) -ne 'old-code') {
                    throw "Invalid $instance target was not stopped before copying: $failure"
                }
            }
            if ((Get-Content -LiteralPath $envFile -Raw) -cne $originalEnv -or (Get-Content (Join-Path $target 'web/konfiguration.php')) -ne 'local-config') {
                throw 'Local configuration was changed.'
            }
            $checks++
        }
    }
    Write-Host "$checks deployment checks passed."
} finally {
    Remove-Item -LiteralPath $temp -Recurse -Force
}
