<?php

final class HarbormasterLeaseWorkingCopyFromRepositoryBuildStepImplementation
  extends HarbormasterLeaseWorkingCopyBuildStepImplementation {

  public function getName() {
    return pht('Lease Working Copy from Repository');
  }

  public function getGenericDescription() {
    return pht(
      'Obtain a lease on a Drydock working copy of a '.
      'repository hosted on Phabricator.');
  }

  protected function getLeaseAttributes(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target,
    array $settings) {

    $variables = $build_target->getVariables();
    $ref = $this->mergeVariables(
      'vsprintf',
      idx($settings, 'ref'),
      $variables);

    return array(
      'repositoryPHID' =>
        head(phutil_json_decode(idx($settings, 'repositoryPHID'))),
      'ref' => $ref,
    );
  }

  protected function getLeaseFieldSpecifications() {
    return array(
      'repositoryPHID' => array(
        'name' => pht('Repository'),
        'type' => 'repository',
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
