<?php

final class HarbormasterPublishFragmentBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Publish Fragment');
  }

  public function getGenericDescription() {
    return pht('Publish a fragment based on a file artifact.');
  }


  public function getBuildStepGroupKey() {
    return HarbormasterPrototypeBuildStepGroup::GROUPKEY;
  }

  public function getDescription() {

    $values = array();
    $publish = $this->getSetting('publish_on');
    $publish_text = '';
    if (in_array('commit', $publish)) {
      $values[] = pht('commits');
    }
    if (in_array('revision', $publish)) {
      $values[] = pht('differential revisions');
    }
    if (count($values) === 0) {
      $publish_text = pht('Never');
    } else {
      $publish_text = pht('For %s,', implode(' and ', $values));
    }

    return pht(
      '%s publish file artifact %s as fragment %s.',
      $publish_text,
      $this->formatSettingForDescription('artifact'),
      $this->formatSettingForDescription('path'));
  }

  public function logBehaviour(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target,
    $message) {
    $log_behaviour = $build->createLog($build_target, 'publish', 'result');
    $start_behaviour = $log_behaviour->start();
    $log_behaviour->append($message);
    $log_behaviour->finalize($start_behaviour);
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $settings = $this->getSettings();
    $variables = $build_target->getVariables();
    $viewer = PhabricatorUser::getOmnipotentUser();

    // Check if we should publish for this buildable.
    $buildable = $build->getBuildable();
    $object = $buildable->getBuildableObject();
    if ($object instanceof PhabricatorRepositoryCommit) {
      if (!in_array('commit', $settings['publish_on'])) {
        $this->logBehaviour(
          $build,
          $build_target,
          'Not publishing because this is a commit and this step only '.
          'publishes for '.implode(', ', $settings['publish_on']));
        return;
      }
    } else if ($object instanceof DifferentialDiff) {
      if (!in_array('revision', $settings['publish_on'])) {
        $this->logBehaviour(
          $build,
          $build_target,
          'Not publishing because this is a revision and this step only '.
          'publishes for '.implode(', ', $settings['publish_on']));
        return;
      }
    } else {
      throw new Exception('Unknown buildable type!');
    }

    $path = $this->mergeVariables(
      'vsprintf',
      $settings['path'],
      $variables);

    $artifact = $build_target->loadArtifact($settings['artifact']);
    $impl = $artifact->getArtifactImplementation();
    $file = $impl->loadArtifactFile($viewer);

    $fragment = id(new PhragmentFragmentQuery())
      ->setViewer($viewer)
      ->withPaths(array($path))
      ->executeOne();

    if ($fragment === null) {
      PhragmentFragment::createFromFile(
        $viewer,
        $file,
        $path,
        PhabricatorPolicies::getMostOpenPolicy(),
        PhabricatorPolicies::POLICY_USER);
    } else {
      if ($file->getMimeType() === 'application/zip') {
        $fragment->updateFromZIP($viewer, $file);
      } else {
        $fragment->updateFromFile($viewer, $file);
      }
    }

    $this->logBehaviour(
      $build,
      $build_target,
      'The artifact was published successfully.');
  }

  public function getArtifactInputs() {
    return array(
      array(
        'name' => pht('Publishes File'),
        'key' => $this->getSetting('artifact'),
        'type' => HarbormasterFileArtifact::ARTIFACTCONST,
      ),
    );
  }

  public function getFieldSpecifications() {
    return array(
      'path' => array(
        'name' => pht('Path'),
        'type' => 'text',
        'required' => true,
      ),
      'artifact' => array(
        'name' => pht('File Artifact'),
        'type' => 'text',
        'required' => true,
      ),
      'publish_on' => array(
        'name' => pht('Publish On'),
        'type' => 'buildabletype',
      ),
    );
  }

}
