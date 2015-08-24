<?php

final class HarbormasterLeaseWorkingCopyFromURLBuildStepImplementation
  extends HarbormasterLeaseWorkingCopyBuildStepImplementation {

  public function getName() {
    return pht('Lease Working Copy from URL');
  }

  public function getGenericDescription() {
    return pht(
      'Obtain a lease on a Drydock working copy of a '.
      'repository at a given URL.');
  }

  protected function getLeaseAttributes(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target,
    array $settings) {

    return array(
      'url' => idx($settings, 'url'),
      'ref' => idx($settings, 'ref'),
    );
  }

  protected function getLeaseFieldSpecifications() {
    return array(
      'url' => array(
        'name' => pht('Repository URL'),
        'type' => 'text',
        'required' => true,
      ),
      'ref' => array(
        'name' => pht('Reference to Checkout'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. master'),
      ),
    );
  }

}
