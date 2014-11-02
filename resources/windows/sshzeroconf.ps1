# Allow running scripts on this system
Set-ExecutionPolicy -Force Bypass

# Download Cygwin64
wget https://cygwin.com/setup-x86_64.exe `
  -OutFile C:\setup-x86_64.exe

# Run Cygwin64 install
C:\setup-x86_64.exe -s http://mirrors.kernel.org/sourceware/cygwin/ -P openssh `
  -q -a x86_64 -B -X -n -N -d

# Wait for Cygwin install to finish
while ((Get-Process setup-x86_64 -ErrorAction SilentlyContinue) -ne $null) {
  Write-Host "Waiting for Cygwin to finish installation..."
  Sleep 5
}

# Setup SSHD
$env:PATH = $env:PATH + ";c:\cygwin64\bin"
C:\cygwin64\bin\chmod.exe +r /etc/passwd
C:\cygwin64\bin\chmod.exe +r /etc/group
C:\cygwin64\bin\chmod.exe a+x /var
C:\cygwin64\bin\bash.exe -c "mkpasswd > /etc/passwd"
C:\cygwin64\bin\bash.exe C:\cygwin64\bin\ssh-host-config -u $username -y -w `
  SSHPRIV1@

# Set up cmd.exe wrapping script
$cmdwrap = @"
#!/bin/bash

cmd=$*
cmd=`${cmd:3}

/cygdrive/c/Windows/system32/cmd.exe /C `$cmd
"@
$cmdwrap = $cmdwrap.Replace("`r`n", "`n")
Set-Content -Path C:\cygwin64\bin\cmdwrap.sh -Value $cmdwrap

C:\cygwin64\bin\bash.exe -c "mkpasswd > /etc/passwd"

if (!(Test-Path C:\cygwin64\home\$username\.ssh)) {
  mkdir C:\cygwin64\home\$username\.ssh
}

$keyline = "$publickey Automatically Defined Key"

Set-Content `
  -Path C:\cygwin64\home\$username\.ssh\authorized_keys `
  -Value $keyline

New-NetFirewallRule -DisplayName "SSHD" `
  -Direction Inbound -Protocol TCP -LocalPort 22 -Action allow

Set-Service sshd -StartupType Automatic
Set-ItemProperty -Path "Registry::HKLM\System\CurrentControlSet\Services\sshd" `
  -Name "DelayedAutostart" -Value 1 -Type DWORD

# Change privilege mode.
$secpasswd = ConvertTo-SecureString "SSHPRIV1@" -AsPlainText -Force
$targetCred = New-Object System.Management.Automation.PSCredential($username, `
  $secpasswd)
Invoke-Command -ComputerName localhost -Credential $targetCred `
    -ScriptBlock {
        $sshd_config = Get-Content -Path C:\cygwin64\etc\sshd_config
        $sshd_config = $sshd_config.Replace( `
          "UsePrivilegeSeparation sandbox", `
          "UsePrivilegeSeparation no")
        Set-Content -Path C:\cygwin64\etc\sshd_config -Value $sshd_config
        }

# Use cmd.exe as the initial prompt on SSH.
$passwd_config = Get-Content -Path C:\cygwin64\etc\passwd
$search = $null;
for ($i = 0; $i -lt $passwd_config.Count; $i++) {
  if ($passwd_config[$i].Contains($username)) {
    $passwd_config[$i] = $passwd_config[$i].Replace( `
      "/var/empty", `
      "/home/$username")
    $passwd_config[$i] = $passwd_config[$i].Replace( `
      "/bin/bash", `
      "/bin/cmdwrap.sh")
    write-host ("Looking at " + $passwd_config[$i])
  }
}
Set-Content -Path C:\cygwin64\etc\passwd -Value $passwd_config

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

# Start SSH.
Start-Service sshd
