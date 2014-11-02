<?php

final class DrydockManagementSSHWorkflow
  extends DrydockManagementWorkflow {

  public function didConstruct() {
    $this
      ->setName('ssh')
      ->setSynopsis('Run a command on a remote host.')
      ->setArguments(
        array(
          array(
            'name'      => 'id',
            'param'     => 'lease',
          ),
          array(
            'name'      => 'command',
            'param'     => 'command',
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $id = $args->getArg('id');
    if (!$id) {
      throw new PhutilArgumentUsageException(
        'Specify a lease ID to run the command on.');
    }

    $viewer = $this->getViewer();

    $leases = id(new DrydockLeaseQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->execute();
    $lease = idx($leases, $id);

    if (!$lease) {
      $console->writeErr("Lease %d does not exist!\n", $id);
    } else {
      $interface = $lease->getInterface('command');
      $future = $interface->getExecFuture('%C', $args->getArg('command'));

      list($err, $stdout, $stderr) = $future->resolve();
      $console->writeOut("Result: %d\n\n", $err);
      $console->writeOut("%s\n", $stdout);
      $console->writeErr("%s\n", $stderr);
    }

  }

}
