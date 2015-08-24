<?php

final class DrydockWorkingCopyBlueprintImplementation
  extends DrydockBlueprintImplementation {

  public function isEnabled() {
    return true;
  }

  public function getBlueprintName() {
    return pht('Working Copy');
  }

  public function getDescription() {
    return pht('Allows Drydock to check out working copies of repositories.');
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $resource_repo = $resource->getAttribute('repositoryID');
    $lease_repo = $lease->getAttribute('repositoryID');

    return ($resource_repo && $lease_repo && ($resource_repo == $lease_repo));
  }

  protected function shouldAllocateLease(
    DrydockAllocationContext $context,
    DrydockResource $resource,
    DrydockLease $lease) {

    return $context->getCurrentResourceLeaseCount() === 0;
  }

  protected function executeInitializePendingResource(
    DrydockResource $resource,
    DrydockLease $lease) {}

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $repository_id = $lease->getAttribute('repositoryID');
    if (!$repository_id) {
      throw new Exception(
        pht(
          "Lease is missing required '%s' attribute.",
          'repositoryID'));
    }

    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($repository_id))
      ->executeOne();

    if (!$repository) {
      throw new Exception(
        pht(
          "Repository '%s' does not exist!",
          $repository_id));
    }

    switch ($repository->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        break;
      default:
        throw new Exception(pht('Unsupported VCS!'));
    }

    // TODO: Policy stuff here too.
    $host_lease = id(new DrydockLease())
      ->setResourceType('host')
      ->waitUntilActive();

    $path = $host_lease->getAttribute('path').$repository->getCallsign();

    $this->log(
      pht('Cloning %s into %s....', $repository->getCallsign(), $path));

    $cmd = $host_lease->getInterface('command');
    $cmd->execx(
      'git clone --origin origin %P %s',
      $repository->getRemoteURIEnvelope(),
      $path);

    $this->log(pht('Complete.'));

    $resource
      ->setName(pht(
        'Working Copy (%s)',
        $repository->getCallsign()))
      ->setStatus(DrydockResourceStatus::STATUS_OPEN)
      ->setAttribute('lease.host', $host_lease->getID())
      ->setAttribute('path', $path)
      ->setAttribute('repositoryID', $repository->getID())
      ->save();

    return $resource;
  }

  protected function executeAcquireLease(
    DrydockResource $resource,
    DrydockLease $lease) {
    return;
  }

  public function getType() {
    return 'working-copy';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    switch ($type) {
      case 'command':
        return $this
          ->loadLease($resource->getAttribute('lease.host'))
          ->getInterface($type);
    }

    throw new Exception(pht("No interface of type '%s'.", $type));
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {}

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    return false;
  }

  protected function executeCloseResource(DrydockResource $resource) {
    // TODO: Remove leased directory
  }

}
