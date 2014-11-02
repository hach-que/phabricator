<?php

final class DrydockSSHCommandInterface extends DrydockCommandInterface {

  private $passphraseSSHKey;
  private $connectTimeout;
  private $execTimeout;

  private function openCredentialsIfNotOpen() {
    if ($this->passphraseSSHKey !== null) {
      return;
    }

    $credential = id(new PassphraseCredentialQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($this->getConfig('credential')))
      ->needSecrets(true)
      ->executeOne();

    if ($credential->getProvidesType() !==
      PassphraseCredentialTypeSSHPrivateKey::PROVIDES_TYPE) {
      throw new Exception('Only private key credentials are supported.');
    }

    $this->passphraseSSHKey = PassphraseSSHKey::loadFromPHID(
      $credential->getPHID(),
      PhabricatorUser::getOmnipotentUser());
  }

  public function setConnectTimeout($timeout) {
    $this->connectTimeout = $timeout;
    return $this;
  }

  public function setExecTimeout($timeout) {
    $this->execTimeout = $timeout;
    return $this;
  }

  public function getExecFuture($command) {
    $this->openCredentialsIfNotOpen();

    $argv = func_get_args();

    if ($this->getConfig('platform') === 'windows') {

      $escape_mode = null;
      $command_line = null;

      switch ($this->getEscapingMode()) {
        case PhutilCommandString::MODE_WINDOWSCMD:
        case PhutilCommandString::MODE_BASH:
          $escape_mode = PhutilCommandString::MODE_POWERSHELL;
          $command_line = 'iex $original_command';
          break;
        case PhutilCommandString::MODE_POWERSHELL:
          $escape_mode = PhutilCommandString::MODE_POWERSHELL;
          $command_line = <<<EOF

# Encode the command as base64...
\$bytes_command = [System.Text.Encoding]::Unicode.GetBytes(\$original_command)
\$encoded_command = [Convert]::ToBase64String(\$bytes_command)

C:\\Windows\\system32\\WindowsPowerShell\\v1.0\\powershell.exe `
  -NonInteractive `
  -OutputFormat Text `
  -EncodedCommand \$encoded_command
EOF;
          break;
        default:
          throw new Exception(pht(
            'Unknown shell %s',
            $this->getShell()));
      }

      // Handle Windows by executing the command under PowerShell.
      $command = id(new PhutilCommandString($argv))
        ->setEscapingMode($escape_mode);

      $encapsulate_command = array('%s', (string)$command);
      $double_command = id(new PhutilCommandString($encapsulate_command))
        ->setEscapingMode(PhutilCommandString::MODE_POWERSHELL);

      $change_directory = '';
      if ($this->getWorkingDirectory() !== null) {
        $change_directory .= 'cd '.$this->getWorkingDirectory();
      }

      $script = <<<EOF
\$ErrorActionPreference = 'Continue'
\$host.UI.RawUI.BufferSize = `
  New-Object System.Management.Automation.Host.Size(512,50)

\$s = New-PSSession localhost
while (\$s -eq \$null) {
  # Occasionally Windows fails to authenticate the current user as
  # themselves.
  Write-Error `
    "Failure to create session in New-PSSession.  Retrying..."
  Sleep 5

  \$s = New-PSSession localhost
}
\$real_env = Invoke-Command -Session \$s -ErrorAction Continue -ScriptBlock {
  dir Env:\\
}
Remove-PSSession \$s
foreach (\$entry in (dir Env:\\)) {
  \$keyname = ("env:" + \$entry.Name)
  Remove-Item -Path \$keyname
}
foreach (\$entry in \$real_env) {
  \$keyname = ("env:" + \$entry.Name)
  \$keyval = \$entry.Value
  Set-Item -Path \$keyname -Value \$keyval
}

$change_directory

\$original_command = $double_command

# Run powershell from itself to get a "standard" exit code.  This still
# doesn't actually catch every kind of error :(
$command_line
exit \$LastExitCode
EOF;

      // When Microsoft says "Unicode" they don't mean UTF-8.
      $script = mb_convert_encoding($script, 'UTF-16LE');

      $script = base64_encode($script);

      $powershell =
        'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
      $powershell .=
        ' -ExecutionPolicy Bypass'.
        ' -NonInteractive'.
        ' -InputFormat Text'.
        ' -OutputFormat Text'.
        ' -EncodedCommand '.$script;

      $full_command = $powershell;
    } else {
      // Handle UNIX by executing under the native shell.
      $argv = $this->applyWorkingDirectoryToArgv($argv);

      $full_command = call_user_func_array('csprintf', $argv);
    }

    $command_timeout = '';
    if ($this->connectTimeout !== null) {
      $command_timeout = csprintf(
        '-o %s',
        'ConnectTimeout='.$this->connectTimeout);
    }

    $future = new ExecFuture(
      'ssh '.
      '-o LogLevel=quiet '.
      '-o StrictHostKeyChecking=no '.
      '-o UserKnownHostsFile=/dev/null '.
      '-o BatchMode=yes '.
      '%C -p %s -i %P %P@%s -- %s',
      $command_timeout,
      $this->getConfig('port'),
      $this->passphraseSSHKey->getKeyfileEnvelope(),
      $this->passphraseSSHKey->getUsernameEnvelope(),
      $this->getConfig('host'),
      $full_command);
    $future->setTimeout($this->execTimeout);
    $future->setPowershellXML($this->getConfig('platform') === 'windows');
    return $future;
  }
}
