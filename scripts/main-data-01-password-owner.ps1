#requires -Version 5.1

$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$helper = Join-Path $PSScriptRoot 'main-data-01-password.php'
$accounts = @{ '1' = 'James Goh'; '2' = 'Hendrik Huang'; '3' = 'Azlina Putri'; '4' = 'Admin'; '5' = 'Pandu Dinata' }
$phase = 'preflight'
$password = $confirmation = $passwordBytes = $confirmationBytes = $child = $null
$result = 1

function Get-SecretUtf8([Security.SecureString] $value) {
    $chars = [char[]]::new($value.Length)
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($value)
    try {
        [Runtime.InteropServices.Marshal]::Copy($pointer, $chars, 0, $chars.Length)
        return ,([Text.UTF8Encoding]::new($false, $true).GetBytes($chars))
    } finally {
        [Array]::Clear($chars, 0, $chars.Length)
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

try {
    if (-not (Test-Path -LiteralPath $php -PathType Leaf) -or -not (Test-Path -LiteralPath $helper -PathType Leaf)) {
        throw 'Runtime or helper unavailable.'
    }
    & $php $helper --preflight
    if ($LASTEXITCODE -ne 0) { throw 'Preflight failed.' }

    $phase = 'pemilihan akun'
    $id = Read-Host 'ID akun (1-5)'
    if ($id -notmatch '^[1-5]$') { throw 'Invalid account.' }
    Write-Host ('Akun terpilih: {0} (ID {1})' -f $accounts[$id], $id)

    $phase = 'prompt password tersembunyi'
    $password = Read-Host 'Password baru' -AsSecureString
    $confirmation = Read-Host 'Ulangi password baru' -AsSecureString
    $phase = 'panjang input (minimal 10 karakter, maksimal 72 byte)'
    if ($password.Length -lt 10 -or $confirmation.Length -lt 10 -or $password.Length -gt 72 -or $confirmation.Length -gt 72) {
        throw 'Invalid length.'
    }
    $phase = 'encoding UTF-8 input'
    $passwordBytes = Get-SecretUtf8 $password
    $confirmationBytes = Get-SecretUtf8 $confirmation
    $phase = 'batas 72 byte atau karakter kontrol input'
    foreach ($value in @($passwordBytes, $confirmationBytes)) {
        if ($value.Length -gt 72 -or $value -contains 0 -or $value -contains 10 -or $value -contains 13) {
            throw 'Invalid input framing.'
        }
    }

    $phase = 'helper rotasi'
    $start = [System.Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $php
    $start.Arguments = '"' + $helper + '" --apply ' + $id
    $start.WorkingDirectory = Split-Path -Parent $PSScriptRoot
    $start.UseShellExecute = $false
    $start.RedirectStandardInput = $true
    $child = [System.Diagnostics.Process]::Start($start)
    $inputPipe = $child.StandardInput.BaseStream
    try {
        $inputPipe.Write($passwordBytes, 0, $passwordBytes.Length)
        $inputPipe.WriteByte(10)
        $inputPipe.Write($confirmationBytes, 0, $confirmationBytes.Length)
        $inputPipe.WriteByte(10)
    } finally {
        $inputPipe.Dispose()
    }

    $child.WaitForExit()
    if ($child.ExitCode -ne 0) { throw 'Rotation helper failed.' }
    Write-Host ('PASS - rotasi dan verifikasi provider login selesai untuk ID {0}.' -f $id)
    $result = 0
} catch {
    Write-Host ('BLOCKED - fase {0}. Jika helper menampilkan hasil, periksa indikatornya sebelum mengulang.' -f $phase)
} finally {
    if ($null -ne $passwordBytes) { [Array]::Clear($passwordBytes, 0, $passwordBytes.Length) }
    if ($null -ne $confirmationBytes) { [Array]::Clear($confirmationBytes, 0, $confirmationBytes.Length) }
    if ($null -ne $password) { $password.Dispose() }
    if ($null -ne $confirmation) { $confirmation.Dispose() }
    if ($null -ne $child) { $child.Dispose() }
}
exit $result
