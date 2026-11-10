<#
.SYNOPSIS
  One-shot installer for Senarath Bank FMS on IIS (Windows Server / AD DC).

.DESCRIPTION
  Installs IIS + CGI, VC++ runtime, PHP (NTS x64), registers PHP FastCGI,
  creates the SenarathFMS app pool + site, configures W3C logging with the
  X-SB-* custom fields, sets folder permissions and opens the firewall port.
  Safe to run again (idempotent).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\setup\Install-SenarathFMS.ps1
  powershell -ExecutionPolicy Bypass -File .\setup\Install-SenarathFMS.ps1 -Port 8080 -PhpZip C:\Temp\php-8.3.x-nts-Win32-vs16-x64.zip
#>
[CmdletBinding()]
param(
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$SiteName = 'SenarathFMS',
    [int]   $SiteId   = 20,
    [int]   $Port     = 8080,
    [string]$PhpDir   = 'C:\PHP',
    [string]$PhpZip   = '',
    [string]$LogRoot  = 'C:\SenarathFMS_Logs'
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
function Step($m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "    [OK] $m" -ForegroundColor Green }

# --- 0. admin check -----------------------------------------------------------
$id = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $id.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this script from an elevated (Run as Administrator) PowerShell.' }

$AppDir  = Join-Path $RepoRoot 'app'
$DataDir = Join-Path $AppDir 'data'
$PhpCgi  = Join-Path $PhpDir 'php-cgi.exe'
$appcmd  = "$env:windir\system32\inetsrv\appcmd.exe"
if (-not (Test-Path (Join-Path $AppDir 'index.php'))) { throw "App not found at $AppDir. Run the script from inside the cloned repo." }

# --- 1. IIS -------------------------------------------------------------------
Step 'Installing IIS role + CGI (FastCGI)'
$features = 'Web-Server','Web-Default-Doc','Web-Static-Content','Web-Http-Errors','Web-Http-Logging',
            'Web-Request-Monitor','Web-Filtering','Web-CGI','Web-Mgmt-Console','Web-Scripting-Tools'
if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) {
    Install-WindowsFeature -Name $features | Out-Null
} else {
    # Windows 10/11 fallback
    Enable-WindowsOptionalFeature -Online -All -NoRestart -FeatureName IIS-WebServerRole,IIS-WebServer,IIS-CGI,IIS-HttpLogging,IIS-RequestFiltering,IIS-ManagementConsole,IIS-ManagementScriptingTools | Out-Null
}
Import-Module WebAdministration
Ok 'IIS ready'

# --- 2. VC++ runtime ----------------------------------------------------------
Step 'Checking Visual C++ 2015-2022 x64 runtime'
$vc = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
if (-not $vc -or $vc.Installed -ne 1) {
    $vcExe = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Invoke-WebRequest 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vcExe -UseBasicParsing
    Start-Process $vcExe -ArgumentList '/install','/quiet','/norestart' -Wait
    Ok 'VC++ runtime installed'
} else { Ok "VC++ runtime present ($($vc.Version))" }

# --- 3. PHP -------------------------------------------------------------------
Step "Installing PHP to $PhpDir"
if (-not (Test-Path $PhpCgi)) {
    if (-not $PhpZip) {
        $rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
        $ver = $rel.PSObject.Properties | Where-Object { $_.Name -match '^8\.\d+$' } | Sort-Object { [version]$_.Name } | Select-Object -Last 1
        $nts = $ver.Value.PSObject.Properties | Where-Object { $_.Name -match '^nts-vs\d+-x64$' } | Select-Object -First 1
        $url = 'https://windows.php.net/downloads/releases/' + $nts.Value.zip.path
        $PhpZip = Join-Path $env:TEMP $nts.Value.zip.path
        Write-Host "    Downloading $url"
        Invoke-WebRequest $url -OutFile $PhpZip -UseBasicParsing
    }
    New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null
    Expand-Archive -Path $PhpZip -DestinationPath $PhpDir -Force
}
$ini = Join-Path $PhpDir 'php.ini'
if (-not (Test-Path $ini)) { Copy-Item (Join-Path $PhpDir 'php.ini-production') $ini }
if (-not (Select-String -Path $ini -SimpleMatch 'SBFMS overrides' -Quiet)) {
    New-Item -ItemType Directory -Force -Path "$LogRoot\php" | Out-Null
@"

; ---- SBFMS overrides (added by Install-SenarathFMS.ps1) ----
extension_dir = "$PhpDir\ext"
extension = pdo_sqlite
extension = sqlite3
cgi.force_redirect = 0
cgi.fix_pathinfo = 1
fastcgi.impersonate = 1
fastcgi.logging = 0
date.timezone = Asia/Colombo
expose_php = Off
display_errors = Off
log_errors = On
error_log = "$LogRoot\php\php_errors.log"
"@ | Add-Content -Path $ini -Encoding ASCII
}
$mods = & (Join-Path $PhpDir 'php.exe') -m
if ($mods -notcontains 'pdo_sqlite') { throw 'pdo_sqlite not loaded - check php.ini and the VC++ runtime' }
Ok ((& (Join-Path $PhpDir 'php.exe') -v | Select-Object -First 1))

# --- 4. FastCGI + handler (server level) ---------------------------------------
Step 'Registering PHP FastCGI handler'
if (-not ((& $appcmd list config /section:system.webServer/fastCgi) -match [regex]::Escape($PhpCgi))) {
    & $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$PhpCgi',activityTimeout='300',requestTimeout='300']" /commit:apphost | Out-Null
}
if (-not ((& $appcmd list config /section:system.webServer/handlers) -match 'PHP_via_FastCGI')) {
    & $appcmd set config /section:system.webServer/handlers "/+[name='PHP_via_FastCGI',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$PhpCgi',resourceType='Either']" /commit:apphost | Out-Null
}
Ok 'FastCGI + *.php handler registered'

