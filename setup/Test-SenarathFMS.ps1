<#
.SYNOPSIS
  Generates realistic demo traffic against Senarath Bank FMS and shows the resulting
  application log and IIS W3C log lines.

.DESCRIPTION
  Scenarios: normal teller day, RBAC violations, brute force + lockout, password spray,
  admin activity (unlock, PII export, high-value deposit), CSRF failure and probing of
  blocked paths. Safe to run repeatedly (teller2 is unlocked again at the end).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\setup\Test-SenarathFMS.ps1
  powershell -ExecutionPolicy Bypass -File .\setup\Test-SenarathFMS.ps1 -Base http://192.168.10.50:8080
#>
param(
    [string]$Base    = 'http://localhost:8080',
    [string]$LogRoot = 'C:\SenarathFMS_Logs',
    [int]   $SiteId  = 20
)
$ErrorActionPreference = 'Stop'
$script:cookies = New-Object System.Net.CookieContainer

function Send([string]$Path, [hashtable]$Form = $null) {
    $req = [System.Net.HttpWebRequest]::Create("$Base/$Path")
    $req.CookieContainer = $script:cookies
    $req.AllowAutoRedirect = $false
    $req.UserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SBFMS-DemoTraffic/1.0'
    if ($Form) {
        $req.Method = 'POST'
        $req.ContentType = 'application/x-www-form-urlencoded'
        $body = ($Form.GetEnumerator() | ForEach-Object { [uri]::EscapeDataString($_.Key) + '=' + [uri]::EscapeDataString([string]$_.Value) }) -join '&'
        $bytes = [Text.Encoding]::UTF8.GetBytes($body)
        $req.ContentLength = $bytes.Length
        $s = $req.GetRequestStream(); $s.Write($bytes, 0, $bytes.Length); $s.Close()
    }
    try { $resp = $req.GetResponse() } catch [System.Net.WebException] { $resp = $_.Exception.Response; if (-not $resp) { throw } }
    $sr = New-Object IO.StreamReader($resp.GetResponseStream()); $html = $sr.ReadToEnd(); $sr.Close()
    $o = [pscustomobject]@{ Code = [int]$resp.StatusCode; Event = $resp.Headers['X-SB-Event']; ReqId = $resp.Headers['X-SB-ReqId']; Html = $html }
    $resp.Close()
    $m = if ($Form) { 'POST' } else { 'GET' }
    $color = if ($o.Code -ge 400) { 'Yellow' } else { 'Gray' }
    Write-Host ('  {0,3} {1,-4} {2,-42} {3,-38} {4}' -f $o.Code, $m, $Path, $o.Event, $o.ReqId) -ForegroundColor $color
    return $o
}
function NewSession { $script:cookies = New-Object System.Net.CookieContainer }
function Csrf([string]$Path) { $r = Send $Path; if ($r.Html -match 'name="csrf" value="([a-f0-9]+)"') { return $Matches[1] }; throw "No CSRF token on $Path" }
function Login([string]$User, [string]$Pass) { $t = Csrf 'login.php'; Send 'login.php' @{ csrf = $t; username = $User; password = $Pass } | Out-Null }
function Title($t) { Write-Host "`n### $t" -ForegroundColor Cyan }

Title '1. Normal teller day (teller1) incl. typo, limits and RBAC violations'
NewSession
Login 'teller1' 'teller@123'                    # typo -> auth.login.failure
Login 'teller1' 'Teller@123'
Send 'dashboard.php' | Out-Null
Send 'customers.php?q=Perera' | Out-Null
Send 'customers.php?id=1' | Out-Null
Send 'accounts.php?id=2' | Out-Null
$t = Csrf 'transactions.php?tab=deposit'
Send 'transactions.php' @{ csrf = $t; action = 'deposit';  account = '100100000002'; amount = '25000';   narration = 'Cash deposit' } | Out-Null
Send 'transactions.php' @{ csrf = $t; action = 'withdraw'; account = '100100000002'; amount = '99999999'; narration = 'Cash withdrawal' } | Out-Null
Send 'transactions.php' @{ csrf = $t; action = 'deposit';  account = '100100000002'; amount = '750000';  narration = 'Large cash' } | Out-Null
Send 'transactions.php' @{ csrf = $t; action = 'transfer'; from = '100100000002'; to = '100100000005'; amount = '10000'; narration = 'Rent' } | Out-Null
Send 'reports.php' | Out-Null                      # 403 access.denied
Send 'reports.php?export=customers' | Out-Null     # 403 access.denied
Send 'users.php' | Out-Null                        # 403 access.denied
Send 'audit.php' | Out-Null                        # 403 access.denied
Send 'logout.php' | Out-Null

Title '2. Brute force against teller2 (-> account lockout)'
NewSession
foreach ($p in 'password', '123456', 'Teller123', 'Galle@123', 'Shehani1', 'Welcome1') { Login 'teller2' $p }

Title '3. Password spray (one password, many users)'
NewSession
foreach ($u in 'admin', 'manager1', 'auditor1', 'administrator', 'sqladmin', 'svc_backup') { Login $u 'Welcome123!' }

Title '4. Admin activity (unlock, PII export, high-value deposit)'
NewSession
Login 'admin' 'Admin@123'
$t = Csrf 'users.php'
Send 'users.php' @{ csrf = $t; action = 'unlock'; user_id = '4' } | Out-Null
Send 'audit.php' | Out-Null
Send 'reports.php' | Out-Null
Send 'reports.php?export=transactions' | Out-Null
Send 'reports.php?export=customers' | Out-Null
$t = Csrf 'transactions.php?tab=deposit'
Send 'transactions.php' @{ csrf = $t; action = 'deposit'; account = '100100000003'; amount = '1500000'; narration = 'Property sale proceeds' } | Out-Null
Send 'transactions.php' @{ action = 'deposit'; account = '100100000003'; amount = '1' } | Out-Null   # missing CSRF token
Send 'logout.php' | Out-Null

Title '5. Probing blocked paths (IIS-only telemetry, no app event)'
NewSession
foreach ($p in 'data/sbfms.sqlite', 'includes/config.php', 'web.config', '.git/config', 'phpinfo.php') { Send $p | Out-Null }

# ---------------------------------------------------------------------------
Title 'Flushing IIS log buffer'
netsh http flush logbuffer | Out-Null
Start-Sleep -Seconds 2

$app = Join-Path "$LogRoot\app" ('sbfms-' + (Get-Date -Format 'yyyyMMdd') + '.log')
Title "Application log (last 8 lines): $app"
if (Test-Path $app) { Get-Content $app -Tail 8 } else { Write-Warning 'App log not found - check permissions on C:\SenarathFMS_Logs\app' }

$iisDir = "$LogRoot\IIS\W3SVC$SiteId"
$iis = Get-ChildItem $iisDir -Filter 'u_ex*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 1
Title "IIS W3C log (fields + last 8 lines): $($iis.FullName)"
if ($iis) {
    Get-Content $iis.FullName | Where-Object { $_ -like '#Fields:*' } | Select-Object -Last 1
    Get-Content $iis.FullName -Tail 8
} else { Write-Warning "No IIS log yet in $iisDir - wait 60s or re-run 'netsh http flush logbuffer'" }
