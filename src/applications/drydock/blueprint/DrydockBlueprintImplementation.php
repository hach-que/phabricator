<?php

/**
 * @task lease      Lease Acquisition
 * @task resource   Resource Allocation
 * @task log        Logging
 */
abstract class DrydockBlueprintImplementation extends Phobject {

  private $activeResource;
  private $activeLease;
  private $instance;
  private $scopes = array();

  abstract public function getType();
  abstract public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type);

  abstract public function isEnabled();

  public function isTest() {
    return false;
  }

  abstract public function getBlueprintName();
  abstract public function getDescription();

  public function getBlueprintClass() {
    return get_class($this);
  }

  protected function loadLease($lease_id) {
    // TODO: Get rid of this?
    $query = id(new DrydockLeaseQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($lease_id))
      ->execute();

    $lease = idx($query, $lease_id);

    if (!$lease) {
      throw new Exception(pht("No such lease '%d'!", $lease_id));
    }

    return $lease;
  }

  public function getInstance() {
    if (!$this->instance) {
      throw new Exception(
        pht('Attach the blueprint instance to the implementation.'));
    }

    return $this->instance;
  }

  public function attachInstance(DrydockBlueprint $instance) {
    $this->instance = $instance;
    return $this;
  }

  public function supportsAutomaticCustomAttributes() {
    return true;
  }

  public function getFieldSpecifications() {
    if ($this->supportsAutomaticCustomAttributes()) {
      return array(
        'attr-header' => array(
          'name' => pht('Custom Attributes'),
          'type' => 'header',
        ),
        'attributes' => array(
          'name' => pht('Attributes'),
          'type' => 'textarea',
          'caption' => pht(
            'A newline separated list of custom blueprint '.
            'attributes.  Each attribute should be specified in '.
            'a key=value format.'),
          'monospace' => true,
        ),
      );
    } else {
      return array();
    }
  }

  public function getDetail($key, $default = null) {
    return $this->getInstance()->getDetail($key, $default);
  }


