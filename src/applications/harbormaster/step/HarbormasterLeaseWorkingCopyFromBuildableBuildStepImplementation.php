<?php

final class HarbormasterLeaseWorkingCopyFromBuildableBuildStepImplementation
  extends HarbormasterLeaseWorkingCopyBuildStepImplementation {

  public function getName() {
    return pht('Lease Working Copy from Buildable');
  }

  public function getGenericDescription() {
    return pht(
      'Obtain a lease on a Drydock working copy of the '.
      'current buildable for performing builds.');
  }

  protected function getLeaseAttributes(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target,
    array $settings) {

    return array(
      'buildablePHID' => $build->getBuildablePHID(),
    );
  }

  protected function getLeaseFieldSpecifications() {
    return array();
  }

}
