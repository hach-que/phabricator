<?php

abstract class DrydockCommandInterface extends DrydockInterface {

  const INTERFACE_TYPE = 'command';

  private $workingDirectoryStack = array();
  private $sshProxyHost;
  private $sshProxyPort;
  private $sshProxyCredential;
  private $debugConnection;
  private $escapingMode;

  public function pushWorkingDirectory($working_directory) {
    $this->workingDirectoryStack[] = $working_directory;
    return $this;
  }

  public function popWorkingDirectory() {
    if (!$this->workingDirectoryStack) {
      throw new Exception(
        pht(
          'Unable to pop working directory, directory stack is empty.'));
    }
    return array_pop($this->workingDirectoryStack);
  }

  public function peekWorkingDirectory() {
    if ($this->workingDirectoryStack) {
      return last($this->workingDirectoryStack);
    }
    return null;
  }

  public function setEscapingMode($escaping_mode) {
    $this->escapingMode = $escaping_mode;
    return $this;
  }

  public function getEscapingMode() {
    return $this->escapingMode;
  }

  public function enableConnectionDebugging() {
    $this->debugConnection = true;
    return $this;
  }

  protected function getConnectionDebugging() {
    return $this->debugConnection;
  }

  final public function getInterfaceType() {
    return self::INTERFACE_TYPE;
  }

  public function setSSHProxy($host, $port, $credential) {
    $this->sshProxyHost = $host;
    $this->sshProxyPort = $port;
    $this->sshProxyCredential = $credential;
    return $this;
  }

  public function getSSHProxyCommand() {
    if ($this->sshProxyHost === null) {
      return '';
    }

    return csprintf(
      'ssh '.
      '-o LogLevel=%s '.
      '-o StrictHostKeyChecking=no '.
      '-o UserKnownHostsFile=/dev/null '.
      '-o BatchMode=yes '.
      '-p %s -i %P %P@%s --',
      $this->getConnectionDebugging() ? 'debug' : 'quiet',
      $this->sshProxyPort,
      $this->sshProxyCredential->getKeyfileEnvelope(),
      $this->sshProxyCredential->getUsernameEnvelope(),
      $this->sshProxyHost);
  }

  public function isSSHProxied() {
    return $this->sshProxyHost !== null;
  }

  final public function exec($command) {
    $argv = func_get_args();
    $exec = call_user_func_array(
      array($this, 'getExecFuture'),
      $argv);
    return $exec->resolve();
  }

  final public function execx($command) {
    $argv = func_get_args();
    $exec = call_user_func_array(
      array($this, 'getExecFuture'),
      $argv);
    return $exec->resolvex();
  }

  abstract public function getExecFuture($command);

  protected function applyWorkingDirectoryToArgv(array $argv) {
    $directory = $this->peekWorkingDirectory();

    if ($directory !== null) {
      $cmd = $argv[0];
      $cmd = "(cd %s && {$cmd})";
      $argv = array_merge(
        array($cmd),
        array($directory),
        array_slice($argv, 1));
    }

    return $argv;
  }

}
