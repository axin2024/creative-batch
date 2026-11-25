param(
    [Parameter(Mandatory)][string]$MySQLBin,
    [string]$Php = 'php.exe',
    [ValidateSet('Lifecycle','Live')][string]$Scenario = 'Lifecycle'
)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$mysqlBinPath = (Resolve-Path -LiteralPath $MySQLBin).Path
$mysql = Join-Path $mysqlBinPath 'mysql.exe'
$mysqladmin = Join-Path $mysqlBinPath 'mysqladmin.exe'
$mysqld = Join-Path $mysqlBinPath 'mysqld.exe'
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
    & $Php @extensions $path
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
