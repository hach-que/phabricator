<?php

final class HarbormasterLeaseHostBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Lease Host');
  }

  public function getGenericDescription() {
    return pht('Obtain a lease on a Drydock host for performing builds.');
  }

  public function getBuildStepGroupKey() {
    return HarbormasterPrototypeBuildStepGroup::GROUPKEY;
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $settings = $this->getSettings();

    // This build step is reentrant, because waitUntilActive may
    // throw PhabricatorWorkerYieldException.  Check to see if there
    // is already a lease on the build target, and if so, wait until
    // that lease is active instead of creating a new one.
    $artifacts = id(new HarbormasterBuildArtifactQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withBuildTargetPHIDs(array($build_target->getPHID()))
      ->execute();
    $artifact = count($artifacts) > 0 ? head($artifacts) : null;

    if ($artifact === null) {
      $custom_attributes = DrydockCustomAttributes::parse(
        idx($settings, 'attributes', ''));

      // Create the lease.
      $lease = id(new DrydockLease())
        ->setResourceType('host')
        ->setOwnerPHID($build_target->getPHID())
        ->setAttributes(
          array(
            'platform' => $settings['platform'],
          ) + $custom_attributes)
        ->queueForActivation();

      // Create the associated artifact.
      $artifact = $build_target->createArtifact(
        PhabricatorUser::getOmnipotentUser(),
        $settings['name'],
        HarbormasterHostArtifact::ARTIFACTCONST,
        array(
          'drydockLeasePHID' => $lease->getPHID(),
        ));
    } else {
      // Load the lease.
      $impl = $artifact->getArtifactImplementation();
      $lease = $impl->loadArtifactLease(PhabricatorUser::getOmnipotentUser());
    }

    // Wait until the lease is fulfilled.
    try {
      $lease->waitUntilActive();
    } catch (PhabricatorWorkerYieldException $ex) {
      throw $ex;
    } catch (Exception $ex) {
      throw new HarbormasterBuildFailureException($ex->getMessage());
    }
  }

  public function getArtifactOutputs() {
    return array(
      array(
        'name' => pht('Leased Host'),
        'key' => $this->getSetting('name'),
        'type' => HarbormasterHostArtifact::ARTIFACTCONST,
      ),
    );
  }

  public function getFieldSpecifications() {
    return array(
      'name' => array(
        'name' => pht('Artifact Name'),
        'type' => 'text',
        'required' => true,
      ),
      'platform' => array(
        'name' => pht('Platform'),
        'type' => 'text',
        'required' => true,
      ),
      'attributes' => array(
        'name' => pht('Required Attributes'),
        'type' => 'textarea',
        'caption' => pht(
          'A newline separated list of required host attributes.  Each '.
          'attribute should be specified in a key=value format.'),
        'monospace' => true,
      ),
    );
  }

}
