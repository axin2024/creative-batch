param(
    [Parameter(Mandatory)][string]$MySQLBin,
    [string]$Php = 'php.exe',
    [ValidateSet('Lifecycle','Live','Provider','Upgrade','Browser')][string]$Scenario = 'Lifecycle',
    [string]$PlaywrightCli
)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$mysqlBinPath = (Resolve-Path -LiteralPath $MySQLBin).Path
$mysql = Join-Path $mysqlBinPath 'mysql.exe'
$mysqladmin = Join-Path $mysqlBinPath 'mysqladmin.exe'
$mysqld = Join-Path $mysqlBinPath 'mysqld.exe'
$mysqldump = Join-Path $mysqlBinPath 'mysqldump.exe'
$root = (New-Item -ItemType Directory -Force -Path (Join-Path $project '.local')).FullName
$name = 'mysql-' + [guid]::NewGuid().ToString('N')
$fixture = (New-Item -ItemType Directory -Path (Join-Path $root $name)).FullName
$data = (New-Item -ItemType Directory -Path (Join-Path $fixture 'data')).FullName
$init = Join-Path $fixture 'init.sql'
$password = [guid]::NewGuid().ToString('N')
$rootPassword = [guid]::NewGuid().ToString('N')
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
try { $port = $listener.LocalEndpoint.Port } finally { $listener.Stop() }
$argsForMySQL = @('--no-defaults', '--mysqlx=OFF', '--innodb-buffer-pool-size=48M', '--max-connections=28',
    ('--basedir="{0}"' -f (Split-Path $mysqlBinPath -Parent)), ('--datadir="{0}"' -f $data),
    ('--log-error="{0}/mysql.log"' -f $fixture))
