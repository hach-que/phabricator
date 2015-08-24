<?php

final class DrydockWorkingCopyCacheBlueprintImplementation
  extends DrydockBlueprintImplementation {

  public function isEnabled() {
    return true;
  }

  public function getBlueprintName() {
    return pht('Working Copy Cache');
  }

  public function getDescription() {
    return pht('Allows Drydock to cache repositories on host resources.');
  }

  public function canAllocateResourceForLease(DrydockLease $lease) {
    return true;
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $resource_match = $lease->getAttribute('host.resource') ===
      $resource->getAttribute('host.resource');
    $url_match = $lease->getAttribute('url') ===
      $resource->getAttribute('url');

    $can_allocate = $resource_match && $url_match;

    if ($can_allocate) {
      $this->log(pht(
        'This blueprint can allocate a resource for the specified lease.'));
    } else {
      $this->log(pht(
        'This blueprint can not allocate a resource for the specified lease.'));
    }

    return $can_allocate;
  }

  protected function shouldAllocateLease(
    DrydockAllocationContext $context,
    DrydockResource $resource,
    DrydockLease $lease) {

    return true;
  }

  protected function executeInitializePendingResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $url = $lease->getAttribute('url');
    $host_resource_id = $lease->getAttribute('host.resource');

    $resource
      ->setAttribute('url', $url)
      ->setAttribute('host.resource', $host_resource_id)
      ->save();
  }

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $url = $lease->getAttribute('url');
    $host_resource_id = $lease->getAttribute('host.resource');

    $resource
      ->setName(pht(
        'Working Copy Cache (%s on host resource %d)',
        $url,
        $host_resource_id))
      ->setStatus(DrydockResourceStatus::STATUS_PENDING)
      ->save();

    $host_lease = id(new DrydockLease())
      ->setResourceType('host')
      ->setIsTransientLease(1) // The cache should not hold the host open.
      ->setAttributes(
        array(
          'resourceID' => $host_resource_id,
        ))
      ->queueForActivation();
    $this->log(pht(
      'Acquiring new host lease %d for working copy cache (on resource %d)...',
      $host_lease->getID(),
      $host_resource_id));
    $host_lease->waitUntilActive();
    $this->log(pht(
      'Lease %d acquired for working copy resource.',
      $host_lease->getID()));

    $resource
      ->setAttribute('host.resource.phid', $host_lease->getResourcePHID())
      ->setAttribute('host.lease', $host_lease->getID())
      ->setAttribute('host.lease.phid', $host_lease->getPHID())
      ->save();

    $this->log(pht(
      'Cloning repository at "%s" to "%s"...',
      $url,
      $host_lease->getAttribute('path')));

    $cmd = $this->getCommandInterfaceForLease($host_lease);
    $cmd->setExecTimeout(3600);
    $cmd->execx(
      'git clone --bare %s .',
      $url);

    $this->log('Cloned repository cache.');

    $resource
      ->setStatus(DrydockResourceStatus::STATUS_OPEN)
      ->save();
    return $resource;
  }

  protected function executeAcquireLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $this->log(pht(
      'Starting acquisition of lease from resource %d',
      $resource->getID()));

    while ($resource->getStatus() == DrydockResourceStatus::STATUS_PENDING) {
      $this->log(pht(
        'Resource %d is still pending, waiting until it is in an open status',
        $resource->getID()));

      // This resource is still being set up by another allocator, wait until
      // it is set to open.
      sleep(5);
      $resource->reload();
    }

    $host_lease = id(new DrydockLeaseQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($resource->getAttribute('host.lease')))
      ->executeOne();
    if ($host_lease === null) {
      throw new Exception(pht(
        'No resource found with ID %d',
        $resource->getAttribute('host.lease')));
    }

    // Ensure the host lease and resource are still active and open.
    if ($host_lease->getStatus() != DrydockLeaseStatus::STATUS_ACTIVE ||
        $host_lease->getResource()->getStatus() !=
          DrydockResourceStatus::STATUS_OPEN) {

      // Our host lease has been released or our host resource has been
      // closed.  No further leases against this working copy cache
      // resource will succeed, so force ourselves into the closed
      // status and fail this lease.
      $resource
        ->setStatus(DrydockResourceStatus::STATUS_DESTROYED)
        ->save();
      throw new Exception(pht(
        'The host lease or resource for this working copy '.
        'cache was released or closed.  Lease was in status "%s", '.
        'resource was in status "%s".  Automatically '.
        'marking this working copy resource as destroyed.',
        DrydockLeaseStatus::getNameForStatus($host_lease->getStatus()),
        DrydockResourceStatus::getNameForStatus(
          $host_lease->getResource()->getStatus())));
    }

    // We must lock the resource while we perform the cache update,
    // because otherwise we'll end up running multiple read-write
    // VCS operations in the same directory at the same time.
    $lock = PhabricatorGlobalLock::newLock(
      'drydock-working-copy-cache-update-'.$host_lease->getID());
    $lock->lock(1000000);
    try {
      $cmd = $this->getCommandInterfaceForLease($host_lease);
      $cmd->setExecTimeout(3600);

      $this->log(pht(
        'Fetching latest commits for repository at "%s"',
        $host_lease->getAttribute('path')));
      $cmd->exec('git fetch origin +refs/heads/*:refs/heads/*');
      $cmd->exec(
        'git fetch origin +refs/tags/phabricator/diff/*:'.
        'refs/tags/phabricator/diff/*');
      $this->log(pht('Fetched latest commits.'));

      $lock->unlock();
    } catch (Exception $ex) {
      $lock->unlock();
      throw $ex;
    }

    // We don't need to perform any cloning or initialization here, the leases
    // just count how many users of the working copy cache there are.

    $lease->setAttribute('path', $host_lease->getAttribute('path'));
  }

  private function getCommandInterfaceForLease(DrydockLease $lease) {
    if ($lease->getAttribute('platform') === 'windows') {
      return $lease->getInterface(
        'command-'.PhutilCommandString::MODE_WINDOWSCMD);
    } else {
      return $lease->getInterface(
        'command-'.PhutilCommandString::MODE_BASH);
    }
  }

  public function getType() {
    return 'working-copy-cache';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    throw new Exception(pht("No interface of type '%s'.", $type));
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    // No release logic require for leases.
  }

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    return false;
  }

  protected function executeCloseResource(DrydockResource $resource) {
    $this->log(pht(
      'Releasing resource host lease %d',
      $resource->getAttribute('host.lease')));
    try {
      $host_lease = $this->loadLease($resource->getAttribute('host.lease'));

      $host_resource = $host_lease->getResource();
      $host_blueprint = $host_resource->getBlueprint();
      $host_blueprint->releaseLease($host_resource, $host_lease);

      $this->log(pht(
        'Released resource host lease %d',
        $resource->getAttribute('host.lease')));
    } catch (Exception $ex) {
      $this->log(pht(
        'Unable to release resource host lease %d: "%s"',
        $resource->getAttribute('host.lease'),
        (string)$ex));
    }
  }


}
