<?php

final class DrydockSSHCommandInterface extends DrydockCommandInterface {

  private $credential;
  private $connectTimeout;
  private $execTimeout;
  private $remoteKeyFile;

  private function loadCredential() {
    if ($this->credential === null) {
      $credential_phid = $this->getConfig('credentialPHID');

      $this->credential = PassphraseSSHKey::loadFromPHID(
        $credential_phid,
        PhabricatorUser::getOmnipotentUser());
    }

    return $this->credential;
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
    $credential = $this->loadCredential();

    $argv = func_get_args();
    $argv = $this->applyWorkingDirectoryToArgv($argv);
    $full_command = call_user_func_array('csprintf', $argv);

    $flags = array();
    $flags[] = '-o';
    $flags[] = 'LogLevel='.(
      $this->getConnectionDebugging() ? 'debug' : 'quiet');

    $flags[] = '-o';
    $flags[] = 'StrictHostKeyChecking=no';

    $flags[] = '-o';
    $flags[] = 'UserKnownHostsFile=/dev/null';

    $flags[] = '-o';
    $flags[] = 'BatchMode=yes';

    if ($this->connectTimeout) {
      $flags[] = '-o';
      $flags[] = 'ConnectTimeout='.$this->connectTimeout;
    }

    $key = $credential->getKeyfileEnvelope();
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
      'ssh %Ls -l %P -p %s -i %P %s -- %s',
      $flags,
      $credential->getUsernameEnvelope(),
      $this->getConfig('port'),
      $key,
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
