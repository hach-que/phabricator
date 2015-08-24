<?php

abstract class DrydockCommandInterface extends DrydockInterface {

  private $workingDirectory;
  private $escapingMode;

  public function __construct() {
    $this->escapingMode = PhutilCommandString::MODE_DEFAULT;
  }

  public function setWorkingDirectory($working_directory) {
    $this->workingDirectory = $working_directory;
    return $this;
  }

  public function getWorkingDirectory() {
    return $this->workingDirectory;
  }

  public function setEscapingMode($escaping_mode) {
    $this->escapingMode = $escaping_mode;
    return $this;
  }

  public function getEscapingMode() {
    return $this->escapingMode;
  }

  final public function getInterfaceType() {
    return 'command';
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
    if ($this->getWorkingDirectory() !== null) {
      $cmd = $argv[0];
      $cmd = "(cd %s; {$cmd})";
      $argv = array_merge(
        array($cmd),
        array($this->getWorkingDirectory()),
        array_slice($argv, 1));
    }

    return $argv;
  }

}
