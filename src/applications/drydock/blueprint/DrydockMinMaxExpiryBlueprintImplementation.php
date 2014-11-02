<?php

abstract class DrydockMinMaxExpiryBlueprintImplementation
  extends DrydockMinMaxBlueprintImplementation {

  public function canAllocateMoreResources(array $pool) {
    $max_count = $this->getDetail('max-count');

    $expiry = $this->getDetail('expiry');

    // Only count resources that haven't yet expired, so we can overallocate
    // if another expired resource is about to be closed (but is still waiting
    // on it's current resources to be released).
    $count = 0;
    $now = time();
    foreach ($pool as $resource) {
      $lifetime = $now - $resource->getDateCreated();
      if ($lifetime <= $expiry) {
        $count++;
      }
    }

    return $count < $max_count;
  }

  protected function shouldAllocateLease(
    DrydockAllocationContext $context,
    DrydockResource $resource,
    DrydockLease $lease) {

    // If we have no leases allocated to this resource, then we always allow
    // the parent logic to evaluate.  The reason for this is that an expired
    // resource can only be closed when a lease is released, so if the resource
    // is open and has no leases, then we'll never reach the code that checks
    // the expiry to close it.  So we allow this lease to occur, so that we'll
    // hit `shouldCloseUnleasedResource` in the future and the resource will
    // be closed.
    if ($context->getCurrentResourceLeaseCount() === 0) {
      return parent::shouldAllocateLease($context, $resource, $lease);
    }

    $expiry = $this->getDetail('expiry');

    if ($expiry !== null) {
      $lifetime = time() - $resource->getDateCreated();

      if ($lifetime > $expiry) {
        // Prevent allocation of leases to this resource, since it's over
        // it's lifetime allowed.
        return false;
      }
    }

    return parent::shouldAllocateLease($context, $resource, $lease);
  }

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    $expiry = $this->getDetail('expiry');

    if ($expiry !== null) {
      $lifetime = time() - $resource->getDateCreated();

      if ($lifetime > $expiry) {
        // Force closure of resources that have expired.
        return true;
      }
    }

    return parent::shouldCloseUnleasedResource($context, $resource);
  }

  public function getFieldSpecifications() {
    return array(
      'expiry-header' => array(
        'name' => pht('Resource Expiration'),
        'type' => 'header',
      ),
      'expiry' => array(
        'name' => pht('Expiry Time'),
        'type' => 'int',
        'caption' => pht(
          'After this time (in seconds) has elapsed since resource creation, '.
          'Drydock will no longer lease against the resource, and it will be '.
          'closed when there are no more leases (regardless of minimum '.
          'resource limits).'),
      ),
    ) + parent::getFieldSpecifications();
  }
}
