# One-click setup for the Utility Billing project on a new Windows computer.
# Run it by double-clicking setup.bat. Safe to run again: finished steps are skipped.
#
# What it does:
#   1. Installs Laravel Herd (PHP, Composer, nginx, nvm) if missing
#   2. Installs DBngin and waits until MySQL is running on 127.0.0.1:3306
#   3. Makes sure Node.js / npm are available
#   4. Creates .env, installs Composer and npm packages, builds the frontend
#   5. Creates the database, runs migrations and either seeds it or imports database\backup.sql
#   6. Links the site in Herd and opens it in the browser

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$ProjectDir = $PSScriptRoot
$SiteName = 'utility-billing'
$SiteUrl = "http://$SiteName.test"
$DbName = 'utility_billing'
$DbHost = '127.0.0.1'
$DbPort = 3306
$DbUser = 'root'
$DbPassword = ''
$HerdBin = Join-Path $env:USERPROFILE '.config\herd\bin'
$TempDir = Join-Path $env:TEMP 'utility-billing-setup'
$BackupSql = Join-Path $ProjectDir 'database\backup.sql'

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Ok([string] $Message) {
    Write-Host "    $Message" -ForegroundColor Green
}

function Write-Note([string] $Message) {
    Write-Host "    $Message" -ForegroundColor Yellow
}

function Stop-Setup([string] $Message) {
    Write-Host ''
    Write-Host "SETUP FAILED: $Message" -ForegroundColor Red
    exit 1
}

function Update-SessionPath {
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$HerdBin;$machinePath;$userPath"
}

