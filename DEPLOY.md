# Senarath Bank FMS — Deploy on Windows Server 2025 (standalone) and ship logs to Falcon NG-SIEM

Target: a dedicated internal PC running **Windows Server 2025** (workgroup — no AD, not a DC).
Everything below is copy-paste. Run all server commands in **PowerShell as Administrator**.

| Item | Value |
|---|---|
| Repo folder on server | `C:\SenarathFMS` (IIS serves `C:\SenarathFMS\app`) |
| URL | `http://localhost:8080` (on the server) · `http://<server-ip>:8080` (lab network) |
| IIS site / app pool / site ID | `SenarathFMS` / `SenarathFMS` / `20` |
| PHP | `C:\PHP` (NTS x64, FastCGI) |
| App log | `C:\SenarathFMS_Logs\app\sbfms-yyyyMMdd.log` |
| IIS W3C log | `C:\SenarathFMS_Logs\IIS\W3SVC20\u_exyyMMdd.log` |
| Database | `C:\SenarathFMS\app\data\sbfms.sqlite` (auto-created with synthetic data on first request) |
| Log shipping | Falcon LogScale Collector → 2 HEC data connectors in Next-Gen SIEM |

Lab accounts (synthetic, lab only):

| Username | Password | Role |
|---|---|---|
| admin | Admin@123 | admin (everything) |
| manager1 | Manager@123 | manager (high-value txns, freeze, reports) |
| teller1 | Teller@123 | teller (customers, accounts, txns ≤ LKR 500k) |
| teller2 | Teller@123 | teller (used by the brute-force demo) |
| auditor1 | Auditor@123 | auditor (reports, exports, audit log) |

---

## 0. Prepare the server (once)

```powershell
# optional: give it a clear name first - it appears in every IIS and app log line (reboots)
Rename-Computer -NewName SBFMS-WEB01 -Restart

# note the server IP (use a static IP or DHCP reservation so the URL doesn't change)
Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' } | Select-Object InterfaceAlias, IPAddress

# check the clock is synchronised (SIEM correlation depends on it)
w32tm /query /status

# check port 8080 is free (no output = free)
Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue
```

## 1. Push the code to your repo (your workstation)

```powershell
cd "C:\Users\Laptop Outlet\Documents\ADD_APP"
git init
git add .
git commit -m "Senarath Bank FMS - IIS/PHP/SQLite demo app"
git branch -M main
git remote add origin https://github.com/<your-org>/senarath-fms.git
git push -u origin main
```
Already pushed earlier? Just: `git add . ; git commit -m "Standalone Server 2025 + collector" ; git push`

## 2. Get the code onto the server

**2.1 Enable TLS 1.2 for this PowerShell window**
```powershell
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
```

**2.2 Install Git (skip if `git --version` already works)**
```powershell
$rel = Invoke-RestMethod https://api.github.com/repos/git-for-windows/git/releases/latest
$url = ($rel.assets | Where-Object name -match '^Git-.*-64-bit\.exe$').browser_download_url
Invoke-WebRequest $url -OutFile "$env:TEMP\git-setup.exe" -UseBasicParsing
Start-Process "$env:TEMP\git-setup.exe" -ArgumentList '/VERYSILENT','/NORESTART' -Wait
$env:Path += ';C:\Program Files\Git\cmd'
git --version
```

**2.3 Clone**
```powershell
git clone https://github.com/<your-org>/senarath-fms.git C:\SenarathFMS
cd C:\SenarathFMS
```
Private repo: Git asks for credentials — use your GitHub username and a Personal Access Token as the password.

*No Git on the server?* Download the repo ZIP on another machine, copy it over, then:
```powershell
Expand-Archive .\senarath-fms-main.zip -DestinationPath C:\ ; Rename-Item C:\senarath-fms-main C:\SenarathFMS
```

## 3. Install and publish (one command)

```powershell
cd C:\SenarathFMS
Set-ExecutionPolicy -Scope Process Bypass -Force
.\setup\Install-SenarathFMS.ps1
```
It ends with `[OK] HTTP 200  X-SB-Event=page.view ...` and prints the URLs and the server IPs.

