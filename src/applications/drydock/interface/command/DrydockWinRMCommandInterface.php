<?php

final class DrydockWinRMCommandInterface extends DrydockCommandInterface {

  private $passphraseWinRMPassword;
  private $connectTimeout;

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
      PassphrasePasswordCredentialType::PROVIDES_TYPE) {
      throw new Exception('Only password credentials are supported.');
    }

    $this->passphraseWinRMPassword = PassphrasePasswordKey::loadFromPHID(
      $credential->getPHID(),
      PhabricatorUser::getOmnipotentUser());
  }

  public function getExecFuture($command) {
    $this->openCredentialsIfNotOpen();

    $argv = func_get_args();

    $change_directory = '';
    if ($this->getWorkingDirectory() !== null) {
      $working_directory = $this->getWorkingDirectory();
      if (strlen($working_directory) >= 2 && $working_directory[1] === ':') {
        // We must also change drive.
        $drive = $working_directory[0];
        $change_directory .= 'cd '.$working_directory.' & '.$drive.': & ';
      } else {
        $change_directory .= 'cd '.$working_directory.' & ';
      }
    }

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

    return new ExecFuture(
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
      $change_directory.$powershell);
  }
}