function Test-Command([string] $Name) {
    return [bool] (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Test-Winget {
    return Test-Command 'winget'
}

function Invoke-Checked([string] $Description, [scriptblock] $Command) {
    Write-Host "    $Description..."
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Stop-Setup "$Description failed (exit code $LASTEXITCODE)."
    }
}

function Save-Download([string] $Url, [string] $FileName) {
    New-Item -ItemType Directory -Force -Path $TempDir | Out-Null
    $target = Join-Path $TempDir $FileName
    Write-Host "    Downloading $Url"
    Invoke-WebRequest -Uri $Url -OutFile $target -UseBasicParsing
    return $target
}

function Test-MySqlPort {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $connect = $client.BeginConnect($DbHost, $DbPort, $null, $null)
        if ($connect.AsyncWaitHandle.WaitOne(1000) -and $client.Connected) {
            return $true
        }
        return $false
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Find-DBnginExe {
    $candidates = @(
        "$env:ProgramFiles\DBngin\DBngin.exe",
        "${env:ProgramFiles(x86)}\DBngin\DBngin.exe",
        "$env:LOCALAPPDATA\Programs\DBngin\DBngin.exe",
        "$env:LOCALAPPDATA\DBngin\DBngin.exe"
    )
    foreach ($path in $candidates) {
        if ($path -and (Test-Path $path)) {
            return $path
        }
    }
    $shortcut = Get-ChildItem -Path "$env:ProgramData\Microsoft\Windows\Start Menu", "$env:APPDATA\Microsoft\Windows\Start Menu" -Recurse -Filter 'DBngin*.lnk' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($shortcut) {
        return $shortcut.FullName
    }
    return $null
}

function Write-PhpScript([string] $Code) {
    New-Item -ItemType Directory -Force -Path $TempDir | Out-Null
    $file = Join-Path $TempDir 'setup-helper.php'
    [IO.File]::WriteAllText($file, $Code, (New-Object System.Text.UTF8Encoding($false)))
    return $file
}

function Invoke-PhpScript([string] $Code) {
    $file = Write-PhpScript $Code
    & php.bat $file | ForEach-Object { Write-Host $_ }
    return $LASTEXITCODE
}

function Set-EnvValue([string] $Content, [string] $Key, [string] $Value) {
    $line = "$Key=$Value"
    $pattern = "(?m)^#?\s*$Key=.*$"
    if ($Content -match $pattern) {
        return [regex]::Replace($Content, $pattern, [System.Text.RegularExpressions.MatchEvaluator] { param($m) $line }, 1)
    }
    return $Content.TrimEnd() + "`r`n$line`r`n"
}

Write-Host '==================================================' -ForegroundColor Cyan
Write-Host '   Utility Billing - one-click setup' -ForegroundColor Cyan
Write-Host '==================================================' -ForegroundColor Cyan
Write-Host "Project folder: $ProjectDir"

Set-Location $ProjectDir
Update-SessionPath

# ---------------------------------------------------------------- 1. Herd
Write-Step 'Laravel Herd (PHP + Composer + web server)'
if (Test-Path (Join-Path $HerdBin 'php.bat')) {
    Write-Ok 'Herd is already installed.'
} else {
    $herdExe = "$env:ProgramFiles\Herd\Herd.exe"
    if (-not (Test-Path $herdExe)) {
        if (Test-Winget) {
            Write-Host '    Installing Herd with winget (accept the Windows admin prompt if it appears)...'
            winget install --id BeyondCode.Herd -e --silent --accept-package-agreements --accept-source-agreements
        } else {
            $installer = Save-Download 'https://herd.laravel.com/download/windows' 'HerdSetup.exe'
            Write-Host '    Running the Herd installer...'
            Start-Process -FilePath $installer -Wait
        }
    }
    if (-not (Test-Path $herdExe)) {
        Stop-Setup 'Herd did not install. Install it manually from https://herd.laravel.com and run setup.bat again.'
    }

    Write-Note 'Starting Herd for the first time. If a welcome window opens, click through it.'
    Start-Process -FilePath $herdExe
    $waited = 0
    while (-not (Test-Path (Join-Path $HerdBin 'php.bat'))) {
        if ($waited -ge 600) {
            Stop-Setup 'Herd did not finish setting up PHP within 10 minutes. Open Herd, finish its setup, then run setup.bat again.'
        }
        Start-Sleep -Seconds 5
        $waited += 5
    }
    Write-Ok 'Herd is ready.'
}
Update-SessionPath
& php.bat -v | Select-Object -First 1

# ---------------------------------------------------------------- 2. DBngin / MySQL
Write-Step 'MySQL database server (DBngin)'
if (Test-MySqlPort) {
    Write-Ok "MySQL is already running on ${DbHost}:${DbPort}."
} else {
    $dbngin = Find-DBnginExe
    if (-not $dbngin) {
        $installer = Save-Download 'https://dbngin.com/release/windows/dbngin_latest' 'DBnginSetupX64.exe'
        Write-Host '    Running the DBngin installer (click through it)...'
        Start-Process -FilePath $installer -Wait
        $dbngin = Find-DBnginExe
    }
    if ($dbngin) {
        Start-Process -FilePath $dbngin
    } else {
        Write-Note 'Could not find DBngin after installing. Open it from the Start menu.'
    }

    Write-Host ''
    Write-Note 'In the DBngin window:'
    Write-Note '  1. Click the "+" button'
    Write-Note '  2. Service: MySQL   Port: 3306   (leave the password empty)'
    Write-Note '  3. Click "Create", then click "Start"'
    Write-Host ''
    Write-Host '    Waiting for MySQL to start' -NoNewline
    $waited = 0
    while (-not (Test-MySqlPort)) {
        if ($waited -ge 900) {
            Write-Host ''
            Stop-Setup 'MySQL was not reachable on port 3306 after 15 minutes. Start it in DBngin and run setup.bat again.'
        }
        Write-Host '.' -NoNewline
        Start-Sleep -Seconds 3
        $waited += 3
    }
    Write-Host ''
    Write-Ok 'MySQL is running.'
}

# ---------------------------------------------------------------- 3. Node.js
Write-Step 'Node.js and npm'
if (-not (Test-Command 'npm.cmd')) {
    $nvm = Join-Path $HerdBin 'nvm\nvm.exe'
    if (Test-Path $nvm) {
        Write-Host '    Installing Node.js LTS with Herd''s nvm...'
        & $nvm install lts
        & $nvm use lts
        Update-SessionPath
    }
}
if (-not (Test-Command 'npm.cmd') -and (Test-Winget)) {
    Write-Host '    Installing Node.js LTS with winget...'
    winget install --id OpenJS.NodeJS.LTS -e --silent --accept-package-agreements --accept-source-agreements
    Update-SessionPath
}
if (-not (Test-Command 'npm.cmd')) {
    Stop-Setup 'npm was not found. Install Node.js LTS from https://nodejs.org and run setup.bat again.'
}
Write-Ok "Node $(& node.exe -v), npm $(& npm.cmd -v)"

# ---------------------------------------------------------------- 4. .env
Write-Step 'Environment file (.env)'
$envFile = Join-Path $ProjectDir '.env'
if (Test-Path $envFile) {
    Write-Ok '.env already exists - keeping it.'
} else {
    $content = [IO.File]::ReadAllText((Join-Path $ProjectDir '.env.example'))
    $content = Set-EnvValue $content 'APP_NAME' '"Utility Billing"'
    $content = Set-EnvValue $content 'APP_URL' $SiteUrl
    $content = Set-EnvValue $content 'DB_CONNECTION' 'mysql'
    $content = Set-EnvValue $content 'DB_HOST' $DbHost
    $content = Set-EnvValue $content 'DB_PORT' "$DbPort"
    $content = Set-EnvValue $content 'DB_DATABASE' $DbName
    $content = Set-EnvValue $content 'DB_USERNAME' $DbUser
    $content = Set-EnvValue $content 'DB_PASSWORD' $DbPassword
    [IO.File]::WriteAllText($envFile, $content, (New-Object System.Text.UTF8Encoding($false)))
    Write-Ok 'Created .env from .env.example.'
    Write-Note 'Email (Gmail SMTP) settings are not filled in. Add MAIL_* values to .env later if you need email.'
}

# ---------------------------------------------------------------- 5. Packages
Write-Step 'Installing PHP packages (Composer)'
Invoke-Checked 'composer install' { & composer.bat install --no-interaction --prefer-dist }

if (-not (Select-String -Path $envFile -Pattern '^APP_KEY=base64:' -Quiet)) {
    Invoke-Checked 'Generating app key' { & php.bat artisan key:generate --force --no-interaction }
}

Write-Step 'Installing JavaScript packages and building the frontend'
Invoke-Checked 'npm install' { & npm.cmd install }
Invoke-Checked 'npm run build' { & npm.cmd run build }

# ---------------------------------------------------------------- 6. Database
Write-Step "Database '$DbName'"
$exitCode = Invoke-PhpScript @"
<?php
try {
    `$pdo = new PDO('mysql:host=$DbHost;port=$DbPort', '$DbUser', '$DbPassword');
    `$pdo->exec('CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "    Database is ready.\n";
} catch (Throwable `$e) {
    fwrite(STDERR, '    ' . `$e->getMessage() . "\n");
    exit(1);
}
"@
if ($exitCode -ne 0) {
    Stop-Setup "Could not connect to MySQL as '$DbUser' with an empty password. Check the MySQL service in DBngin."
}

& php.bat artisan config:clear --no-interaction | Out-Null

$countScript = Write-PhpScript @"
<?php
`$pdo = new PDO('mysql:host=$DbHost;port=$DbPort;dbname=$DbName', '$DbUser', '$DbPassword');
echo `$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
"@
$tableCount = [int] ((& php.bat $countScript) | Select-Object -Last 1)

if ($tableCount -gt 0) {
    Write-Ok "Database already has $tableCount tables - running new migrations only."
    Invoke-Checked 'php artisan migrate' { & php.bat artisan migrate --force --no-interaction }
} elseif (Test-Path $BackupSql) {
    Write-Host "    Importing data from database\backup.sql..."
    $exitCode = Invoke-PhpScript @"
<?php
`$pdo = new PDO('mysql:host=$DbHost;port=$DbPort;dbname=$DbName', '$DbUser', '$DbPassword', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
try {
    `$stmt = `$pdo->prepare(file_get_contents('$($BackupSql -replace '\\', '/')'));
    `$stmt->execute();
    do { } while (`$stmt->nextRowset());
    echo "    Import finished.\n";
} catch (Throwable `$e) {
    fwrite(STDERR, '    ' . `$e->getMessage() . "\n");
    exit(1);
}
"@
    if ($exitCode -ne 0) {
        Stop-Setup 'Importing database\backup.sql failed.'
    }
    Invoke-Checked 'php artisan migrate' { & php.bat artisan migrate --force --no-interaction }
} else {
    Invoke-Checked 'php artisan migrate --seed' { & php.bat artisan migrate --seed --force --no-interaction }
}

# ---------------------------------------------------------------- 7. Herd site
Write-Step "Linking the site in Herd as $SiteUrl"
Invoke-Checked 'herd link' { & herd.bat link $SiteName }
& php.bat artisan optimize:clear --no-interaction | Out-Null

Write-Host ''
Write-Host '==================================================' -ForegroundColor Green
Write-Host "   Done! Open $SiteUrl" -ForegroundColor Green
Write-Host '==================================================' -ForegroundColor Green
Write-Host 'Next time, just make sure Herd and the MySQL service in DBngin are running.'
Start-Process $SiteUrl
