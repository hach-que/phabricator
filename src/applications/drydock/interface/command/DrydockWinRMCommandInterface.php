<?php

final class DrydockWinRMCommandInterface extends DrydockCommandInterface {

  private $passphraseWinRMPassword;
  private $connectTimeout;
  private $execTimeout;

  private function loadCredential() {
    if ($this->passphraseWinRMPassword === null) {
      $credential_phid = $this->getConfig('credentialPHID');

      $this->passphraseWinRMPassword = PassphrasePasswordKey::loadFromPHID(
        $credential_phid,
        PhabricatorUser::getOmnipotentUser());
    }

    return $this->passphraseWinRMPassword;
  }

  public function setExecTimeout($timeout) {
    $this->execTimeout = $timeout;
    return $this;
  }

  public function getExecFuture($command) {
    $this->loadCredential();

    $argv = func_get_args();

    $change_directory = '';
    if ($this->peekWorkingDirectory() !== null) {
      $working_directory = $this->peekWorkingDirectory();
      if (strlen($working_directory) >= 2 && $working_directory[1] === ':') {
        // We must also change drive.
        $drive = $working_directory[0];
        $change_directory .= 'cd '.$working_directory.' & '.$drive.': & ';
      } else {
        $change_directory .= 'cd '.$working_directory.' & ';
      }
    }

    // Encode the command to run under Powershell.
    switch ($this->getEscapingMode()) {
      case PhutilCommandString::MODE_WINDOWSCMD:
        $command = id(new PhutilCommandString($argv))
          ->setEscapingMode(PhutilCommandString::MODE_WINDOWSCMD);
        break;
      case PhutilCommandString::MODE_BASH:
        $command = id(new PhutilCommandString($argv))
          ->setEscapingMode(PhutilCommandString::MODE_BASH);
        break;
      case PhutilCommandString::MODE_DEFAULT:
      case PhutilCommandString::MODE_POWERSHELL:
        // Encode the command to run under Powershell.
        $command = id(new PhutilCommandString($argv))
          ->setEscapingMode(PhutilCommandString::MODE_POWERSHELL);

        // When Microsoft says "Unicode" they don't mean UTF-8.
        $command = mb_convert_encoding($command, 'UTF-16LE');
        $command = base64_encode($command);

        $powershell =
          'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $powershell .=
          ' -ExecutionPolicy Bypass'.
          ' -NonInteractive'.
          ' -InputFormat Text'.
          ' -OutputFormat Text'.
          ' -EncodedCommand '.$command;
        $command = $powershell;
        break;
      default:
        throw new Exception(pht(
          'Unknown shell %s',
          $this->getShell()));
    }

    $escaped_command = csprintf(
      'winrm '.
      '-hostname=%s '.
      '-username=%P '.
      '-password=%P '.
      '-port=%s '.
      '%s',
      $this->getConfig('host'),
      $this->passphraseWinRMPassword->getUsernameEnvelope(),
      $this->passphraseWinRMPassword->getPasswordEnvelope(),
      $this->getConfig('port'),
      $change_directory.$command);

    $proxy_cmd = $this->getSSHProxyCommand();
    if ($proxy_cmd !== '') {
      $future = new ExecFuture(
        '%C %s',
        $proxy_cmd,
        $escaped_command);
    } else {
      $future = new ExecFuture(
        '%C',
        $escaped_command);
    }
    $future->setTimeout($this->execTimeout);
    return $future;
  }
}
