<?php

final class DrydockManagementLeaseWorkflow
  extends DrydockManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('lease')
      ->setSynopsis(pht('Lease a resource.'))
      ->setArguments(
        array(
          array(
            'name'      => 'type',
            'param'     => 'resource_type',
            'help'      => pht('Resource type.'),
          ),
          array(
            'name'      => 'attributes',
            'param'     => 'name=value,...',
            'help'      => pht('Resource specficiation.'),
          ),
          array(
            'name'      => 'in-process',
            'help'      => pht('Acquire lease in-process.'),
          ),
        ));
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();

    $resource_type = $args->getArg('type');
    if (!$resource_type) {
      throw new PhutilArgumentUsageException(
        pht(
          'Specify a resource type with `%s`.',
          '--type'));
    }

    $attributes = $args->getArg('attributes');
    if ($attributes) {
      $options = new PhutilSimpleOptions();
      $options->setCaseSensitive(true);
      $attributes = $options->parse($attributes);
    }

    if ($args->getArg('in-process')) {
      PhabricatorWorker::setRunAllTasksInProcess(true);
    }

    $lease = id(new DrydockLease())
      ->setResourceType($resource_type);
    if ($attributes) {
      $lease->setAttributes($attributes);
    }
    $lease
      ->queueForActivation();

    while (true) {
      try {
        $lease->waitUntilActive();
        break;
      } catch (PhabricatorWorkerYieldException $ex) {
        $console->writeOut(
          "%s\n",
          pht('Task yielded while acquiring %s...', $lease->getID()));
      }
    }

    $console->writeOut("%s\n", pht('Acquired Lease %s', $lease->getID()));
    return 0;
  }

}
