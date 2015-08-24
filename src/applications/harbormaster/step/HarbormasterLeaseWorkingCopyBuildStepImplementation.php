<?php

abstract class HarbormasterLeaseWorkingCopyBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  abstract protected function getLeaseAttributes(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target,
    array $settings);

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
        ->setResourceType('working-copy')
        ->setOwnerPHID($build_target->getPHID())
        ->setAttributes(
          array(
            'platform' => $settings['platform'],
            'buildablePHID' => $build->getBuildablePHID(),
          ) + $this->getLeaseAttributes($build, $build_target, $settings)
          + $custom_attributes)
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
        'name' => pht('Leased Working Copy'),
        'key' => $this->getSetting('name'),
        'type' => HarbormasterHostArtifact::ARTIFACTCONST,
      ),
    );
  }

  abstract protected function getLeaseFieldSpecifications();

  public function getFieldSpecifications() {
    return array(
      'name' => array(
        'name' => pht('Artifact Name'),
        'type' => 'text',
        'required' => true,
      ),
      'platform' => array(
        'name' => pht('Host Platform'),
        'type' => 'text',
        'required' => true,
      ),
    ) + $this->getLeaseFieldSpecifications() + array(
      'attributes' => array(
        'name' => pht('Required Attributes'),
        'type' => 'textarea',
        'caption' => pht(
          'A newline separated list of required working copy attributes.  '.
          'Each attribute should be specified in a key=value format.'),
        'monospace' => true,
      ),
    );
  }

}
