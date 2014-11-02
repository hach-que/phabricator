<?php

final class DrydockWinRMCommandInterface extends DrydockCommandInterface {

  private $passphraseWinRMPassword;
  private $execTimeout;

  private function openCredentialsIfNotOpen() {
    if ($this->passphraseWinRMPassword !== null) {
      return;
    }

    $credential = id(new PassphraseCredentialQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($this->getConfig('credential')))
      ->needSecrets(true)
      ->executeOne();

    if ($credential->getProvidesType() !==
      PassphraseCredentialTypePassword::PROVIDES_TYPE) {
      throw new Exception('Only password credentials are supported.');
    }

    $this->passphraseWinRMPassword = PassphrasePasswordKey::loadFromPHID(
      $credential->getPHID(),
      PhabricatorUser::getOmnipotentUser());
  }

  public function setExecTimeout($timeout) {
    $this->execTimeout = $timeout;
    return $this;
  }

  public function getExecFuture($command) {
    $this->openCredentialsIfNotOpen();

    $argv = func_get_args();

    $change_directory = '';
    if ($this->getWorkingDirectory() !== null) {
      $change_directory .= 'cd '.$this->getWorkingDirectory().' & ';
    }

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

    $future = new ExecFuture(
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
    $future->setTimeout($this->execTimeout);
    return $future;
  }
}
