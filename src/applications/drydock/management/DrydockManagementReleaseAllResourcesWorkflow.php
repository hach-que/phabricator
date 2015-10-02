<?php

final class DrydockManagementReleaseAllResourcesWorkflow
  extends DrydockManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('release-all-resources')
      ->setSynopsis(pht('Release all open resources.'));
  }

  public function execute(PhutilArgumentParser $args) {
    $viewer = $this->getViewer();
    $drydock_phid = id(new PhabricatorDrydockApplication())->getPHID();

    $resources = id(new DrydockResourceQuery())
      ->setViewer($viewer)
      ->withStatuses(array(
        DrydockResourceStatus::STATUS_PENDING,
        DrydockResourceStatus::STATUS_ACTIVE
      ))
      ->execute();

    foreach ($resources as $resource) {
      $id = $resource->getID();

      if (!$resource->canRelease()) {
        echo tsprintf(
          "%s\n",
          pht('Resource "%s" is not releasable.', $id));
        continue;
      }

      $command = DrydockCommand::initializeNewCommand($viewer)
        ->setTargetPHID($resource->getPHID())
        ->setAuthorPHID($drydock_phid)
        ->setCommand(DrydockCommand::COMMAND_RELEASE)
        ->save();

      $resource->scheduleUpdate();

      echo tsprintf(
        "%s\n",
        pht('Scheduled release of resource "%s".', $id));
    }

  }

}
