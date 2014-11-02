<?php

final class DrydockAllocationContext extends Phobject {

  private $blueprintOpenResourceCount;
  private $resourceLeaseCounts;
  private $currentResourceLeaseCount;

  public function __construct(
    DrydockBlueprint $blueprint,
    DrydockResource $resource = null,
    DrydockLease $lease = null) {

    $table_blueprint = $blueprint->getTableName();
    $table_resource = id(new DrydockResource())->getTableName();
    $table_lease = id(new DrydockLease())->getTableName();

    $conn = $blueprint->establishConnection('r');

    $result = queryfx_one(
      $conn,
      'SELECT COUNT(id) AS count '.
      'FROM %T '.
      'WHERE blueprintPHID = %s '.
      'AND status IN (%Ld)',
      $table_resource,
      $blueprint->getPHID(),
      array(
        DrydockResourceStatus::STATUS_PENDING,
        DrydockResourceStatus::STATUS_OPEN,
        DrydockResourceStatus::STATUS_ALLOCATING));
    $this->setBlueprintOpenResourceCount($result['count']);

    $results = queryfx_all(
      $conn,
      'SELECT '.
      '  resource.id AS resourceID, '.
      '  COUNT(lease.id) AS leaseCount '.
      'FROM %T AS resource '.
      'LEFT JOIN %T AS lease '.
      '  ON lease.resourceID = resource.id '.
      'WHERE resource.blueprintPHID = %s '.
      'AND resource.status IN (%Ld) '.
      'AND lease.status IN (%Ld) '.
      'GROUP BY resource.id',
      $table_resource,
      $table_lease,
      $blueprint->getPHID(),
      array(
        DrydockResourceStatus::STATUS_PENDING,
        DrydockResourceStatus::STATUS_OPEN,
        DrydockResourceStatus::STATUS_ALLOCATING),
      array(
        DrydockLeaseStatus::STATUS_PENDING,
        DrydockLeaseStatus::STATUS_ACQUIRING,
        DrydockLeaseStatus::STATUS_ACTIVE));
    $results = ipull($results, 'leaseCount', 'resourceID');
    $this->setResourceLeaseCounts($results);

    if ($resource !== null) {
      $this->setCurrentResourceLeaseCount(idx($results, $resource->getID(), 0));
    }

    // $lease is not yet used, but it's passed in so we can add additional
    // contextual statistics later.
  }

  public function setBlueprintOpenResourceCount($blueprint_resource_count) {
    $this->blueprintOpenResourceCount = $blueprint_resource_count;
    return $this;
  }

  public function getBlueprintOpenResourceCount() {
    return $this->blueprintOpenResourceCount;
  }

  public function setResourceLeaseCounts($resource_lease_counts) {
    $this->resourceLeaseCounts = $resource_lease_counts;
    return $this;
  }

  public function getResourceLeaseCounts() {
    return $this->resourceLeaseCounts;
  }

  public function setCurrentResourceLeaseCount($resource_lease_counts) {
    $this->currentResourceLeaseCount = $resource_lease_counts;
    return $this;
  }

  public function getCurrentResourceLeaseCount() {
    return $this->currentResourceLeaseCount;
  }

}
