<?php

final class DrydockLeaseQuery extends DrydockQuery {

  private $ids;
  private $phids;
  private $resourceIDs;
  private $statuses;
  private $datasourceQuery;
  private $needOwnerHandles;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withResourceIDs(array $ids) {
    $this->resourceIDs = $ids;
    return $this;
  }

  public function withStatuses(array $statuses) {
    $this->statuses = $statuses;
    return $this;
  }

  public function newResultObject() {
    return new DrydockLease();
  }

  public function withDatasourceQuery($query) {
    $this->datasourceQuery = $query;
    return $this;
  }

  protected function loadPage() {
    return $this->loadStandardPage($this->newResultObject());
  }

  public function needOwnerHandles($owner_handles) {
    $this->needOwnerHandles = $owner_handles;
    return $this;
  }

  protected function willFilterPage(array $leases) {
    $resource_ids = array_filter(mpull($leases, 'getResourceID'));
    if ($resource_ids) {
      $resources = id(new DrydockResourceQuery())
        ->setParentQuery($this)
        ->setViewer($this->getViewer())
        ->withIDs(array_unique($resource_ids))
        ->execute();
    } else {
      $resources = array();
    }

    foreach ($leases as $key => $lease) {
      $resource = null;
      if ($lease->getResourceID()) {
        $resource = idx($resources, $lease->getResourceID());
        if (!$resource) {
          unset($leases[$key]);
          continue;
        }
      }
      $lease->attachResource($resource);
    }

    return $leases;
  }

  protected function didFilterPage(array $page) {
    if ($this->needOwnerHandles) {
      $owner_phids = mpull($page, 'getOwnerPHID');
      foreach ($owner_phids as $key => $phid) {
        if ($phid === null) {
          unset($owner_phids[$key]);
        }
      }

      $handles = id(new PhabricatorHandleQuery())
        ->setViewer($this->getViewer())
        ->setParentQuery($this)
        ->withPHIDs($owner_phids)
        ->execute();
      $handles = mpull($handles, null, 'getPHID');

      foreach ($page as $lease) {
        $lease->attachOwnerHandle(idx($handles, $lease->getOwnerPHID()));
      }
    }

    return $page;
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->resourceIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'resourceID IN (%Ld)',
        $this->resourceIDs);
    }

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'phid IN (%Ls)',
        $this->phids);
    }

    if ($this->statuses !== null) {
      $where[] = qsprintf(
        $conn,
        'status IN (%Ld)',
        $this->statuses);
    }

    if ($this->datasourceQuery !== null) {
      $where[] = qsprintf(
        $conn,
        'id = %d',
        (int)$this->datasourceQuery);
    }

    return $where;
  }

}