# --- 5. folders ------------------------------------------------------------------
Step 'Creating folders'
foreach ($d in "$LogRoot\app", "$LogRoot\IIS", "$LogRoot\php", $DataDir) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
Ok "Logs: $LogRoot   Data: $DataDir"

# --- 6. app pool + site -------------------------------------------------------------
Step "Creating app pool and site '$SiteName' on port $Port"
if (-not (Test-Path "IIS:\AppPools\$SiteName")) { New-WebAppPool -Name $SiteName | Out-Null }
Set-ItemProperty "IIS:\AppPools\$SiteName" -Name managedRuntimeVersion -Value ''
Set-ItemProperty "IIS:\AppPools\$SiteName" -Name processModel.identityType -Value ApplicationPoolIdentity
if (-not (Test-Path "IIS:\Sites\$SiteName")) {
    New-Website -Name $SiteName -Id $SiteId -Port $Port -PhysicalPath $AppDir -ApplicationPool $SiteName | Out-Null
} else {
    Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $AppDir
    Set-ItemProperty "IIS:\Sites\$SiteName" -Name applicationPool -Value $SiteName
}
# anonymous requests run as the app pool identity (so PHP can write DB + logs)
Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $SiteName -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name userName -Value ''
Ok 'Site created'

# --- 7. W3C logging + custom fields ---------------------------------------------------
Step 'Configuring IIS W3C logging with X-SB-* custom fields'
$lf = "system.applicationHost/sites/site[@name='$SiteName']/logFile"
$ps = 'MACHINE/WEBROOT/APPHOST'
Set-WebConfigurationProperty -PSPath $ps -Filter $lf -Name logFormat -Value 'W3C'
Set-WebConfigurationProperty -PSPath $ps -Filter $lf -Name directory -Value "$LogRoot\IIS"
Set-WebConfigurationProperty -PSPath $ps -Filter $lf -Name period -Value 'Daily'
Set-WebConfigurationProperty -PSPath $ps -Filter $lf -Name logExtFileFlags -Value 'Date,Time,ClientIP,UserName,SiteName,ComputerName,ServerIP,Method,UriStem,UriQuery,HttpStatus,Win32Status,BytesSent,BytesRecv,TimeTaken,ServerPort,UserAgent,Referer,ProtocolVersion,Host,HttpSubStatus'
Clear-WebConfiguration -PSPath $ps -Filter "$lf/customFields" -ErrorAction SilentlyContinue
$fields = [ordered]@{
    'x-sb-reqid' = 'X-SB-ReqId'; 'x-sb-event' = 'X-SB-Event'; 'x-sb-result' = 'X-SB-Result'; 'x-sb-severity' = 'X-SB-Severity'
    'x-sb-user'  = 'X-SB-User';  'x-sb-role'  = 'X-SB-Role';  'x-sb-detail' = 'X-SB-Detail'
}
foreach ($k in $fields.Keys) {
    Add-WebConfigurationProperty -PSPath $ps -Filter "$lf/customFields" -Name '.' -Value @{ logFieldName = $k; sourceName = $fields[$k]; sourceType = 'ResponseHeader' }
}
Ok "W3C log -> $LogRoot\IIS\W3SVC$SiteId\u_ex*.log"

# --- 8. permissions ------------------------------------------------------------------
Step 'Setting NTFS permissions'
$poolId = "IIS AppPool\$SiteName"
icacls $AppDir         /grant "${poolId}:(OI)(CI)RX" /T /C /Q | Out-Null
icacls $DataDir        /grant "${poolId}:(OI)(CI)M"  /T /C /Q | Out-Null
icacls "$LogRoot\app"  /grant "${poolId}:(OI)(CI)M"  /T /C /Q | Out-Null
icacls "$LogRoot\php"  /grant "${poolId}:(OI)(CI)M"  /T /C /Q | Out-Null
icacls $PhpDir         /grant "IIS_IUSRS:(OI)(CI)RX" /T /C /Q | Out-Null
Ok "$poolId can read the app and write data + logs"

# --- 9. firewall ------------------------------------------------------------------------
Step "Opening TCP $Port in Windows Firewall"
if (-not (Get-NetFirewallRule -DisplayName "SenarathFMS HTTP $Port" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName "SenarathFMS HTTP $Port" -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow -Profile Domain,Private | Out-Null
}
Ok 'Firewall rule present'

# --- 10. start + smoke test ---------------------------------------------------------------
Step 'Starting site and running smoke test'
Start-WebAppPool -Name $SiteName -ErrorAction SilentlyContinue
Start-Website -Name $SiteName -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2
$r = Invoke-WebRequest "http://localhost:$Port/login.php" -UseBasicParsing
if ($r.StatusCode -ne 200 -or -not $r.Headers['X-SB-Event']) { throw "Smoke test failed: HTTP $($r.StatusCode)" }
Ok "HTTP $($r.StatusCode)  X-SB-Event=$($r.Headers['X-SB-Event'])  X-SB-ReqId=$($r.Headers['X-SB-ReqId'])"

Write-Host "`nSenarath Bank FMS is live:" -ForegroundColor Yellow
Write-Host "  http://localhost:$Port/          (on this server)"
Write-Host "  http://$($env:COMPUTERNAME):$Port/   (from the lab network)"
Write-Host "  App log : $LogRoot\app\sbfms-yyyyMMdd.log"
Write-Host "  IIS log : $LogRoot\IIS\W3SVC$SiteId\u_exyyMMdd.log  (flush with: netsh http flush logbuffer)"