/* -(  Lease Acquisition  )-------------------------------------------------- */


  /**
   * @task lease
   */
  final public function filterResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $scope = $this->pushActiveScope($resource, $lease);

    if ($this->supportsAutomaticCustomAttributes()) {
      $custom_match = DrydockCustomAttributes::hasRequirements(
        $lease->getAttributes(),
        $this->getDetail('attributes', ''));

      if (!$custom_match) {
        return false;
      }
    }

    return $this->canAllocateLease($resource, $lease);
  }


  /**
   * Enforce basic checks on lease/resource compatibility. Allows resources to
   * reject leases if they are incompatible, even if the resource types match.
   *
   * For example, if a resource represents a 32-bit host, this method might
   * reject leases that need a 64-bit host. If a resource represents a working
   * copy of repository "X", this method might reject leases which need a
   * working copy of repository "Y". Generally, although the main types of
   * a lease and resource may match (e.g., both "host"), it may not actually be
   * possible to satisfy the lease with a specific resource.
   *
   * This method generally should not enforce limits or perform capacity
   * checks. Perform those in @{method:shouldAllocateLease} instead. It also
   * should not perform actual acquisition of the lease; perform that in
   * @{method:executeAcquireLease} instead.
   *
   * @param   DrydockResource   Candidiate resource to allocate the lease on.
   * @param   DrydockLease      Pending lease that wants to allocate here.
   * @return  bool              True if the resource and lease are compatible.
   * @task lease
   */
  abstract protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease);


  /**
   * @task lease
   */
  final public function allocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $scope = $this->pushActiveScope($resource, $lease);

    $this->log(pht('Trying to Allocate Lease'));

    $lease->setStatus(DrydockLeaseStatus::STATUS_ACQUIRING);
    $lease->setResourceID($resource->getID());
    $lease->attachResource($resource);

    $ephemeral_lease = id(clone $lease)->makeEphemeral();

    $allocated = false;
    $allocation_exception = null;

    $context = new DrydockAllocationContext(
      $this->getInstance(),
      $resource,
      $lease);

    $resource->openTransaction();
      $resource->beginReadLocking();
        $resource->reload();

        // TODO: Policy stuff.
        $other_leases = id(new DrydockLease())->loadAllWhere(
          'status IN (%Ld) AND resourceID = %d',
          array(
            DrydockLeaseStatus::STATUS_ACQUIRING,
            DrydockLeaseStatus::STATUS_ACTIVE,
          ),
          $resource->getID());

        try {
          $allocated = $this->shouldAllocateLease(
            $context,
            $resource,
            $ephemeral_lease);
        } catch (Exception $ex) {
          $allocation_exception = $ex;
        }

        if ($allocated) {
          $lease->save();
        }
      $resource->endReadLocking();
    if ($allocated) {
      $resource->saveTransaction();
      $this->log(pht('Allocated Lease'));
    } else {
      $resource->killTransaction();
      $this->log(pht('Failed to Allocate Lease'));
    }

    if ($allocation_exception) {
      $lease->setStatus(DrydockLeaseStatus::STATUS_BROKEN);
      $lease->setBrokenReason($allocation_exception->getMessage());
      $lease->save();
      $this->logException($allocation_exception);
      $this->closeResourceIfDesired($resource);
    }

    return $allocated;
  }


  /**
   * Enforce lease limits on resources. Allows resources to reject leases if
   * they would become over-allocated by accepting them.
   *
   * For example, if a resource represents disk space, this method might check
   * how much space the lease is asking for (say, 200MB) and how much space is
   * left unallocated on the resource. It could grant the lease (return true)
   * if it has enough remaining space (more than 200MB), and reject the lease
   * (return false) if it does not (less than 200MB).
   *
   * A resource might also allow only exclusive leases. In this case it could
   * accept a new lease (return true) if there are no active leases, or reject
   * the new lease (return false) if there any other leases.
   *
   * A lock is held on the resource while this method executes to prevent
   * multiple processes from allocating leases on the resource simultaneously.
   * However, this means you should implement the method as cheaply as possible.
   * In particular, do not perform any actual acquisition or setup in this
   * method.
   *
   * If allocation is permitted, the lease will be moved to `ACQUIRING` status
   * and @{method:executeAcquireLease} will be called to actually perform
   * acquisition.
   *
   * General compatibility checks unrelated to resource limits and capacity are
   * better implemented in @{method:canAllocateLease}, which serves as a
   * cheap filter before lock acquisition.
   *
   * @param   DrydockAllocationContext Relevant contextual information.
   * @param   DrydockResource     Candidate resource to allocate the lease on.
   * @param   DrydockLease        Pending lease that wants to allocate here.
   * @task lease
   */
  abstract protected function shouldAllocateLease(
    DrydockAllocationContext $context,
    DrydockResource $resource,
    DrydockLease $lease);


  /**
   * @task lease
   */
  final public function acquireLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $scope = $this->pushActiveScope($resource, $lease);

    $this->log(pht('Acquiring Lease'));
    $lease->setStatus(DrydockLeaseStatus::STATUS_ACTIVE);
    $lease->setResourceID($resource->getID());
    $lease->attachResource($resource);

    $ephemeral_lease = id(clone $lease)->makeEphemeral();

    try {
      $this->executeAcquireLease($resource, $ephemeral_lease);
    } catch (Exception $ex) {
      $lease->setStatus(DrydockLeaseStatus::STATUS_BROKEN);
      $lease->setBrokenReason($ex->getMessage());
      $lease->save();
      $this->logException($ex);
      $this->closeResourceIfDesired($resource);
      throw $ex;
    }

    $lease->setAttributes($ephemeral_lease->getAttributes());
    $lease->save();
    $this->log(pht('Acquired Lease'));
  }


  /**
   * Acquire and activate an allocated lease. Allows resources to peform setup
   * as leases are brought online.
   *
   * Following a successful call to @{method:canAllocateLease}, a lease is moved
   * to `ACQUIRING` status and this method is called after resource locks are
   * released. Nothing is locked while this method executes; the implementation
   * is free to perform expensive operations like writing files and directories,
   * executing commands, etc.
   *
   * After this method executes, the lease status is moved to `ACTIVE` and the
   * original leasee may access it.
   *
   * If acquisition fails, throw an exception.
   *
   * @param   DrydockResource   Resource to acquire a lease on.
   * @param   DrydockLease      Lease to acquire.
   * @return  void
   */
  abstract protected function executeAcquireLease(
    DrydockResource $resource,
    DrydockLease $lease);


  /**
   * Release an allocated lease, performing any desired cleanup.
   *
   * After this method executes, the lease status is moved to `RELEASED`.
   *
   * If release fails, throw an exception.
   *
   * @param   DrydockResource   Resource to release the lease from.
   * @param   DrydockLease      Lease to release.
   * @return  void
   */
  abstract protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease);

  final public function releaseLease(
    DrydockResource $resource,
    DrydockLease $lease,
    $caused_by_closing_resource = false) {
    $scope = $this->pushActiveScope(null, $lease);

    $released = false;

    $lease->openTransaction();
      $lease->beginReadLocking();
        $lease->reload();

        if ($lease->getStatus() == DrydockLeaseStatus::STATUS_ACTIVE) {
          $lease->setStatus(DrydockLeaseStatus::STATUS_RELEASED);
          $lease->save();
          $released = true;
        }

      $lease->endReadLocking();
    $lease->saveTransaction();

    // Execute clean up outside of the lock and don't perform clean up if the
    // resource is closing anyway, because in that scenario, the closing
    // resource will clean up all the leases anyway (e.g. an EC2 host being
    // terminated that contains leases on it's instance storage).
    if ($released && !$caused_by_closing_resource) {
      $this->executeReleaseLease($resource, $lease);
    }

    if (!$released) {
      throw new Exception(pht('Unable to release lease: lease not active!'));
    }

    if (!$caused_by_closing_resource) {
      $this->closeResourceIfDesired($resource);
    }
  }

  private function closeResourceIfDesired(
    DrydockResource $resource) {

    // Check to see if the resource has no more leases, and if so, ask the
    // blueprint as to whether this resource should be closed.
    $context = new DrydockAllocationContext(
      $this->getInstance(),
      $resource,
      null);

    if ($context->getCurrentResourceLeaseCount() === 0) {
      if ($this->shouldCloseUnleasedResource($context, $resource)) {
        self::writeLog(
          $resource,
          null,
          pht('Closing resource because it has no more active leases'));
        $this->closeResource($resource);
      }
    }
  }


