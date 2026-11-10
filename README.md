# Senarath Bank — Finance Management System (SB-FMS)

Small internal PHP + SQLite web app for the Senarath Bank CrowdStrike lab. It runs on IIS (FastCGI) on the AD/DC host and
produces security/business telemetry in two places:

1. **IIS W3C logs (native)** — every app event is emitted as `X-SB-*` response headers that IIS records through site
   custom log fields (`x-sb-reqid, x-sb-event, x-sb-result, x-sb-severity, x-sb-user, x-sb-role, x-sb-detail`).
2. **Application log** — `C:\SenarathFMS_Logs\app\sbfms-yyyyMMdd.log`, syslog-style header + key=value body.

Both share a request id for correlation. Data is synthetic.

Features: staff sign-in with lockout, dashboard, customers (search/onboard), accounts (open/freeze), deposits,
withdrawals and transfers with teller limits and high-value flags, reports + CSV export, user management, audit-log viewer.

```
app/                 IIS site root (PHP 8.1+, pdo_sqlite)
  includes/          config, DB + seed, logger, auth/RBAC, layout   (blocked from the web)
  data/              SQLite DB + sessions, created at runtime       (blocked from the web)
  assets/            CSS, JS, logos
setup/
  Install-SenarathFMS.ps1   IIS + PHP + site + logging, one shot
  Test-SenarathFMS.ps1      demo traffic generator + log check
DEPLOY.md            step-by-step deploy and verification
```

Quick start on the DC (elevated PowerShell): see **DEPLOY.md**.