$client = @('--no-defaults', '--protocol=TCP', '--host=127.0.0.1', "--port=$port")
$variables = @{
    MYSQL_PWD = $password
    CREATIVE_MYSQL_DSN = "mysql:host=127.0.0.1;port=$port;dbname=creative_test;charset=utf8mb4"
    CREATIVE_MYSQL_USER = 'creative_test'
    CREATIVE_MYSQL_PASSWORD = $password
}
$original = @{}
$process = $null
foreach ($item in $variables.Keys) { $original[$item] = [Environment]::GetEnvironmentVariable($item, 'Process') }
try {
    $initial = Start-Process -FilePath $mysqld -ArgumentList ($argsForMySQL + '--initialize-insecure') -PassThru -Wait -WindowStyle Hidden
    if ($initial.ExitCode -ne 0) { throw 'Unable to initialize disposable MySQL' }
    @"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPassword';
CREATE DATABASE creative_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER 'creative_test'@'127.0.0.1' IDENTIFIED BY '$password';
GRANT ALL ON creative_test.* TO 'creative_test'@'127.0.0.1';
CREATE DATABASE creative_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
GRANT ALL ON creative_restored.* TO 'creative_test'@'127.0.0.1';
"@ | Set-Content -LiteralPath $init -Encoding ascii
    $process = Start-Process -FilePath $mysqld -ArgumentList ($argsForMySQL + @('--bind-address=127.0.0.1', "--port=$port", ('--init-file="{0}"' -f $init))) -PassThru -WindowStyle Hidden
    foreach ($item in $variables.Keys) { [Environment]::SetEnvironmentVariable($item, $variables[$item], 'Process') }
    $version = $null
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($process.HasExited) { throw 'Disposable MySQL exited before startup' }
        $version = & $mysql @client --user=creative_test --database=creative_test --batch --skip-column-names '--execute=SELECT VERSION()' 2>$null
        if ($LASTEXITCODE -eq 0 -and $version) { break }
        Start-Sleep -Milliseconds 250
    }
    if ($version -notmatch '^8\.4\.') { throw 'Expected MySQL 8.4' }
    Remove-Item -LiteralPath $init
    Write-Output "Disposable MySQL $version"
    $drivers = [string](& $Php -r 'echo implode(",", PDO::getAvailableDrivers());')
    $extensions = if ($drivers -notmatch '\bmysql\b') { @('-d', 'extension=pdo_mysql') } else { @() }
    foreach ($extension in @('gd','zip','curl')) {
        if ((& $Php -m) -notcontains $extension) { $extensions += @('-d', "extension=$extension") }
    }
    $path = Join-Path $project "tests/$($Scenario.ToLowerInvariant()).php"
    if ($Scenario -eq 'Upgrade') {
        $priorCommit = git -C $project rev-parse 'v1.0.0^{commit}'
        if ($LASTEXITCODE -ne 0) { throw 'The v1.0.0 source ref is required for the upgrade scenario.' }
        $sourceZip = Join-Path $fixture 'v1.0.0.zip'
        $prior = Join-Path $fixture 'prior'
        $evidence = Join-Path $fixture 'historical.json'
        $backup = Join-Path $fixture 'creative.sql'
        git -C $project archive --format=zip "--output=$sourceZip" $priorCommit
        if ($LASTEXITCODE -ne 0) { throw 'Could not export the previous source.' }
        Expand-Archive -LiteralPath $sourceZip -DestinationPath $prior
        & $Php @extensions $path seed $prior $evidence
        if ($LASTEXITCODE -ne 0) { throw 'Could not create a delivery in the previous version.' }
        & $mysqldump @client --user=creative_test --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF "--result-file=$backup" creative_test
        if ($LASTEXITCODE -ne 0) { throw 'MySQL backup failed.' }
        $import = Start-Process -FilePath $mysql -ArgumentList ($client + @('--user=creative_test', 'creative_restored')) -RedirectStandardInput $backup -WindowStyle Hidden -Wait -PassThru
        if ($import.ExitCode -ne 0) { throw 'MySQL restore failed.' }
        $env:CREATIVE_MYSQL_DSN = $variables.CREATIVE_MYSQL_DSN.Replace('dbname=creative_test;', 'dbname=creative_restored;')
        & $Php @extensions $path verify $project $evidence $priorCommit (Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant()
    } elseif ($Scenario -eq 'Browser') {
        if (!$PlaywrightCli) { throw 'Provide the path to the installed Playwright CLI JavaScript entry point.' }
        $cli = (Resolve-Path -LiteralPath $PlaywrightCli).Path
        $testCode = Join-Path $fixture 'browser-scenario.js'
        & $Php @extensions $path $testCode
        if ($LASTEXITCODE -ne 0) { throw 'Could not create the browser fixture.' }
        $webListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
        $webListener.Start()
        try { $webPort = $webListener.LocalEndpoint.Port } finally { $webListener.Stop() }
        $web = Start-Process -FilePath (Get-Command $Php).Source -ArgumentList ($extensions + @('-S', "127.0.0.1:$webPort", '-t', 'public', 'public/index.php')) `
            -WorkingDirectory $project -RedirectStandardError (Join-Path $fixture 'web.log') -WindowStyle Hidden -PassThru
        Push-Location -LiteralPath $fixture
        try {
            $origin = "http://127.0.0.1:$webPort"
            $ready = $false
            for ($attempt = 0; $attempt -lt 40; $attempt++) {
                if ($web.HasExited) { break }
                try { $ready = (Invoke-RestMethod -Uri "$origin/api/health" -TimeoutSec 1).status -eq 'ready' } catch {}
                if ($ready) { break }
                Start-Sleep -Milliseconds 250
            }
            if (!$ready) { throw 'Disposable web process did not reach ready status.' }
            & node $cli "-s=$name" open $origin --browser=chrome | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Could not open the isolated headless Chrome session.' }
            $browsed = & node $cli "-s=$name" run-code "--filename=$testCode"
            $exitCode = $LASTEXITCODE
            $reported = [regex]::Match(($browsed -join "`n"), '(?ms)^### Result\s+(.*?)^### ')
            $result = if ($reported.Success) { $reported.Groups[1].Value | ConvertFrom-Json }
            if ($exitCode -ne 0 -or $result.result -ne 'BROWSER_ACCEPTANCE_PASSED') {
                $browsed | Select-String -Pattern '^### |^\w+Error:|^\{"result"|waiting for|dialog' | Where-Object { $_.Line -notmatch '^\s+(const|await|page\.)' } | Select-Object -First 10 | Write-Output
                throw 'The browser scenario did not pass.'
            }
            $result | ConvertTo-Json -Compress
        } finally {
            & node $cli "-s=$name" close *> $null
            Pop-Location
            if (!$web.HasExited) { Stop-Process -InputObject $web; $web.WaitForExit() }
        }
        $global:LASTEXITCODE = 0
    } else {
        & $Php @extensions $path
    }
    if ($LASTEXITCODE -ne 0) { throw "Failed $Scenario scenario" }
} finally {
    if (Test-Path -LiteralPath $init) { Remove-Item -LiteralPath $init }
    if ($process -and !$process.HasExited) {
        $env:MYSQL_PWD = $rootPassword
        & $mysqladmin @client --user=root --connect-timeout=2 shutdown 2>$null
        if (!$process.WaitForExit(5000)) { Stop-Process -InputObject $process }
    }
    foreach ($item in $original.Keys) { [Environment]::SetEnvironmentVariable($item, $original[$item], 'Process') }
    if (Test-Path -LiteralPath $fixture) {
        $absolute = (Resolve-Path -LiteralPath $fixture).Path
        if ((Split-Path -Parent $absolute) -ne $root -or (Split-Path -Leaf $absolute) -ne $name) {
            throw 'Disposable MySQL cleanup refused the resolved path'
        }
        Remove-Item -LiteralPath $absolute -Force -Recurse
    }
}
