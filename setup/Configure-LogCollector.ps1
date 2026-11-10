<#
.SYNOPSIS
  Points the Falcon LogScale Collector at the SB-FMS logs and starts it.

.DESCRIPTION
  Writes config.yaml for the installed Falcon LogScale Collector with two file sources
  (IIS W3C log, SB-FMS app log), each sent to its own Next-Gen SIEM HEC data connector.
  The existing config.yaml is backed up first and its dataDirectory line is kept.
  The collector service is set to Automatic and restarted.

.EXAMPLE
  .\setup\Configure-LogCollector.ps1 -IisUrl '<API URL of IIS connector>' -IisKey '<API key of IIS connector>' `
                                     -AppUrl '<API URL of App connector>' -AppKey '<API key of App connector>'
#>
param(
    [Parameter(Mandatory)] [string]$IisUrl,
    [Parameter(Mandatory)] [string]$IisKey,
    [Parameter(Mandatory)] [string]$AppUrl,
    [Parameter(Mandatory)] [string]$AppKey,
    [string]$CollectorDir = 'C:\Program Files (x86)\CrowdStrike\Humio Log Collector',
    [string]$LogRoot      = 'C:\SenarathFMS_Logs',
    [int]   $SiteId       = 20
)
$ErrorActionPreference = 'Stop'

$cfg = Join-Path $CollectorDir 'config.yaml'
if (-not (Test-Path $CollectorDir)) { throw "Collector not found in $CollectorDir - install the LogScale Collector MSI first (or pass -CollectorDir)." }

# the URL in the config must not end with /services/collector
$IisUrl = ($IisUrl.Trim() -replace '/services/collector/?$', '')
$AppUrl = ($AppUrl.Trim() -replace '/services/collector/?$', '')

# keep the installer's dataDirectory setting
$dataLine = ''
if (Test-Path $cfg) {
    $dataLine = (Get-Content $cfg | Where-Object { $_ -match '^\s*dataDirectory\s*:' } | Select-Object -First 1)
    $bak = "$cfg.bak-$(Get-Date -Format yyyyMMddHHmmss)"
    Copy-Item $cfg $bak
    Write-Host "Backed up existing config to $bak"
}

$iisGlob = ("$LogRoot\IIS\W3SVC$SiteId\*.log") -replace '\\', '/'
$appGlob = ("$LogRoot\app\*.log") -replace '\\', '/'

$yaml = @"
# Falcon LogScale Collector -> Falcon Next-Gen SIEM (written by Configure-LogCollector.ps1)
$dataLine

sources:
  sbfms_iis:
    type: file
    include:
      - "$iisGlob"
    sink: ngsiem_sbfms_iis

  sbfms_app:
    type: file
    include:
      - "$appGlob"
    sink: ngsiem_sbfms_app

sinks:
  ngsiem_sbfms_iis:
    type: hec
    proxy: none
    token: $IisKey
    url: $IisUrl

  ngsiem_sbfms_app:
    type: hec
    proxy: none
    token: $AppKey
    url: $AppUrl
"@
# UTF-8 without BOM
[IO.File]::WriteAllText($cfg, $yaml, (New-Object Text.UTF8Encoding $false))
Write-Host "Wrote $cfg"

$svc = Get-Service | Where-Object { $_.DisplayName -like '*Log Collector*' -or $_.Name -like '*log-collector*' } | Select-Object -First 1
if (-not $svc) { throw 'Collector service not found. Check services.msc for "Humio Log Collector".' }
Set-Service -Name $svc.Name -StartupType Automatic
Restart-Service -Name $svc.Name -Force
Start-Sleep -Seconds 3
Get-Service -Name $svc.Name | Select-Object Name, DisplayName, Status, StartType | Format-Table -AutoSize

Write-Host "Sources:"
Write-Host "  $iisGlob  ->  IIS connector"
Write-Host "  $appGlob  ->  App connector"
Write-Host "If the service stops again, run it in the foreground to see the error:"
$exe = (Get-ChildItem $CollectorDir -Filter '*collector.exe' | Select-Object -First 1).FullName
Write-Host "  Stop-Service '$($svc.Name)'; & '$exe' --cfg '$cfg' --log-level debug --log-pretty"
