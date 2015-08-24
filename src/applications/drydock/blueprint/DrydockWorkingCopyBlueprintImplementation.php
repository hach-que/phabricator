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
    return pht(
      'Allows Drydock to check out working '.
      'copies of repositories and revisions.');
  }

  private function resolveRelatedObjectsForLease(DrydockLease $lease) {
    if ($lease->getAttribute('resolved.target') !== null) {
      return;
    }

    $url = $lease->getAttribute('url');
    $ref = $lease->getAttribute('ref');
    $buildable_phid = $lease->getAttribute('buildablePHID');
    $repository_phid = $lease->getAttribute('repositoryPHID');

    if ($url) {
      $lease->setAttribute('resolved.target', 'url');
      $lease->setAttribute('resolved.repositoryURL', $url);
      $lease->setAttribute('resolved.repositoryReference', $ref);
      $lease->save();

      $this->log(pht(
        'Resolved working copy target as "url"'));
      $this->log(pht(
        'Resolved working copy repository URL as "%s"',
        $lease->getAttribute('resolved.repositoryURL')));
    } else if ($repository_phid) {
      $repository = id(new PhabricatorRepositoryQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($repository_phid))
        ->executeOne();
      if ($repository === null) {
        throw new Exception(pht(
          'No repository found with PHID %s',
          $repository_phid));
      }

      $lease->setAttribute('resolved.target', 'commit');
      $lease->setAttribute(
        'resolved.repositoryURL',
        $repository->getPublicCloneURI());
      $lease->setAttribute('resolved.commitIdentifier', $ref);
      $lease->save();

      $this->log(pht(
        'Resolved working copy target as "commit"'));
      $this->log(pht(
        'Resolved working copy commit identifier as "%s"',
        $lease->getAttribute('resolved.commitIdentifier')));
      $this->log(pht(
        'Resolved working copy repository URL as "%s"',
        $lease->getAttribute('resolved.repositoryURL')));
    } else if ($buildable_phid) {
      $buildable = id(new HarbormasterBuildableQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($buildable_phid))
        ->needContainerObjects(true)
        ->executeOne();
      if ($buildable === null) {
        throw new Exception(pht(
          'No buildable found with PHID %s',
          $buildable_phid));
      }
      $buildable_object = $buildable->getBuildableObject();
      $container_object = $buildable->getContainerObject();

      if ($buildable_object instanceof PhabricatorRepositoryCommit &&
          $container_object instanceof PhabricatorRepository) {
        $lease->setAttribute('resolved.target', 'commit');
        $lease->setAttribute(
          'resolved.commitIdentifier',
          $buildable_object->getCommitIdentifier());
        $lease->setAttribute(
          'resolved.repositoryURL',
          $container_object->getPublicCloneURI());
        $lease->save();

        $this->log(pht(
          'Resolved working copy target as "commit"'));
        $this->log(pht(
          'Resolved working copy commit identifier as "%s"',
          $lease->getAttribute('resolved.commitIdentifier')));
        $this->log(pht(
          'Resolved working copy repository URL as "%s"',
          $lease->getAttribute('resolved.repositoryURL')));
      } else if ($buildable_object instanceof DifferentialDiff &&
                 $container_object instanceof DifferentialRevision) {

        // For diffs and revisions, we use the staging URL for cloning, since
        // that's where the diff data will reside.  If there's no staging
        // repository configured, then we can't fulfill the lease.
        $repository = $container_object->getRepository();
        if (!$repository->supportsStaging()) {
          // TODO: Make this report a more detailed error message.
          $lease->setAttribute('resolved.target', 'diff.nostaging');
          return;
        }

        $staging_uri = $repository->getStagingURI();

        $lease->setAttribute('resolved.target', 'diff');
        $lease->setAttribute(
          'resolved.diffID',
          $buildable_object->getID());
        $lease->setAttribute(
          'resolved.repositoryURL',
          $staging_uri);
        $lease->save();

        $this->log(pht(
          'Resolved working copy target as "diff"'));
        $this->log(pht(
          'Resolved working copy diff ID as "%d"',
          $lease->getAttribute('resolved.diffID')));
        $this->log(pht(
          'Resolved working copy staging repository URI as "%s"',
          $lease->getAttribute('resolved.repositoryURL')));
      }
    }
  }

  public function canAllocateResourceForLease(DrydockLease $lease) {
    return true;
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $resource_url = $resource->getAttribute('repositoryURL');

    $this->resolveRelatedObjectsForLease($lease);

    $lease_url = $lease->getAttribute('resolved.repositoryURL');

    $can_allocate =
      ($resource_url && $lease_url && ($resource_url == $lease_url));

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

    $this->resolveRelatedObjectsForLease($lease);

    $target = $lease->getAttribute('resolved.target');
    if (!$target) {
      throw new Exception(
        'Unable to resolve working copy target for lease.');
    }

    $repository_url = $lease->getAttribute('resolved.repositoryURL');

    // We must set the platform so that other allocators will lease
    // against it successfully.
    $resource
      ->setAttribute('repositoryURL', $repository_url)
      ->save();
  }

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $repository_url = $lease->getAttribute('resolved.repositoryURL');

    $resource
      ->setName('Working Copy ('.$repository_url.')')
      ->setStatus(DrydockResourceStatus::STATUS_OPEN)
      ->setAttribute('repositoryURL', $repository_url)
      ->setAttribute('platform', $lease->getAttribute('platform'))
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

    $this->log('Acquiring new host lease for working copy...');

    $host_attributes = array();
    foreach ($lease->getAttributes() as $key => $value) {
      if (substr($key, 0, 5) === 'attr_') {
        $host_attributes[$key] = $value;
      }
    }

    $host_lease = id(new DrydockLease())
      ->setResourceType('host')
      ->setAttributes(
        array(
          'platform' => $lease->getAttribute('platform'),
        ) + $host_attributes)
      ->waitUntilActive();

    $lease->setAttribute('host.lease', $host_lease->getID());

    list($cache_lease, $source_url) = $this->tryAcquireWorkingCopyCache(
      $host_lease->getResource(),
      $lease->getAttribute('resolved.repositoryURL'));

    $cmd = $this->getCommandInterfaceForLease($lease);

    $this->log(pht(
      'Cloning from "%s" to lease path "%s"',
      $source_url,
      $host_lease->getAttribute('path')));
    $cmd->execx(
      'git clone %s .',
      $source_url);
    $this->log(pht('Cloned from %s', $source_url));

    $this->tryReleaseWorkingCopyCache($cache_lease);

    if ($lease->getAttribute('resolved.target') === 'commit') {
      $this->log(pht(
        'Checking out target commit "%s"',
        $lease->getAttribute('resolved.commitIdentifier')));
      $cmd->execx(
        'git checkout -f %s',
        $lease->getAttribute('resolved.commitIdentifier'));
      $this->log(pht('Checked out commit'));
    } else if ($lease->getAttribute('resolved.target') === 'diff') {
      $this->log(pht(
        'Checking out target diff at tag "phabricator/diff/%d"',
        $lease->getAttribute('resolved.diffID')));
      $cmd->execx(
        'git checkout -f phabricator/diff/%d',
        $lease->getAttribute('resolved.diffID'));
      $this->log(pht('Checked out diff'));
    } else if ($lease->getAttribute('resolved.target') === 'url') {
      if ($lease->getAttribute('resolved.repositoryReference') !== null) {
        $this->log(pht(
          'Checking out target reference "%s"',
          $lease->getAttribute('resolved.repositoryReference')));
        $cmd->execx(
          'git checkout -f %s',
          $lease->getAttribute('resolved.repositoryReference'));
        $this->log(pht('Checked out reference'));
      } else {
        $this->log(pht(
          'No target reference provided, leaving working directory as-is'));
      }
    } else {
      throw new Exception(pht(
        'Target type %s not yet supported.',
        $lease->getAttribute('resolved.target')));
    }

    $this->initializeGitSubmodules(
      $host_lease,
      $host_lease->getAttribute('path'));
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

  private function tryAcquireWorkingCopyCache(
    DrydockResource $host_resource,
    $url) {

    $cache_lease = id(new DrydockLease())
      ->setResourceType('working-copy-cache')
      ->setAttributes(
        array(
          'host.resource' => $host_resource->getID(),
          'url' => $url,
        ))
      ->queueForActivation();

    $this->log(pht(
      'Attempting to acquire working copy cache lease %d for URL %s',
      $cache_lease->getID(),
      $url));

    try {
      $cache_lease->waitUntilActive();

      $this->log(pht(
        'Acquired working copy cache lease %d for URL %s',
        $cache_lease->getID(),
        $url));

      $source_url = $cache_lease->getAttribute('path');
    } catch (Exception $ex) {
      $this->log(pht(
        'Unable to acquire working copy cache lease %d for URL %s, '.
        'will perform clone directly from the source URL',
        $cache_lease->getID(),
        $url));

      $cache_lease = null;
      $source_url = $url;
    }

    return array($cache_lease, $source_url);
  }

  private function tryReleaseWorkingCopyCache(
    DrydockLease $cache_lease = null) {

    if ($cache_lease !== null) {
      $cache_lease_id = $cache_lease->getID();

      $this->log(pht(
        'Releasing working copy cache lease %d',
        $cache_lease_id));

      try {
        $cache_resource = $cache_lease->getResource();
        $cache_blueprint = $cache_resource->getBlueprint();
        $cache_blueprint->releaseLease($cache_resource, $cache_lease);

        $this->log(pht(
          'Released working copy cache lease %d',
          $cache_lease_id));
      } catch (Exception $ex) {
        $this->log(pht(
          'Unable to release working copy cache lease %d: "%s"',
          $cache_lease_id,
          (string)$ex));
      }
    } else {
      $this->log(pht(
        'No working copy cache lease to release'));
    }
  }

  private function initializeGitSubmodules(
    DrydockLease $target_lease,
    $target_path = null) {

    $cmd = $this->getCommandInterfaceForLease($target_lease);
    $cmd->setWorkingDirectory($target_path);

    $this->log(pht(
      'Initializing submodules in %s',
      $target_path));
    $cmd->execx('git submodule init');
    $this->log(pht(
      'Initialized submodules in %s',
      $target_path));

    $this->log(pht(
      'Discovering initialized submodules in %s',
      $target_path));
    list($stdout, $stderr) = $cmd->execx('git config --local --list');
    $matches = null;
    preg_match_all(
      '/submodule\.(?<name>.*)\.url=(?<url>.*)/',
      $stdout,
      $matches);
    $submodules = array();
    for ($i = 0; $i < count($matches['name']); $i++) {
      $name = $matches['name'][$i];
      $url = $matches['url'][$i];

      $submodules[$name] = $url;

      $this->log(pht(
        'Discovered submodule %s registered with URL %s',
        $name,
        $url));
    }

    foreach ($submodules as $name => $url) {
      list($cache_lease, $source_url) = $this->tryAcquireWorkingCopyCache(
        $target_lease->getResource(),
        $url);

      $this->log(pht(
        'Updating local submodule URL to point to %s',
        $source_url));

      $cmd->execx('git config --local submodule.%s.url %s', $name, $source_url);

      $this->log(pht(
        'Updating submodule %s',
        $name));

      $cmd->execx('git submodule update %s', $name);

      $this->log(pht(
        'Recursively initializing submodules for %s',
        $name));

      $this->initializeGitSubmodules(
        $target_lease,
        $target_path.'/'.$name);

      $this->log(pht(
        'Recursive submodule initialization complete for %s',
        $name));

      $this->tryReleaseWorkingCopyCache($cache_lease);

      $this->log(pht(
        'Updating local submodule URL to point back to %s',
        $url));

      $cmd->execx('git config --local submodule.%s.url %s', $name, $url);
    }

    $this->log(pht(
      'Submodules initialized for working directory at %s',
      $target_path));
  }

  public function getType() {
    return 'working-copy';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    return $this
      ->loadLease($lease->getAttribute('host.lease'))
      ->getInterface($type);
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $this->log(pht(
      'Releasing host lease %d',
      $lease->getAttribute('host.lease')));
    try {
      $host_lease = $this->loadLease($lease->getAttribute('host.lease'));

      $host_resource = $host_lease->getResource();
      $host_blueprint = $host_resource->getBlueprint();
      $host_blueprint->releaseLease($host_resource, $host_lease);

      $this->log(pht(
        'Released host lease %d',
        $lease->getAttribute('host.lease')));
    } catch (Exception $ex) {
      $this->log(pht(
        'Unable to release host lease %d: "%s"',
        $lease->getAttribute('host.lease'),
        (string)$ex));
    }
  }

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    return true;
  }

  protected function executeCloseResource(DrydockResource $resource) {
    // No work to be done closing resources (they are just for grouping).
  }


}
