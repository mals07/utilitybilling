# Builds utility-billing-deploy.zip: a ready-to-upload copy of the app for shared hosting (cPanel / hPanel).
# Run it by double-clicking build-deploy.bat. Upload the zip to the hosting and extract it.
#
# The zip contains:
#   - the app code with production Composer packages (vendor/, no dev tools)
#   - the built frontend (public/build)
#   - empty, writable storage/ and bootstrap/cache folders
#   - .env.hosting: a production .env template with a fresh APP_KEY (rename it to .env on the server)
#   - .htaccess: forwards requests to public/ when the zip is extracted straight into public_html

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$ProjectDir = $PSScriptRoot
$HerdBin = Join-Path $env:USERPROFILE '.config\herd\bin'
$StageDir = Join-Path $env:TEMP 'utility-billing-deploy'
$ZipFile = Join-Path $ProjectDir 'utility-billing-deploy.zip'
$Utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Stop-Build([string] $Message) {
    Write-Host ''
    Write-Host "BUILD FAILED: $Message" -ForegroundColor Red
    exit 1
}

function Invoke-Checked([string] $Description, [scriptblock] $Command) {
    Write-Host "    $Description..."
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Stop-Build "$Description failed (exit code $LASTEXITCODE)."
    }
}

function Copy-Folder([string] $Name, [string[]] $ExcludeFiles = @(), [string[]] $ExcludeDirs = @()) {
    $arguments = @((Join-Path $ProjectDir $Name), (Join-Path $StageDir $Name), '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP')
    if ($ExcludeFiles.Count -gt 0) {
        $arguments += '/XF'
        $arguments += $ExcludeFiles
    }
    if ($ExcludeDirs.Count -gt 0) {
        $arguments += '/XD'
        $arguments += $ExcludeDirs
    }
    & robocopy @arguments | Out-Null
    if ($LASTEXITCODE -ge 8) {
        Stop-Build "Copying $Name failed."
    }
    $global:LASTEXITCODE = 0
}

function Set-EnvValue([string] $Content, [string] $Key, [string] $Value) {
    $line = "$Key=$Value"
    $pattern = "(?m)^#?\s*$Key=.*$"
    if ($Content -match $pattern) {
        return [regex]::Replace($Content, $pattern, [System.Text.RegularExpressions.MatchEvaluator] { param($m) $line }, 1)
    }
    return $Content.TrimEnd() + "`n$line`n"
}

Write-Host '==================================================' -ForegroundColor Cyan
Write-Host '   Utility Billing - build hosting package' -ForegroundColor Cyan
Write-Host '==================================================' -ForegroundColor Cyan

Set-Location $ProjectDir
$env:Path = "$HerdBin;$env:Path"

# ---------------------------------------------------------------- 1. Frontend
Write-Step 'Building the frontend'
if (-not (Test-Path (Join-Path $ProjectDir 'node_modules'))) {
    Invoke-Checked 'npm install' { & npm.cmd install }
}
Invoke-Checked 'npm run build' { & npm.cmd run build }

# ---------------------------------------------------------------- 2. Copy app files
Write-Step 'Copying app files'
if (Test-Path $StageDir) {
    Remove-Item -Recurse -Force $StageDir
}
New-Item -ItemType Directory -Force -Path $StageDir | Out-Null

foreach ($folder in @('app', 'config', 'resources', 'routes')) {
    Copy-Folder $folder
}
Copy-Folder 'bootstrap' -ExcludeDirs @((Join-Path $ProjectDir 'bootstrap\cache'))
Copy-Folder 'database' -ExcludeFiles @('backup.sql', '*.sqlite', '*.sqlite-journal')
Copy-Folder 'public' -ExcludeFiles @('hot')
foreach ($file in @('artisan', 'composer.json', 'composer.lock')) {
    Copy-Item (Join-Path $ProjectDir $file) (Join-Path $StageDir $file)
}