/* -(  Resource Allocation  )------------------------------------------------ */


  public function canAllocateMoreResources(array $pool) {
    return true;
  }

  public function canAllocateResourceForLease(DrydockLease $lease) {
    return true;
  }

  abstract protected function executeInitializePendingResource(
    DrydockResource $resource,
    DrydockLease $lease);

  abstract protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease);

  /**
   * Closes a previously allocated resource, performing any desired
   * cleanup.
   *
   * After this method executes, the release status is moved to `CLOSED`.
   *
   * If release fails, throw an exception.
   *
   * @param   DrydockResource   Resource to close.
   * @return  void
   */
  abstract protected function executeCloseResource(
    DrydockResource $resource);

  /**
   * Return whether or not a resource that now has no leases on it
   * should be automatically closed.
   *
   * @param DrydockAllocationContext Relevant contextual information.
   * @param DrydockResource       The resource that has no more leases on it.
   * @return bool
   */
  abstract protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource);

  final public function closeResource(DrydockResource $resource) {
    $resource->openTransaction();
      $resource->setStatus(DrydockResourceStatus::STATUS_CLOSING);
      $resource->save();

      $statuses = array(
        DrydockLeaseStatus::STATUS_PENDING,
        DrydockLeaseStatus::STATUS_ACTIVE,
      );

      $leases = id(new DrydockLeaseQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withResourceIDs(array($resource->getID()))
        ->withStatuses($statuses)
        ->execute();

      foreach ($leases as $lease) {
        switch ($lease->getStatus()) {
          case DrydockLeaseStatus::STATUS_PENDING:
            $message = pht('Breaking pending lease (resource closing).');
            $lease->setStatus(DrydockLeaseStatus::STATUS_BROKEN);
            $lease->setBrokenReason('Resource forcibly closed');
            break;
          case DrydockLeaseStatus::STATUS_ACTIVE:
            $message = pht('Releasing active lease (resource closing).');
            $this->releaseLease($resource, $lease, true);
            $lease->setStatus(DrydockLeaseStatus::STATUS_RELEASED);
            break;
        }
        self::writeLog($resource, $lease, $message);
        $lease->save();
      }

      $this->executeCloseResource($resource);

      $resource->setStatus(DrydockResourceStatus::STATUS_CLOSED);
      $resource->save();
    $resource->saveTransaction();
  }

  final public function initializePendingResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $scope = $this->pushActiveScope($resource, $lease);

    $this->log(pht(
      'Blueprint \'%s\': Initializing Resource for \'%s\'',
      $this->getBlueprintClass(),
      $lease->getLeaseName()));

    try {
      $this->executeInitializePendingResource($resource, $lease);
    } catch (Exception $ex) {
      $this->logException($ex);
      throw $ex;
    }
  }

  final public function allocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $scope = $this->pushActiveScope(null, $lease);

    $this->log(
      pht(
        "Blueprint '%s': Allocating Resource for '%s'",
        $this->getBlueprintClass(),
        $lease->getLeaseName()));

    try {
      $this->executeAllocateResource($resource, $lease);
      $this->validateAllocatedResource($resource);
    } catch (Exception $ex) {
      $this->logException($ex);
      throw $ex;
    }

    return $resource;
  }


