<?php

final class DrydockKVMVirtualMachineShutdownException
  extends PhabricatorWorkerYieldException {

  public function __construct() {
    parent::__construct(15);
  }

}
