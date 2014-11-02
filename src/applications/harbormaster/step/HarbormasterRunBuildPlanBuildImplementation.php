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

    $target_plan_id = $this->getSetting('id');

    // Get the target build plan type.
    $target_plan = id(new HarbormasterBuildPlanQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($target_plan_id))
      ->executeOne();
    if (!$target_plan) {
      throw new Exception('Unable to find build plan with ID '.$target_plan_id);
    }

    // If the target plan is the same as the current plan, we
    // automatically pass.
    if ($target_plan->getPHID() === $build->getBuildPlanPHID()) {
      return;
    }

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
        $target_build = $other_build;
        break;
      }
    }

    if ($target_build === null) {
      // There is no current build with this build plan on the buildable,
      // so now we're going to start one.
      $target_build = $build->getBuildable()->applyPlan($target_plan);
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

    $artifact = $build->createArtifact(
      $build_target,
      'build_plan_'.$build_target->getID(),
      HarbormasterBuildArtifact::TYPE_URI);
    $artifact->setArtifactData(array(
      'name' => 'Target Build Plan',
      'uri' => '/harbormaster/plan/'.$target_plan->getID(),));
    $artifact->save();

    $artifact = $build->createArtifact(
      $build_target,
      'build_'.$build_target->getID(),
      HarbormasterBuildArtifact::TYPE_URI);
    $artifact->setArtifactData(array(
      'name' => 'Target Build',
      'uri' => '/harbormaster/build/'.$target_build->getID(),));
    $artifact->save();

    $target_build_phid = $target_build->getPHID();

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
        $target_build->getBuildStatus() === HarbormasterBuild::STATUS_STOPPED) {
        sleep(15);
        continue;
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
      'id' => array(
        'name' => pht('Target Build Plan ID'),
        'type' => 'text',
        'required' => true,
      ),
    );
  }


}
