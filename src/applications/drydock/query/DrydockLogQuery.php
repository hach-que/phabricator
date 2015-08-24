<?php

final class DrydockLogQuery extends DrydockQuery {

  private $blueprintPHIDs;
  private $resourceIDs;
  private $leaseIDs;

  public function withBlueprintPHIDs(array $phids) {
    $this->blueprintPHIDs = $phids;
    return $this;
  }

  public function withResourceIDs(array $ids) {
    $this->resourceIDs = $ids;
    return $this;
  }

  public function withLeaseIDs(array $ids) {
    $this->leaseIDs = $ids;
    return $this;
  }

  protected function loadPage() {
    $table = new DrydockLog();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT log.* FROM %T log %Q %Q %Q',
      $table->getTableName(),
      $this->buildWhereClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    return $table->loadAllFromArray($data);
  }

  protected function willFilterPage(array $logs) {
    $blueprint_phids = array_filter(mpull($logs, 'getBlueprintPHID'));
    if ($blueprint_phids) {
      $blueprints = id(new DrydockBlueprintQuery())
        ->setParentQuery($this)
        ->setViewer($this->getViewer())
        ->withPHIDs($blueprint_phids)
        ->execute();
      $blueprints = mpull($blueprints, null, 'getPHID');
    } else {
      $blueprints = array();
    }

    foreach ($logs as $key => $log) {
      $blueprint = null;
      if ($log->getBlueprintPHID()) {
        $blueprint = idx($blueprints, $log->getBlueprintPHID());
        if (!$blueprint) {
          unset($logs[$key]);
          continue;
        }
      }
      $log->attachBlueprint($blueprint);
    }

    $resource_ids = array_filter(mpull($logs, 'getResourceID'));
    if ($resource_ids) {
      $resources = id(new DrydockResourceQuery())
        ->setParentQuery($this)
        ->setViewer($this->getViewer())
        ->withIDs(array_unique($resource_ids))
        ->execute();
    } else {
      $resources = array();
    }

    foreach ($logs as $key => $log) {
      $resource = null;
      if ($log->getResourceID()) {
        $resource = idx($resources, $log->getResourceID());
        if (!$resource) {
          $log->attachResource(null);
          continue;
        }
      }
      $log->attachResource($resource);
    }

    $lease_ids = array_filter(mpull($logs, 'getLeaseID'));
    if ($lease_ids) {
      $leases = id(new DrydockLeaseQuery())
        ->setParentQuery($this)
        ->setViewer($this->getViewer())
        ->withIDs(array_unique($lease_ids))
        ->execute();
    } else {
      $leases = array();
    }

    foreach ($logs as $key => $log) {
      $lease = null;
      if ($log->getLeaseID()) {
        $lease = idx($leases, $log->getLeaseID());
        if (!$lease) {
          $log->attachLease(null);
          continue;
        }
      }
      $log->attachLease($lease);
    }

    return $logs;
  }

  protected function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->blueprintPHIDs !== null) {
      $where[] = qsprintf(
        $conn_r,
        'blueprintPHID IN (%Ls)',
        $this->blueprintPHIDs);
    }

    if ($this->resourceIDs !== null) {
      $where[] = qsprintf(
        $conn_r,
        'resourceID IN (%Ld)',
        $this->resourceIDs);
    }

    if ($this->leaseIDs !== null) {
      $where[] = qsprintf(
        $conn_r,
        'leaseID IN (%Ld)',
        $this->leaseIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

}
