<?php

abstract class DrydockMinMaxBlueprintImplementation
  extends DrydockBlueprintImplementation {

  public function canAllocateMoreResources(array $pool) {
    $max_count = $this->getDetail('max-count');
    return count($pool) < $max_count;
  }

  protected function shouldAllocateLease(
    DrydockAllocationContext $context,
    DrydockResource $resource,
    DrydockLease $lease) {

    // If the current resource can allocate a lease, allow it.
    if ($context->getCurrentResourceLeaseCount() <
          $this->getDetail('leases-per-resource')) {
      return true;
    }

    // We don't have enough room under the `leases-per-instance` limit, but
    // this limit can be bypassed if we've allocated all of the resources
    // we allow.
    $open_count = $context->getBlueprintOpenResourceCount();
    if ($open_count < $this->getDetail('max-count')) {
      return false;
    }

    // Find the resource that has the least leases.
    $all_lease_counts_grouped = $context->getResourceLeaseCounts();
    $minimum_lease_count = $all_lease_counts_grouped[$resource->getID()];
    $minimum_lease_resource_id = $resource->getID();
    foreach ($all_lease_counts_grouped as $resource_id => $lease_count) {
      if ($minimum_lease_count > $lease_count) {
        $minimum_lease_count = $lease_count;
        $minimum_lease_resource_id = $resource_id;
      }
    }

    // If we are that resource, then allow it, otherwise let the other
    // less-leased resource run through this logic and allocate the lease.
    return $minimum_lease_resource_id === $resource->getID();
  }

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    return $context->getBlueprintOpenResourceCount() >
      $this->getDetail('min-count');
  }

  public function getFieldSpecifications() {
    return array(
      'min-max-header' => array(
        'name' => pht('Allocation Limits'),
        'type' => 'header',
      ),
      'min-count' => array(
        'name' => pht('Minimum Resources'),
        'type' => 'int',
        'required' => true,
        'caption' => pht(
          'The minimum number of resources to keep open in '.
          'this pool at all times.'),
      ),
      'max-count' => array(
        'name' => pht('Maximum Resources'),
        'type' => 'int',
        'caption' => pht(
          'The maximum number of resources to allow open at any time.  '.
          'If the number of resources currently open are equal to '.
          '`max-count` and another lease is requested, Drydock will place '.
          'leases on existing resources and thus exceeding '.
          '`leases-per-resource`.  If this parameter is left blank, then '.
          'this blueprint has no limit on the number of resources it '.
          'can allocate.'),
      ),
      'leases-per-resource' => array(
        'name' => pht('Maximum Leases Per Resource'),
        'type' => 'int',
        'required' => true,
        'caption' => pht(
          'The soft limit on the number of leases to allocate to an '.
          'individual resource in the pool.  Drydock will choose the '.
          'resource with the lowest number of leases when selecting a '.
          'resource to lease on.  If all current resources have '.
          '`leases-per-resource` leases on them, then Drydock will allocate '.
          'another resource providing `max-count` would not be exceeded.'.
          '  If `max-count` would be exceeded, Drydock will instead '.
          'overallocate the lease to an existing resource and '.
          'exceed the limit specified here.'),
      ),
    );
  }
}