/* -(  Logging  )------------------------------------------------------------ */


  /**
   * @task log
   */
  protected function logException(Exception $ex) {
    $this->log($ex->getMessage());
  }


  /**
   * @task log
   */
  protected function log($message) {
    self::writeLog(
      $this->instance,
      $this->activeResource,
      $this->activeLease,
      $message);
  }


  /**
   * @task log
   */
  public static function writeLog(
    DrydockBlueprint $blueprint = null,
    DrydockResource $resource = null,
    DrydockLease $lease = null,
    $message = null) {

    $log = id(new DrydockLog())
      ->setEpoch(time())
      ->setMessage($message);

    if ($blueprint) {
      $log->setBlueprintPHID($blueprint->getPHID());
    }

    if ($resource) {
      $log->setResourceID($resource->getID());
    }

    if ($lease) {
      $log->setLeaseID($lease->getID());
    }

    $log->save();
  }


  public static function getAllBlueprintImplementations() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass(__CLASS__)
      ->execute();
  }

  public static function getAllBlueprintImplementationsForResource($type) {
    static $groups = null;
    if ($groups === null) {
      $groups = mgroup(self::getAllBlueprintImplementations(), 'getType');
    }
    return idx($groups, $type, array());
  }

  public static function getNamedImplementation($class) {
    return idx(self::getAllBlueprintImplementations(), $class);
  }

  /**
   * Sanity checks that the blueprint is implemented properly.
   */
  private function validateAllocatedResource($resource) {
    $blueprint = $this->getBlueprintClass();

    if (!($resource instanceof DrydockResource)) {
      throw new Exception(
        pht(
          "Blueprint '%s' is not properly implemented: %s must return an ".
          "object of type %s or throw, but returned something else.",
          $blueprint,
          'executeAllocateResource()',
          'DrydockResource'));
    }

    $current_status = $resource->getStatus();
    $req_status = DrydockResourceStatus::STATUS_OPEN;
    if ($current_status != $req_status) {
      $current_name = DrydockResourceStatus::getNameForStatus($current_status);
      $req_name = DrydockResourceStatus::getNameForStatus($req_status);
      throw new Exception(
        pht(
          "Blueprint '%s' is not properly implemented: %s must return a %s ".
          "with status '%s', but returned one with status '%s'.",
          $blueprint,
          'executeAllocateResource()',
          'DrydockResource',
          $req_name,
          $current_name));
    }
  }

  public function pushActiveScope(
    DrydockResource $resource = null,
    DrydockLease $lease = null) {

    $scope = array(
      'resource' => $resource,
      'lease' => $lease,
    );
    array_push($this->scopes, $scope);

    $this->activeResource = $resource;
    $this->activeLease = $lease;

    return new DrydockBlueprintScopeGuard($this);
  }

  public function popActiveScope() {
    if (count($this->scopes) === 0) {
      throw new Exception('Unable to pop active scope; no scopes active');
    }

    array_pop($this->scopes);

    if (count($this->scopes) === 0) {
      $this->activeResource = null;
      $this->activeLease = null;
    } else {
      $current = last($this->scopes);
      $this->activeResource = $current['resource'];
      $this->activeLease = $current['lease'];
    }
  }

}
