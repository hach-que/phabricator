<?php

final class DrydockSSHCommandInterface extends DrydockCommandInterface {

  private $passphraseSSHKey;
  private $connectTimeout;
  private $execTimeout;
  private $remoteKeyFile;

  private function openCredentialsIfNotOpen() {
    if ($this->passphraseSSHKey !== null) {
      return;
    }

    $credential = id(new PassphraseCredentialQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($this->getConfig('credential')))
      ->needSecrets(true)
      ->executeOne();

    if ($credential === null) {
      throw new Exception(
        pht(
          'There is no credential with ID %d.',
          $this->getConfig('credential')));
    }

    if ($credential->getProvidesType() !==
      PassphraseSSHPrivateKeyCredentialType::PROVIDES_TYPE) {
      throw new Exception(pht('Only private key credentials are supported.'));
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
    $argv = $this->applyWorkingDirectoryToArgv($argv);
    $full_command = call_user_func_array('csprintf', $argv);

    $command_timeout = '';
    if ($this->connectTimeout !== null) {
      $command_timeout = csprintf(
        '-o %s',
        'ConnectTimeout='.$this->connectTimeout);
    }

    $key = $this->passphraseSSHKey->getKeyfileEnvelope();
    if ($this->isSSHProxied()) {
      if ($this->remoteKeyFile === null) {
        $temp_name = '/tmp/'.Filesystem::readRandomCharacters(20).'.proxy';
        $key_future = new ExecFuture(
          'cat %P | %C %s',
          $key,
          $this->getSSHProxyCommand(),
          csprintf(
            'touch %s && chmod 0600 %s && cat - >%s',
            $temp_name,
            $temp_name,
            $temp_name));
        $key_future->resolvex();
        $this->remoteKeyFile = new RemoteTempFile(
          $temp_name,
          new ExecFuture(
            '%C %s',
            $this->getSSHProxyCommand(),
            csprintf('rm %s', $temp_name)));
      }
      $key = new PhutilOpaqueEnvelope((string)$this->remoteKeyFile);
    }

    $escaped_command = csprintf(
      'ssh '.
      '-o LogLevel=quiet '.
      '-o StrictHostKeyChecking=no '.
      '-o UserKnownHostsFile=/dev/null '.
      '-o BatchMode=yes '.
      '%C -p %s -i %P %P@%s -- %s',
      $command_timeout,
      $this->getConfig('port'),
      $key,
      $this->passphraseSSHKey->getUsernameEnvelope(),
      $this->getConfig('host'),
      $full_command);

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
