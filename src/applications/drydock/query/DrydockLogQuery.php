<?php

final class DrydockLogQuery extends DrydockQuery {

  private $resourceIDs;
  private $leaseIDs;

  public function withResourceIDs(array $ids) {
    $this->resourceIDs = $ids;
    return $this;
  }

  public function withLeaseIDs(array $ids) {
    $this->leaseIDs = $ids;
    return $this;
  }

  public function loadPage() {
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

  public function willFilterPage(array $logs) {
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
        ->withIDs($resource_ids)
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
        ->withIDs($lease_ids)
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

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->resourceIDs) {
      $where[] = qsprintf(
        $conn_r,
        'resourceID IN (%Ld)',
        $this->resourceIDs);
    }

    if ($this->leaseIDs) {
      $where[] = qsprintf(
        $conn_r,
        'leaseID IN (%Ld)',
        $this->leaseIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

}
