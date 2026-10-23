# INFOS ERP — Server Performance Setup (IIS + SQL Server)

This guide covers the server setup that removes the long delay on the first page after the site
has been idle (for example Login → Branch Selection taking ~29 seconds).
No application code is involved; all four changes are IIS / SQL Server settings.

---

## Table of Contents
1. [Background — Why the First Load Was Slow](#1-background--why-the-first-load-was-slow)
2. [Environment](#2-environment)
3. [Before You Start — Backup](#3-before-you-start--backup)
4. [Change 1 — App Pool Always Running, No Idle Time-out](#4-change-1--app-pool-always-running-no-idle-time-out)
5. [Change 2 — Application Initialization + Preload Enabled](#5-change-2--application-initialization--preload-enabled)
6. [Change 3 — App Pool .NET CLR Version: No Managed Code](#6-change-3--app-pool-net-clr-version-no-managed-code)
7. [Change 4 — SQL Server Min / Max Server Memory](#7-change-4--sql-server-min--max-server-memory)
8. [Verification Checklist](#8-verification-checklist)
9. [Rollback](#9-rollback)
10. [Applied Status on This Server](#10-applied-status-on-this-server)

---

## 1. Background — Why the First Load Was Slow

| Finding | Detail |
|---|---|
| App pool shut down when idle | `ApprovalApp` pool had **Idle Time-out = 20 min** and **Start Mode = OnDemand**. Windows System log showed event **5186** "worker process ... was shutdown due to inactivity". |
| Cold start on next login | The next request had to start a new `w3wp.exe`, new `php-cgi.exe` processes and new SQL connections from scratch. |
| Unneeded .NET load | The pool loaded the .NET 4.0 CLR even though the site is PHP only. |
| Low free memory | Only ~1.1 GB of 16 GB RAM was free; SQL Server had been trimmed to ~384 MB, so data was re-read from disk after idle periods. |

When warm, the same pages respond in 2–70 ms, so the delay was purely the cold start plus memory pressure.

---

## 2. Environment

| Item | Value |
|---|---|
| IIS site | `ApprovalApp` (binding `http/*:8080`) |
| Physical path | `C:\inetpub\wwwroot\Approval` |
| Application pool | `ApprovalApp` |
| PHP handler | FastCGI → `C:\PHP84\php-cgi.exe` |
| SQL Server instance | `DESKTOP-GP23B1O\MSSQL2024` |
| Database | `van` |

> On another server, replace the site / pool / instance names with the ones used there.

All `appcmd` commands below must be run from an **elevated (Run as Administrator)** PowerShell or Command Prompt.
`appcmd.exe` lives in `%windir%\system32\inetsrv\`.

---

## 3. Before You Start — Backup

### IIS configuration

```powershell
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"

# IIS built-in backup (restorable with appcmd restore backup)
& $appcmd add backup "pre-coldstart-20261015"

# Plain file copy of applicationHost.config
Copy-Item "$env:windir\system32\inetsrv\config\applicationHost.config" `
          "$env:windir\system32\inetsrv\config\applicationHost.config.pre-coldstart.bak"
```

### SQL Server

Note the current memory values before changing them:

```sql
SELECT name, value, value_in_use
FROM   sys.configurations
WHERE  name IN ('max server memory (MB)', 'min server memory (MB)');
```

Original values on this server: **max = 2147483647 (unlimited)**, **min = 0**.

---

## 4. Change 1 — App Pool Always Running, No Idle Time-out

**Goal:** the worker process starts with IIS and is never shut down for inactivity.

| Setting | Before | After |
|---|---|---|
| Start Mode | `OnDemand` | `AlwaysRunning` |
| Idle Time-out (minutes) | `20` | `0` (disabled) |

### Command

```powershell
& $appcmd set apppool "approval" /startMode:AlwaysRunning /processModel.idleTimeout:00:00:00
```

### IIS Manager (GUI) alternative

1. IIS Manager → **Application Pools** → select **ApprovalApp** → **Advanced Settings...**
2. **General → Start Mode** = `AlwaysRunning`
3. **Process Model → Idle Time-out (minutes)** = `0`
4. Click **OK**.

---

## 5. Change 2 — Application Initialization + Preload Enabled

**Goal:** IIS warms the site up automatically after an IIS restart, app pool recycle or server reboot,
so the first user never waits for the cold start.

`Preload Enabled` only works when the IIS **Application Initialization** feature is installed.

### Step 2a — Install the Application Initialization feature

Windows 10 / 11 (client):

```powershell
Enable-WindowsOptionalFeature -Online -FeatureName IIS-ApplicationInit -All -NoRestart
```

Windows Server:

```powershell
Install-WindowsFeature Web-AppInit
```

GUI alternative: **Turn Windows features on or off** → Internet Information Services →
World Wide Web Services → Application Development Features → tick **Application Initialization**.

> On Windows 10 / 11 this feature finishes installing only after a **Windows restart**
> (status shows `EnablePending` until then).
> Check with: `Get-WindowsOptionalFeature -Online -FeatureName IIS-ApplicationInit`
> and confirm `C:\Windows\System32\inetsrv\warmup.dll` exists after the restart.

### Step 2b — Enable Preload on the site

| Setting | Before | After |
|---|---|---|
| Preload Enabled (site `ApprovalApp`) | `False` | `True` |

```powershell
& $appcmd set app "approval/" /preloadEnabled:true
```

GUI alternative: IIS Manager → **Sites** → **ApprovalApp** → **Advanced Settings...** →
**General → Preload Enabled** = `True`.

---

## 6. Change 3 — App Pool .NET CLR Version: No Managed Code

**Goal:** stop loading the .NET runtime into a PHP-only worker process (faster start, less memory).

| Setting | Before | After |
|---|---|---|
| .NET CLR Version | `v4.0` | `No Managed Code` |

### Command

```powershell
& $appcmd set apppool "approval" /managedRuntimeVersion:""
```

### IIS Manager (GUI) alternative

IIS Manager → **Application Pools** → right-click **ApprovalApp** → **Basic Settings...** →
**.NET CLR version** = `No Managed Code` → **OK**.

> Only do this for pools that run PHP (or other non-.NET) sites. Do **not** change pools that host ASP.NET applications.

---

## 7. Change 4 — SQL Server Min / Max Server Memory

**Goal:** keep SQL Server's data cache in memory (stop Windows trimming it to a few hundred MB),
while leaving enough RAM for IIS, PHP and other applications.

| Setting | Before | After |
|---|---|---|
| max server memory (MB) | `2147483647` (unlimited) | `4096` |
| min server memory (MB) | `0` | `1024` |

Requires a login with the **sysadmin** role. Takes effect immediately — **no SQL Server restart needed**.

### T-SQL (run in SSMS against the instance, e.g. `DESKTOP-GP23B1O\MSSQL2024`)

```sql
EXEC sp_configure 'show advanced options', 1;
RECONFIGURE;

EXEC sp_configure 'max server memory (MB)', 4096;
RECONFIGURE;

EXEC sp_configure 'min server memory (MB)', 1024;
RECONFIGURE;
```

### SSMS (GUI) alternative

SSMS → right-click the server → **Properties** → **Memory** →
**Minimum server memory (in MB)** = `1024`, **Maximum server memory (in MB)** = `4096` → **OK**.

### Sizing guidance

| Server RAM | Suggested max | Suggested min |
|---|---|---|
| 8 GB | 2048–3072 MB | 512–1024 MB |
| 16 GB (this server) | 4096 MB | 1024 MB |
| 32 GB (dedicated DB server) | 24576 MB or more | 4096 MB |

Note: SQL Server only holds memory down to *min* after it has grown past that amount.
Freeing RAM on the machine (closing unused desktop applications) still helps.

---

## 8. Verification Checklist

Run in an elevated PowerShell:

```powershell
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"

& $appcmd list apppool ApprovalApp /text:startMode               # expect: AlwaysRunning
& $appcmd list apppool ApprovalApp /text:processModel.idleTimeout # expect: 00:00:00
& $appcmd list apppool ApprovalApp /text:managedRuntimeVersion   # expect: (empty)
& $appcmd list app "ApprovalApp/" /text:preloadEnabled           # expect: true
Get-WindowsOptionalFeature -Online -FeatureName IIS-ApplicationInit | Select-Object State   # expect: Enabled (after restart)

# Worker running without .NET (no -v "v4.0" in the command line)
Get-CimInstance Win32_Process -Filter "Name='w3wp.exe'" | Select-Object ProcessId, CreationDate, CommandLine

# Page response time
curl.exe -s -L -o NUL -w "login %{http_code} %{time_total}s`n" "http://localhost:8080/login"
curl.exe -s -L -o NUL -w "branchselection %{http_code} %{time_total}s`n" "http://localhost:8080/branchselection"
```

SQL Server:

```sql
SELECT name, value, value_in_use
FROM   sys.configurations
WHERE  name IN ('max server memory (MB)', 'min server memory (MB)');
-- expect: max 4096 / 4096, min 1024 / 1024
```

---

## 9. Rollback

### IIS — restore the full backup

```powershell
& "$env:windir\system32\inetsrv\appcmd.exe" restore backup "pre-coldstart-20261015"
```

### IIS — revert individual settings

```powershell
& $appcmd set apppool "ApprovalApp" /startMode:OnDemand /processModel.idleTimeout:00:20:00
& $appcmd set apppool "ApprovalApp" /managedRuntimeVersion:v4.0
& $appcmd set app "ApprovalApp/" /preloadEnabled:false
```

Remove the Application Initialization feature (optional):

```powershell
Disable-WindowsOptionalFeature -Online -FeatureName IIS-ApplicationInit -NoRestart
```

### SQL Server — revert memory

```sql
EXEC sp_configure 'max server memory (MB)', 2147483647;
RECONFIGURE;
EXEC sp_configure 'min server memory (MB)', 0;
RECONFIGURE;
```

---

## 10. Applied Status on This Server

Applied on **15-Oct-2026**.

| # | Change | Status |
|---|---|---|
| — | IIS backup `pre-coldstart-20261015` + `applicationHost.config.pre-coldstart.bak` | Done |
| 1 | `ApprovalApp` pool: Start Mode `AlwaysRunning`, Idle Time-out `0` | Done — active |
| 2 | Application Initialization feature installed, site Preload Enabled `True` | Done — **active after next Windows restart** (feature state `EnablePending`) |
| 3 | `ApprovalApp` pool: .NET CLR `No Managed Code` | Done — active (worker restarted without .NET) |
| 4 | SQL Server min `1024` MB / max `4096` MB | Done — active |

Post-change check: `login` 0.07 s, `branchselection` 0.09 s on `http://localhost:8080`.
