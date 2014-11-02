# Allow running scripts on this system
Set-ExecutionPolicy -Force Bypass

# Enable plain HTTP WinRM remoting
Set-WSManInstance WinRM/Config/Service/Auth -ValueSet @{Basic = $true}
Set-WSManInstance WinRM/Config/Service -ValueSet @{AllowUnencrypted = $true}
Set-WSManInstance WinRM/Config/Client -ValueSet @{TrustedHosts="*"}

# Prevent Powershell from using stupid defaults.
Set-Item -ErrorAction SilentlyContinue `
  WSMAN:localhost\Shell\MaxMemoryPerShellMB 100000000
Set-Item -ErrorAction SilentlyContinue `
  WSMAN:localhost\Shell\MaxShellsPerUser 10000

Push-Location WSMAN:localhost\Plugin\
foreach ($dir in Get-ChildItem) {
    $name = $dir.Name
    try {
        Set-Item -ErrorAction SilentlyContinue `
          -Path "$name\Quotas\MaxConcurrentCommandsPerShell" 100000000
    } catch [System.InvalidOperationException] {
    }
    try {
        Set-Item -ErrorAction SilentlyContinue `
          -Path "$name\Quotas\MaxMemoryPerShellMB" 10000
    } catch [System.InvalidOperationException] {
    }
}
Pop-Location

# Reload remoting configuration.
Restart-Service winrm
