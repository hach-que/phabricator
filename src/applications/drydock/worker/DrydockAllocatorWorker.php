<?php

final class DrydockAllocatorWorker extends PhabricatorWorker {

  private $lease;

  public function getRequiredLeaseTime() {
    return 3600 * 24;
  }

  public function getMaximumRetryCount() {
    // TODO: Allow Drydock allocations to retry. For now, every failure is
    // permanent and most of them are because I am bad at programming, so fail
    // fast rather than ending up in limbo.
    return 0;
  }

  private function loadLease() {
    if (empty($this->lease)) {
      $lease = id(new DrydockLeaseQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withIDs(array($this->getTaskData()))
        ->executeOne();
      if (!$lease) {
        throw new PhabricatorWorkerPermanentFailureException(
          pht('No such lease %d!', $this->getTaskData()));
      }
      $this->lease = $lease;
    }
    return $this->lease;
  }

  private function logToDrydock($message) {
    DrydockBlueprintImplementation::writeLog(
      null,
      null,
      $this->loadLease(),
      $message);
  }

  protected function doWork() {
    $lease = $this->loadLease();

    if ($lease->getStatus() != DrydockLeaseStatus::STATUS_PENDING) {
      // We can't handle non-pending leases.
      return;
    }

    $this->logToDrydock('Allocating Lease');

    try {
      $this->allocateLease($lease);
    } catch (Exception $ex) {

      // TODO: We should really do this when archiving the task, if we've
      // suffered a permanent failure. But we don't have hooks for that yet
      // and always fail after the first retry right now, so this is
      // functionally equivalent.
      $lease->reload();
      if ($lease->getStatus() == DrydockLeaseStatus::STATUS_PENDING ||
        $lease->getStatus() == DrydockLeaseStatus::STATUS_ACQUIRING) {
        $lease->setStatus(DrydockLeaseStatus::STATUS_BROKEN);
        $lease->setBrokenReason($ex->getMessage());
        $lease->save();
      }

      throw $ex;
    }
  }

  private function loadAllBlueprints() {
    $viewer = PhabricatorUser::getOmnipotentUser();
    $instances = id(new DrydockBlueprintQuery())
      ->setViewer($viewer)
      ->execute();
    $blueprints = array();
    foreach ($instances as $instance) {
      $blueprints[$instance->getPHID()] = $instance;
    }
    return $blueprints;
  }

  private function allocateLease(DrydockLease $lease) {
    $type = $lease->getResourceType();

    $blueprints = $this->loadAllBlueprints();

    $lock = PhabricatorGlobalLock::newLock('drydockallocation');
    $lock->lock(1000000);

    // TODO: Policy stuff.
    $pool = id(new DrydockResource())->loadAllWhere(
      'type = %s AND status = %s',
      $lease->getResourceType(),
      DrydockResourceStatus::STATUS_OPEN);

    $this->logToDrydock(
      pht('Found %d Open Resource(s)', count($pool)));

    $candidates = array();
    foreach ($pool as $key => $candidate) {
      if (!isset($blueprints[$candidate->getBlueprintPHID()])) {
        unset($pool[$key]);
        continue;
      }

      $blueprint = $blueprints[$candidate->getBlueprintPHID()];
      $implementation = $blueprint->getImplementation();

      if ($implementation->filterResource($candidate, $lease)) {
        $candidates[] = $candidate;
      }
    }

    $this->logToDrydock(pht('%d Open Resource(s) Remain', count($candidates)));

    $resource = null;
    if ($candidates) {
      shuffle($candidates);
      foreach ($candidates as $candidate_resource) {
        $blueprint = $blueprints[$candidate_resource->getBlueprintPHID()]
          ->getImplementation();
        if ($blueprint->allocateLease($candidate_resource, $lease)) {
          $resource = $candidate_resource;
          break;
        }
      }
    }

    if (!$resource) {
      // Attempt to use pending resources if we can.
      $pool = id(new DrydockResource())->loadAllWhere(
        'type = %s AND status = %s',
        $lease->getResourceType(),
        DrydockResourceStatus::STATUS_PENDING);

      $this->logToDrydock(
        pht('Found %d Pending Resource(s)', count($pool)));

      $candidates = array();
      foreach ($pool as $key => $candidate) {
        if (!isset($blueprints[$candidate->getBlueprintPHID()])) {
          unset($pool[$key]);
          continue;
        }

        $blueprint = $blueprints[$candidate->getBlueprintPHID()];
        $implementation = $blueprint->getImplementation();

        if ($implementation->filterResource($candidate, $lease)) {
          $candidates[] = $candidate;
        }
      }

      $this->logToDrydock(
        pht('%d Pending Resource(s) Remain',
        count($candidates)));

      $resource = null;
      if ($candidates) {
        shuffle($candidates);
        foreach ($candidates as $candidate_resource) {
          $blueprint = $blueprints[$candidate_resource->getBlueprintPHID()]
            ->getImplementation();
          if ($blueprint->allocateLease($candidate_resource, $lease)) {
            $resource = $candidate_resource;
            break;
          }
        }
      }
    }

    if ($resource) {
      $lock->unlock();
    } else {
      $blueprints = id(new DrydockBlueprintQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->execute();
      $blueprints = mpull($blueprints, 'getImplementation', 'getPHID');

      $this->logToDrydock(
        pht('Found %d Blueprints', count($blueprints)));

      foreach ($blueprints as $key => $candidate_blueprint) {
        if (!$candidate_blueprint->isEnabled()) {
          $this->logToDrydock(
            pht(
              '%s is not currently enabled',
              get_class($candidate_blueprint)));

          unset($blueprints[$key]);
          continue;
        }

        if ($candidate_blueprint->getType() !==
          $lease->getResourceType()) {
          $this->logToDrydock(
            pht(
              '%s does not allocate resources of the required type',
              get_class($candidate_blueprint)));

          unset($blueprints[$key]);
          continue;
        }
      }

      $this->logToDrydock(
        pht('%d Blueprints Enabled', count($blueprints)));

      $resources_per_blueprint = id(new DrydockResourceQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withStatuses(array(
          DrydockResourceStatus::STATUS_PENDING,
          DrydockResourceStatus::STATUS_OPEN,
          DrydockResourceStatus::STATUS_ALLOCATING))
        ->execute();
      $resources_per_blueprint = mgroup(
        $resources_per_blueprint,
        'getBlueprintPHID');

      try {
        foreach ($blueprints as $key => $candidate_blueprint) {
          $scope = $candidate_blueprint->pushActiveScope(null, $lease);

          $rpool = idx($resources_per_blueprint, $key, array());
          if (!$candidate_blueprint->canAllocateMoreResources($rpool)) {
            $this->logToDrydock(
              pht(
                '\'%s\' can\'t allocate more resources',
                $candidate_blueprint->getInstance()->getBlueprintName()));

            unset($blueprints[$key]);
            continue;
          }

          if (!$candidate_blueprint->canAllocateResourceForLease($lease)) {
            $this->logToDrydock(
              pht(
                '\'%s\' can\'t allocate a resource for this particular lease',
                $candidate_blueprint->getInstance()->getBlueprintName()));

            unset($blueprints[$key]);
            continue;
          }
        }

        $this->logToDrydock(
          pht('%d Blueprints Can Allocate', count($blueprints)));

        if (!$blueprints) {
          $reason = pht(
            "There are no resources of type '%s' available, and no ".
            "blueprints which can allocate new ones.",
            $type);

          $lease->setStatus(DrydockLeaseStatus::STATUS_BROKEN);
          $lease->setBrokenReason($reason);
          $lease->save();

          $this->logToDrydock($reason);

          $lock->unlock();
          return;
        }

        // TODO: Rank intelligently.
        shuffle($blueprints);

        $blueprint = head($blueprints);

        // Create and save the resource preemptively with STATUS_ALLOCATING
        // before we unlock, so that other workers will correctly count the
        // new resource "to be allocated" when determining if they can allocate
        // more resources to a blueprint.
        $resource = id(new DrydockResource())
          ->setBlueprintPHID($blueprint->getInstance()->getPHID())
          ->setType($blueprint->getType())
          ->setName(pht('Pending Allocation'))
          ->setStatus(DrydockResourceStatus::STATUS_ALLOCATING)
          ->save();

        $this->logToDrydock(
          pht('Created resources %d in \'allocating\' status.',
          $resource->getID()));

        // Pre-emptively allocate the lease on the resource inside the lock,
        // to ensure that other allocators don't cause this worker to lose
        // an allocation race.  If we fail to allocate a lease here, then the
        // blueprint is allocating resources it can't lease against.
        //
        // NOTE; shouldAllocateLease is specified to only check resource
        // constraints, which means that it shouldn't be checking compatibility
        // of details on resources or leases.  If there are any
        // shouldAllocateLease that use details on the resources or leases to
        // complete their work, then we might have to change this to:
        //
        //   $lease->setStatus(DrydockLeaseStatus::STATUS_ACQUIRING);
        //   $lease->setResourceID($resource->getID());
        //   $lease->attachResource($resource);
        //   $lease->save();
        //
        // and bypass the resource quota logic entirely (and just assume that
        // a resource allocated by a blueprint can have the lease allocated
        // against it).
        //
        $this->logToDrydock(
          pht('Pre-emptively allocating the lease against the new resource.'));

        if (!$blueprint->allocateLease($resource, $lease)) {
          throw new Exception(
            'Blueprint allocated a resource, but can\'t lease against it.');
        }

        $this->logToDrydock(
          pht('Pre-emptively allocated the lease against the new resource.'));

        // We now have to set the resource into Pending status, now that the
        // initial lease has been grabbed on the resource.  This ensures that
        // as soon as we leave the lock, other allocators can start taking
        // leases on it.  If we didn't do this, we can run into a scenario
        // where all resources are in "ALLOCATING" status when an allocator
        // runs, and instead of overleasing, the request would fail.
        //
        // TODO: I think this means we can remove the "ALLOCATING" status now,
        // but I'm not entirely sure.  It's only ever used inside the lock, so
        // I don't think any other allocators can race when attempting to
        // use a still-allocating resource.
        $resource
          ->setStatus(DrydockResourceStatus::STATUS_PENDING)
          ->save();

        $this->logToDrydock(
          pht('Moved the resource to the pending status.'));

        // We must allow some initial set up of resource attributes within the
        // lock such that when we exit, method calls to canAllocateLease will
        // succeed even for pending resources.
        $this->logToDrydock(
          pht('Started initialization of pending resource.'));

        $blueprint->initializePendingResource($resource, $lease);

        $this->logToDrydock(
          pht('Finished initialization of pending resource.'));

        $lock->unlock();
      } catch (Exception $ex) {
        $lock->unlock();
        throw $ex;
      }

      try {
        $this->logToDrydock(pht(
          'Allocating resource using blueprint \'%s\'.',
          $blueprint->getInstance()->getBlueprintName()));

        $blueprint->allocateResource($resource, $lease);
      } catch (Exception $ex) {
        $resource->delete();
        throw $ex;
      }

      // We do not need to call allocateLease here, because we have already
      // performed this check inside the lock.  If the logic at the end of the
      // lock changes to bypass allocateLease, then we probably need to do some
      // logic like (where STATUS_RESERVED does not count towards allocation
      // limits):
      //
      //   $lock->lock(10000);
      //   $lease->setStatus(DrydockLeaseStatus::STATUS_RESERVED);
      //   $lease->save();
      //   try {
      //     if (!$blueprint->allocateLease($resource, $lease)) {
      //       throw new Exception('Lost an allocation race?');
      //     }
      //   } catch (Exception $ex) {
      //     $lock->unlock();
      //     throw $ex;
      //   }
      //   $lock->unlock();
      //
    }

    $this->logToDrydock(pht(
      'Acquiring lease %d on resource %d using blueprint \'%s\'.',
      $lease->getID(),
      $resource->getID(),
      $blueprint->getInstance()->getBlueprintName()));

    $blueprint = $resource->getBlueprint();
    $blueprint->acquireLease($resource, $lease);
  }

}