Options:
- `-AllowFrom 192.168.10.0/24` — only allow your lab subnet through the firewall (default `Any`; the rule covers all firewall profiles because a workgroup server is usually on the *Public* profile)
- `-Port 8090` — if 8080 is taken
- `-PhpZip C:\Temp\php-8.x-nts-Win32-vs17-x64.zip` — offline PHP install (download the **NTS x64** ZIP from windows.php.net on another machine)

Section 8 lists what the script does, command by command, if you prefer to run it manually.

## 4. Open the app

```powershell
Start-Process http://localhost:8080
```
Sign in as `admin / Admin@123`.

From other lab machines browse `http://<server-ip>:8080`. For a friendly name instead, pick one:
```powershell
# a) on each client (PowerShell as Administrator)
Add-Content C:\Windows\System32\drivers\etc\hosts "`n<server-ip>  fms.senarath.local"

# b) or once, on the lab DNS server (your lab DC)
Add-DnsServerResourceRecordA -ZoneName senarath.local -Name fms -IPv4Address <server-ip>
```
Then browse `http://fms.senarath.local:8080`.

## 5. Verify logging on the server

**5.1 Generate demo traffic** (teller day, RBAC denials, brute force + lockout, password spray, PII export, high-value txn, CSRF, path probing — all against the app's own users, nothing touches AD)
```powershell
cd C:\SenarathFMS
.\setup\Test-SenarathFMS.ps1
```
It prints every request (status, event, request id) and then the tail of both logs.

**5.2 Force IIS to write its buffer to disk** (IIS otherwise flushes about every 60 s)
```powershell
netsh http flush logbuffer
```

**5.3 Application log**
```powershell
Get-ChildItem C:\SenarathFMS_Logs\app
Get-Content "C:\SenarathFMS_Logs\app\sbfms-$(Get-Date -Format yyyyMMdd).log" -Tail 20
Get-Content "C:\SenarathFMS_Logs\app\sbfms-$(Get-Date -Format yyyyMMdd).log" -Wait -Tail 5    # live, Ctrl+C to stop
Select-String -Path C:\SenarathFMS_Logs\app\*.log -Pattern 'severity=(WARN|HIGH)' | Select-Object -Last 15
```

**5.4 IIS W3C log with the X-SB-* custom fields**
```powershell
Get-ChildItem C:\SenarathFMS_Logs\IIS\W3SVC20
$iis = (Get-ChildItem C:\SenarathFMS_Logs\IIS\W3SVC20\u_ex*.log | Sort-Object LastWriteTime)[-1].FullName
Select-String -Path $iis -Pattern '^#Fields' | Select-Object -Last 1
Get-Content $iis -Tail 20
Select-String -Path $iis -Pattern 'auth.login.failure|auth.account.locked|access.denied' | Select-Object -Last 10
```

**5.5 Correlate one request across both logs** (request id from the test output or the page footer)
```powershell
$id = '<request id>'
Select-String -Path C:\SenarathFMS_Logs\app\*.log -SimpleMatch "req_id=$id"
Select-String -Path C:\SenarathFMS_Logs\IIS\W3SVC20\*.log -SimpleMatch " $id "
```

**5.6 In the browser**: sign in as `admin` or `auditor1` → **Audit Logs** shows the parsed app log with severity counters and filters.

**5.7 Check the IIS logging configuration**
```powershell
Import-Module WebAdministration
Get-WebConfiguration "system.applicationHost/sites/site[@name='SenarathFMS']/logFile/customFields/add" | Select-Object logFieldName, sourceName, sourceType
Get-ItemProperty IIS:\Sites\SenarathFMS -Name logFile | Select-Object directory, logFormat, period
```

## 6. Ship the logs to Falcon Next-Gen SIEM (LogScale Collector)

The collector on this server reads both log folders and sends each one to its own HEC data connector, so each source gets its own parser in NG-SIEM.

**6.1 Create two HEC data connectors** (Falcon console)
1. **Next-Gen SIEM → Data onboarding** → tile **HEC / HTTP Event Collector**.
2. Create the first one, e.g. name `SBFMS-IIS`, and choose the parser you want for the IIS log. Save, then **generate the API key** and copy the **API key** and **API URL**.
3. Repeat for `SBFMS-App` (the application log) and copy its API key and URL.

**6.2 Install the collector**
1. Falcon console → **Support and resources → Tool downloads** → download the latest **LogScale Collector for Windows (x64)** MSI and copy it to `C:\Temp` on the server.
2. Install it silently:
```powershell
$msi = (Get-ChildItem C:\Temp\*ollector*.msi | Sort-Object LastWriteTime)[-1].FullName
Start-Process msiexec.exe -ArgumentList '/i', "`"$msi`"", '/qn' -Wait
Get-Service | Where-Object DisplayName -like '*Log Collector*'
```
Default install folder: `C:\Program Files (x86)\CrowdStrike\Humio Log Collector\` (the MSI creates the service but leaves it stopped / Manual).

**6.3 Configure and start it** (paste the URLs exactly as shown in the console — the script strips `/services/collector`)
```powershell
cd C:\SenarathFMS
.\setup\Configure-LogCollector.ps1 -IisUrl '<SBFMS-IIS API URL>' -IisKey '<SBFMS-IIS API key>' -AppUrl '<SBFMS-App API URL>' -AppKey '<SBFMS-App API key>'
```
It backs up `config.yaml`, writes the two file sources + two HEC sinks, sets the service to Automatic and restarts it. A hand-editable template of the same config is in `setup\logscale-collector.yaml`.

*Using Fleet Management instead of a local config?* Enroll the collector with the command the console gives you, and paste the `sources:` and `sinks:` blocks from `setup\logscale-collector.yaml` (with your keys/URLs) into the Fleet Management config.

**6.4 Verify**
```powershell
# service running
Get-Service | Where-Object DisplayName -like '*Log Collector*' | Select-Object Name, Status, StartType

# generate fresh events
.\setup\Test-SenarathFMS.ps1
netsh http flush logbuffer

# if nothing arrives: run the collector in the foreground and read the errors (Ctrl+C, then start the service again)
$svc = (Get-Service | Where-Object DisplayName -like '*Log Collector*').Name
$dir = 'C:\Program Files (x86)\CrowdStrike\Humio Log Collector'
Stop-Service $svc
& (Get-ChildItem $dir -Filter '*collector.exe')[0].FullName --cfg "$dir\config.yaml" --log-level debug --log-pretty
Start-Service $svc
```
In the Falcon console, the two connectors show data arriving; in **Next-Gen SIEM → Advanced event search**, search for a request id printed by the test script (last 15 minutes) — it should match one IIS event and one app event.

Notes for the parsers: IIS log files start with `#Software/#Version/#Date/#Fields` header lines (drop lines starting with `#`); IIS times are UTC, the app log carries its own `+05:30` offset.

## 7. Log formats

**IIS W3C** (times are UTC; custom fields appended after the standard ones; IIS writes spaces as `+`). Expected header — confirm yours with step 5.4:
```
#Fields: date time s-sitename s-computername s-ip cs-method cs-uri-stem cs-uri-query s-port cs-username c-ip cs-version cs(User-Agent) cs(Referer) cs-host sc-status sc-substatus sc-win32-status sc-bytes cs-bytes time-taken x-sb-reqid x-sb-event x-sb-result x-sb-severity x-sb-user x-sb-role x-sb-detail
```
`x-sb-detail` holds `key=value;key=value` (values URL-encoded); several events in one request are comma-separated in `x-sb-event` and `|`-separated in `x-sb-detail`. Requests IIS answers itself (blocked paths, static files) carry `-` in the custom fields.

**Application log** (local time; syslog-style header + key=value body):
```
2026-11-10T15:41:11.347+05:30 SBFMS-WEB01 SBFMS[4312]: severity=WARN event=user.role.change result=success user=admin role=admin src_ip=10.10.10.25 req_id=ac8be077d261 session=2bbbbf2beeab method=POST uri=/users.php target_user=teller1 old_role=teller new_role=manager escalation=true ua="Mozilla/5.0 ..."
```

**Event catalogue**

| Event | Severity | When |
|---|---|---|
| page.view | INFO | any page render without another event |
| auth.login.success / auth.logout | INFO | sign-in / sign-out |
| auth.login.failure | WARN | bad password (`reason=bad_password failed_attempts=n`) or `reason=unknown_user` (HTTP 401) |
| auth.account.locked | HIGH | 5th consecutive bad password |
| auth.login.blocked | WARN | sign-in to a locked/disabled account (HTTP 403) |
| auth.session.expired / auth.session.revoked | INFO / WARN | 15 min idle / account disabled while signed in |
| access.denied | WARN | role not allowed for a page/function (HTTP 403) |
| security.csrf.failure | HIGH | POST without a valid form token (HTTP 400) |
| customer.search / customer.view / customer.create | INFO | `customer.view` carries `pii=true` |
| account.view / account.open | INFO | |
| account.freeze / account.unfreeze | WARN | manager/admin |
| txn.deposit / txn.withdrawal / txn.transfer | INFO, WARN when denied | `result=denied reason=teller_limit_exceeded / insufficient_funds / account_frozen` |
| txn.high_value | WARN | amount ≥ LKR 1,000,000 |
| report.export | NOTICE / WARN | WARN for the customer register (bulk PII) |
| audit.view | NOTICE | audit log opened |
| user.create / user.role.change / user.disable / user.enable / user.unlock / user.password.reset | WARN (HIGH for admin role) | `escalation=true` on privilege increase |
| app.error | HIGH | unhandled exception (HTTP 500) |

## 8. Manual install (what the script does, command by command)

```powershell
# 8.1 IIS + CGI
Install-WindowsFeature Web-Server,Web-Default-Doc,Web-Static-Content,Web-Http-Errors,Web-Http-Logging,Web-Request-Monitor,Web-Filtering,Web-CGI,Web-Mgmt-Console,Web-Scripting-Tools
Import-Module WebAdministration

# 8.2 Visual C++ 2015-2022 x64 runtime (needed by PHP)
Invoke-WebRequest https://aka.ms/vs/17/release/vc_redist.x64.exe -OutFile "$env:TEMP\vc_redist.x64.exe" -UseBasicParsing
Start-Process "$env:TEMP\vc_redist.x64.exe" -ArgumentList '/install','/quiet','/norestart' -Wait

# 8.3 PHP NTS x64 -> C:\PHP  (download the "VS16/VS17 x64 Non Thread Safe" ZIP from https://windows.php.net/download/)
Expand-Archive C:\Temp\php-8.x-nts-Win32-vs17-x64.zip -DestinationPath C:\PHP -Force
Copy-Item C:\PHP\php.ini-production C:\PHP\php.ini
New-Item -ItemType Directory -Force C:\SenarathFMS_Logs\php | Out-Null
Add-Content C:\PHP\php.ini "`nextension_dir = `"C:\PHP\ext`"`nextension = pdo_sqlite`nextension = sqlite3`ncgi.force_redirect = 0`ncgi.fix_pathinfo = 1`nfastcgi.impersonate = 1`ndate.timezone = Asia/Colombo`nexpose_php = Off`nlog_errors = On`nerror_log = `"C:\SenarathFMS_Logs\php\php_errors.log`""
C:\PHP\php.exe -m | Select-String sqlite          # must list pdo_sqlite and sqlite3

# 8.4 Register PHP with IIS (server level)
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"
& $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='C:\PHP\php-cgi.exe',activityTimeout='300',requestTimeout='300']" /commit:apphost
& $appcmd set config /section:system.webServer/handlers "/+[name='PHP_via_FastCGI',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='C:\PHP\php-cgi.exe',resourceType='Either']" /commit:apphost

# 8.5 Folders
New-Item -ItemType Directory -Force C:\SenarathFMS_Logs\app, C:\SenarathFMS_Logs\IIS, C:\SenarathFMS\app\data | Out-Null

# 8.6 App pool + site (port 8080, site id 20)
New-WebAppPool -Name SenarathFMS
Set-ItemProperty IIS:\AppPools\SenarathFMS -Name managedRuntimeVersion -Value ''
New-Website -Name SenarathFMS -Id 20 -Port 8080 -PhysicalPath C:\SenarathFMS\app -ApplicationPool SenarathFMS
Set-WebConfigurationProperty -PSPath MACHINE/WEBROOT/APPHOST -Location SenarathFMS -Filter system.webServer/security/authentication/anonymousAuthentication -Name userName -Value ''

# 8.7 W3C logging + custom fields from the X-SB-* response headers
$lf = "system.applicationHost/sites/site[@name='SenarathFMS']/logFile"
Set-WebConfigurationProperty -PSPath MACHINE/WEBROOT/APPHOST -Filter $lf -Name directory -Value C:\SenarathFMS_Logs\IIS
Set-WebConfigurationProperty -PSPath MACHINE/WEBROOT/APPHOST -Filter $lf -Name logExtFileFlags -Value 'Date,Time,ClientIP,UserName,SiteName,ComputerName,ServerIP,Method,UriStem,UriQuery,HttpStatus,Win32Status,BytesSent,BytesRecv,TimeTaken,ServerPort,UserAgent,Referer,ProtocolVersion,Host,HttpSubStatus'
foreach ($h in 'ReqId','Event','Result','Severity','User','Role','Detail') {
  Add-WebConfigurationProperty -PSPath MACHINE/WEBROOT/APPHOST -Filter "$lf/customFields" -Name '.' -Value @{ logFieldName = "x-sb-$($h.ToLower())"; sourceName = "X-SB-$h"; sourceType = 'ResponseHeader' }
}

# 8.8 Permissions
icacls C:\SenarathFMS\app        /grant "IIS AppPool\SenarathFMS:(OI)(CI)RX" /T
icacls C:\SenarathFMS\app\data   /grant "IIS AppPool\SenarathFMS:(OI)(CI)M"  /T
icacls C:\SenarathFMS_Logs\app   /grant "IIS AppPool\SenarathFMS:(OI)(CI)M"  /T
icacls C:\SenarathFMS_Logs\php   /grant "IIS AppPool\SenarathFMS:(OI)(CI)M"  /T
icacls C:\PHP                    /grant "IIS_IUSRS:(OI)(CI)RX" /T

# 8.9 Firewall + start
New-NetFirewallRule -DisplayName "SenarathFMS HTTP 8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Any -RemoteAddress Any
Start-Website SenarathFMS
(Invoke-WebRequest http://localhost:8080/login.php -UseBasicParsing).Headers['X-SB-Event']     # -> page.view
```

## 9. Update, reset, remove

```powershell
# update to latest code
cd C:\SenarathFMS; git pull; Restart-WebAppPool SenarathFMS

# reset the database to fresh synthetic data (recreated on next request)
Stop-WebAppPool SenarathFMS; Remove-Item C:\SenarathFMS\app\data\sbfms.sqlite*; Start-WebAppPool SenarathFMS

# unlock an account without the UI (e.g. admin), optionally with a new password
C:\PHP\php.exe C:\SenarathFMS\setup\unlock-user.php admin
C:\PHP\php.exe C:\SenarathFMS\setup\unlock-user.php admin "NewPass@123"

# remove the site (keeps PHP, logs and the collector)
Remove-Website SenarathFMS; Remove-WebAppPool SenarathFMS; Remove-NetFirewallRule -DisplayName "SenarathFMS HTTP 8080"
```

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Other machines can't open the site | firewall rule must cover the *Public* profile → `Get-NetFirewallRule -DisplayName "SenarathFMS HTTP 8080" \| Select Profile, Enabled`; re-run the installer; test with `Test-NetConnection <server-ip> -Port 8080` from a client |
| HTTP 500.0 / `0x8007007e` or `php-cgi.exe` exits | VC++ runtime missing → step 8.2 |
| HTTP 404.3 or PHP file downloads | handler not registered → step 8.4, then `iisreset` |
| Page says “could not find driver” | `pdo_sqlite` not enabled → check `C:\PHP\php.ini`, `C:\PHP\php.exe -m` |
| “unable to open database file” / error page | app pool can't write `app\data` → re-run 8.8 |
| App log empty | app pool can't write `C:\SenarathFMS_Logs\app` → re-run 8.8 |
| IIS log has no `x-sb-*` columns | re-run 8.7; the new columns start in a new log file / after `iisreset` |
| IIS log not updated yet | `netsh http flush logbuffer` |
| Collector service stops right after start | config error → run it in the foreground (6.4) and read the message; check the URL has no `/services/collector` |
| Collector runs but NG-SIEM shows nothing | wrong API key/URL pairing, or outbound HTTPS blocked → `Test-NetConnection <ingest host> -Port 443` |
| Port 8080 in use | re-run installer with `-Port 8090` |
| Server errors in detail | `Get-Content C:\SenarathFMS_Logs\php\php_errors.log -Tail 20` |
