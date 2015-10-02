<?php

final class HarbormasterRunBuildPlanBuildImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Start or Wait for Build Plan');
  }

  public function getGenericDescription() {
    return pht(
      'Start or wait for another build plan to finish on the same buildable.');
  }

  public function getDescription() {
    $target_plan_id = $this->getSetting('id');

    $target_plan = id(new HarbormasterBuildPlanQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($target_plan_id))
      ->executeOne();

    if ($target_plan === null) {
      return pht(
        'Start and / or wait for a non-existent build plan '.
        'to finish on this buildable.');
    }

    $name = $this->formatValueForDescription($target_plan->getName());

    return pht(
      'Start and / or wait for build plan %s to finish on this buildable.',
      $name);
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    if ($build_target->getVariable('targetBuildPHID') === null) {
      // Get the target build plan type.
      $target_plan = id(new HarbormasterBuildPlanQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs($build_target->getFieldValue('buildPlanPHIDs'))
        ->executeOne();
      if (!$target_plan) {
        throw new Exception('Build plan does not exist');
      }

      // If the target plan is the same as the current plan, we
      // automatically pass.
      if ($target_plan->getPHID() === $build->getBuildPlanPHID()) {
        return;
      }
      
      // Parse the parameters.
      $parameters = self::parseParameters(
        $build_target,
        $this->getSetting('parameters'));
      $parameters_hash = self::getBuildParametersHashForArray($parameters);

      // Find all other builds running on this buildable.
      $buildable = $build->getBuildable();
      $other_builds = id(new HarbormasterBuildQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withBuildablePHIDs(array($buildable->getPHID()))
        ->execute();

      // Find the build for that build plan (if it exists).
      $target_build = null;
      foreach ($other_builds as $other_build) {
        if ($other_build->getBuildPlanPHID() === $target_plan->getPHID()) {
          $other_parameters_hash = self::getBuildParametersHashForArray(
            $other_build->getBuildParameters());
          if ($other_parameters_hash === $parameters_hash) {
            $target_build = $other_build;
            break;
          }
        }
      }

      if ($target_build === null) {
        // There is no current build with this build plan on the buildable,
        // so now we're going to start one.
        $target_build = $build->getBuildable()->applyPlan(
          $target_plan,
          $parameters,
          null);
        
        try {
          $build_target->createArtifact(
            PhabricatorUser::getOmnipotentUser(),
            'Target Build Plan',
            HarbormasterURIArtifact::ARTIFACTCONST,
            array(
              'uri' => PhabricatorEnv::getProductionURI(
                '/harbormaster/plan/'.$target_plan->getID()),
              'name' => $target_plan->getName(),
            ));

          $build_target->createArtifact(
            PhabricatorUser::getOmnipotentUser(),
            'Target Build',
            HarbormasterURIArtifact::ARTIFACTCONST,
            array(
              'uri' => PhabricatorEnv::getProductionURI(
                '/harbormaster/build/'.$target_build->getID()),
              'name' => 'Build '.$target_build->getID(),
            ));
        } catch (Exception $ex) {
        }
      } else {
        // If the build plan has failed on a previous run, restart it.
        switch ($target_build->getBuildStatus()) {
          case HarbormasterBuild::STATUS_FAILED:
          case HarbormasterBuild::STATUS_ERROR: {
            $harbormaster_phid = id(new PhabricatorHarbormasterApplication())
              ->getPHID();

            $daemon_source = PhabricatorContentSource::newForSource(
              PhabricatorContentSource::SOURCE_DAEMON,
              array());

            $editor = id(new HarbormasterBuildTransactionEditor())
              ->setActor(PhabricatorUser::getOmnipotentUser())
              ->setActingAsPHID($harbormaster_phid)
              ->setContentSource($daemon_source)
              ->setContinueOnNoEffect(true)
              ->setContinueOnMissingFields(true);

            $xaction = id(new HarbormasterBuildTransaction())
              ->setTransactionType(HarbormasterBuildTransaction::TYPE_COMMAND)
              ->setNewValue(HarbormasterBuildCommand::COMMAND_RESTART);

            $editor->applyTransactions($target_build, array($xaction));

            break;
          }
        }
      }
      
      $build_target->setVariable('targetBuildPHID', $target_build->getPHID());
      $build_target->save();
    }
    
    $target_build_phid = $build_target->getVariable('targetBuildPHID');

    do {
      $target_build = id(new HarbormasterBuildQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($target_build_phid))
        ->executeOne();

      if (!$target_build) {
        throw new Exception('Build has disappeared!');
      }

      // If the build has not yet passed, yield for the moment.
      if ($target_build->isBuilding() || $target_build->isRestarting() ||
        $target_build->getBuildStatus() === HarbormasterBuild::STATUS_PAUSED) {
        throw new PhabricatorWorkerYieldException(10);
      }

      // Otherwise check the result and if it's an error or failure result,
      // then throw an exception.
      switch ($target_build->getBuildStatus()) {
        case HarbormasterBuild::STATUS_FAILED:
        case HarbormasterBuild::STATUS_ERROR:
          throw new HarbormasterBuildFailureException();
      }

      // Pass if we get to here.
      break;
    } while (true);
  }

  public function getFieldSpecifications() {
    return array(
      'buildPlanPHIDs' => array(
        'name' => pht('Build Plan'),
        'type' => 'datasource',
        'datasource.class' => 'HarbormasterBuildPlanDatasource',
      ),
      'parameters' => array(
        'name' => pht('Build Plan Parameters'),
        'type' => 'textarea',
        'caption' => pht(
          'A newline separated list of parameters to pass into the build.  '.
          'Each attribute should be specified in a key=value format.'),
        'monospace' => true,
      ),
    );
  }

  private function parseParameters(
    HarbormasterBuildTarget $build_target,
    $text) {

    if (trim($text) === '') {
      return array();
    }

    $variables = $build_target->getVariables();
    $text = $this->mergeVariables(
      'vsprintf',
      $text,
      $variables);

    $pairs = phutil_split_lines($text);
    $attributes = array();

    foreach ($pairs as $line) {
      $kv = explode('=', $line, 2);
      if (count($kv) === 0) {
        continue;
      } else if (count($kv) === 1) {
        $attributes[$kv[0]] = true;
      } else {
        $attributes[$kv[0]] = trim($kv[1]);
      }
    }

    return $attributes;
  }

  private function getBuildParametersHashForArray(array $parameters) {
    return md5(print_r($parameters, true));
   }
}
