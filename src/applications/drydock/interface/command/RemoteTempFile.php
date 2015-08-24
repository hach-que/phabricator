<?php

final class RemoteTempFile extends Phobject {

  private $file;
  private $destroyed = false;
  private $destructionFuture;

  public function __construct($filename, $destruction_future) {
    $this->file = $filename;
    $this->destructionFuture = $destruction_future;

    register_shutdown_function(array($this, '__destruct'));
  }

  public function __toString() {
    return $this->file;
  }

  public function __destruct() {
    if ($this->destroyed) {
      return;
    }

    $this->destructionFuture->resolve();

    $this->file = null;
    $this->destroyed = true;
  }

}