$emptyFolders = @(
    'bootstrap\cache',
    'storage\app\public',
    'storage\app\private',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'storage\logs'
)
foreach ($folder in $emptyFolders) {
    $path = Join-Path $StageDir $folder
    New-Item -ItemType Directory -Force -Path $path | Out-Null
    [IO.File]::WriteAllText((Join-Path $path '.gitignore'), "*`n!.gitignore`n", $Utf8)
}

# ---------------------------------------------------------------- 3. Production packages
Write-Step 'Installing production PHP packages (no dev tools)'
Push-Location $StageDir
Invoke-Checked 'composer install --no-dev' { & composer.bat install --no-dev --optimize-autoloader --no-interaction --prefer-dist }
Pop-Location

# ---------------------------------------------------------------- 4. Hosting files
Write-Step 'Writing .env.hosting and .htaccess'
$appKey = (& php.bat artisan key:generate --show --no-interaction | Select-Object -Last 1).Trim()
if ($appKey -notmatch '^base64:') {
    Stop-Build 'Could not generate an APP_KEY.'
}

$envContent = [IO.File]::ReadAllText((Join-Path $ProjectDir '.env.example')) -replace "`r`n", "`n"
$envValues = [ordered] @{
    'APP_NAME' = '"Utility Billing"'
    'APP_ENV' = 'production'
    'APP_KEY' = $appKey
    'APP_DEBUG' = 'false'
    'APP_URL' = 'https://YOUR-DOMAIN.com'
    'LOG_LEVEL' = 'error'
    'DB_CONNECTION' = 'mysql'
    'DB_HOST' = 'localhost'
    'DB_PORT' = '3306'
    'DB_DATABASE' = 'CHANGE_ME_database_name'
    'DB_USERNAME' = 'CHANGE_ME_database_user'
    'DB_PASSWORD' = 'CHANGE_ME_database_password'
    'EMAIL_DELIVERY' = 'smtp'
    'MAIL_MAILER' = 'smtp'
    'MAIL_HOST' = 'smtp.gmail.com'
    'MAIL_PORT' = '587'
    'MAIL_USERNAME' = 'CHANGE_ME_gmail_address'
    'MAIL_PASSWORD' = 'CHANGE_ME_16_character_app_password'
    'MAIL_FROM_ADDRESS' = 'CHANGE_ME_gmail_address'
}
foreach ($key in $envValues.Keys) {
    $envContent = Set-EnvValue $envContent $key $envValues[$key]
}
[IO.File]::WriteAllText((Join-Path $StageDir '.env.hosting'), $envContent, $Utf8)

$htaccess = @'
# Used only when the app is extracted straight into public_html.
# Sends every request into the public/ folder so app code and .env are never served.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
'@
[IO.File]::WriteAllText((Join-Path $StageDir '.htaccess'), ($htaccess -replace "`r`n", "`n"), $Utf8)

# ---------------------------------------------------------------- 5. Zip
Write-Step 'Creating utility-billing-deploy.zip'
if (Test-Path $ZipFile) {
    Remove-Item -Force $ZipFile
}
# Windows' bsdtar writes forward-slash paths, which Linux hosting extracts correctly.
& "$env:SystemRoot\System32\tar.exe" -a -c -f $ZipFile -C $StageDir .
if ($LASTEXITCODE -ne 0) {
    Stop-Build 'Creating the zip failed.'
}
Remove-Item -Recurse -Force $StageDir

$sizeMb = [math]::Round((Get-Item $ZipFile).Length / 1MB, 1)
Write-Host ''
Write-Host '==================================================' -ForegroundColor Green
Write-Host "   Done! utility-billing-deploy.zip ($sizeMb MB)" -ForegroundColor Green
Write-Host '==================================================' -ForegroundColor Green
Write-Host 'Upload it to your hosting and follow the hosting guide.'
Write-Host 'The hosting must use PHP 8.4 or newer.'
