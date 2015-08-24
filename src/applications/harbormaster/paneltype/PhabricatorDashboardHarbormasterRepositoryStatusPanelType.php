<?php

final class PhabricatorDashboardHarbormasterRepositoryStatusPanelType
  extends PhabricatorDashboardPanelType {

  public function getPanelTypeKey() {
    return 'harbormaster-container-repository';
  }

  public function getPanelTypeName() {
    return pht('Harbormaster Repository Build Status');
  }

  public function getPanelTypeDescription() {
    return pht(
      'Show current build status of each repository\'s branch, '.
      'indicating whether the commit pointed to by the '.
      'branch has passed or failed all of it\'s builds.');
  }

  public function getFieldSpecifications() {
    return array(
      // TODO Add a field here that allows filtering repositories
      // by a query or by a project?
      'manual-buildables' => array(
        'name' => pht('Include Manual Buildables?'),
        'caption' => pht(
          'Consider manual buildables when '.
          'evaluting repository status.'),
        'type' => 'bool',
      ),
      'limit' => array(
        'name' => pht('Limit'),
        'caption' => pht('Leave this blank for the default number of items.'),
        'type' => 'text',
      ),
    );
  }

  public function renderPanelContent(
    PhabricatorUser $viewer,
    PhabricatorDashboardPanel $panel,
    PhabricatorDashboardPanelRenderingEngine $engine) {

    $manual_buildables = $panel->getProperty('manual-buildables');

    $repositories = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->execute();

    $branches = id(new PhabricatorRepositoryRefCursorQuery())
      ->setViewer($viewer)
      ->withRepositoryPHIDs(mpull($repositories, 'getPHID'))
      // TODO: Make this configurable
      ->withRefTypes(array(PhabricatorRepositoryRefCursor::TYPE_BRANCH))
      ->execute();
    $branches_grouped = mgroup($branches, 'getRepositoryPHID');

    $commits = id(new DiffusionCommitQuery())
      ->setViewer($viewer)
      ->withIdentifiers(mpull($branches, 'getCommitIdentifier'))
      ->execute();
    $commit_phids = mpull($commits, 'getPHID', 'getCommitIdentifier');

    $buildables = id(new HarbormasterBuildableQuery())
      ->setViewer($viewer)
      ->withBuildablePHIDs($commit_phids)
      ->withManualBuildables($manual_buildables)
      ->execute();
    $buildables = mpull($buildables, null, 'getBuildablePHID');

    $any_buildables = id(new HarbormasterBuildableQuery())
      ->setViewer($viewer)
      ->withContainerPHIDs(mpull($repositories, 'getPHID'))
      ->withManualBuildables($manual_buildables)
      ->execute();
    $any_buildables = mpull($any_buildables, null, 'getContainerPHID');

    $failed = HarbormasterBuildable::STATUS_FAILED;
    $building = HarbormasterBuildable::STATUS_BUILDING;
    $passed = HarbormasterBuildable::STATUS_PASSED;

    $reported_builds = array();
    foreach ($repositories as $repository) {
      $repo_branches = idx($branches_grouped, $repository->getPHID(), array());

      foreach ($repo_branches as $repo_branch) {
        $has_build = true;

        if (!$repository->shouldTrackBranch($repo_branch->getRefName())) {
          continue;
        }

        $commit_phid = idx($commit_phids, $repo_branch->getCommitIdentifier());
        if ($commit_phid === null) {
          $has_build = false;
        } else {
          $buildable = idx($buildables, $commit_phid);
          if ($buildable === null) {
            $has_build = false;
          }
        }

        if (!$has_build) {
          if (idx($any_buildables, $repository->getPHID()) === null) {
            // This repository has never been built.
            continue;
          } else {
            $buildable_status = 'unknown';
            $buildable_uri =
              'diffusion/'.$repository->getCallsign().
              '/history/'.$repo_branch->getRefName().'/';
          }
        } else {
          $buildable_status = $buildable->getBuildableStatus();
          $buildable_uri = $buildable->getMonogram();
        }

        $priority = 100;
        if ($buildable_status === $failed) {
          $priority = 20;
        } else if ($buildable_status === $building) {
          $priority = 40;
        } else if ($buildable_status === $passed) {
          $priority = 60;
        }

        $reported_builds[] = array(
          'buildable_priority' => $priority,
          'buildable_status' => $buildable_status,
          'buildable_uri' => '/'.$buildable_uri,
          'repository_name' => $repository->getName(),
          'repository_ref_name' => $repo_branch->getRefName(),
        );
      }
    }

    if (count($reported_builds) === 0) {
      return id(new PHUIPropertyListView())
        ->addTextContent(pht(
          'There are no repositories that have '.
          'had commits built.'));
    }

    $reported_builds = isort($reported_builds, 'buildable_priority');

    $list = new PHUIStatusListView();
    $limit = $panel->getProperty('limit');
    if ($limit === null) {
      $limit = 10;
    }
    $shown = 0;
    $tally_passed = 0;
    $tally_unknown = 0;
    foreach ($reported_builds as $build) {
      $status = idx($build, 'buildable_status');

      if ($shown < $limit || $status === $failed || $status === $building) {
        $item = id(new PHUIStatusItemView())
          ->setIcon(
            HarbormasterBuildable::getBuildableStatusIcon($status),
            HarbormasterBuildable::getBuildableStatusColor($status),
            HarbormasterBuildable::getBuildableStatusName($status))
          ->setTarget(phutil_tag(
            'a',
            array(
            'href' =>
              PhabricatorEnv::getProductionURI(idx($build, 'buildable_uri')),
            ),
            idx($build, 'repository_name')))
          ->setNote(idx($build, 'repository_ref_name'));
        $list->addItem($item);
        $shown++;
        continue;
      }

      switch ($status) {
        case $passed:
          $tally_passed++;
          break;
        default:
          $tally_unknown++;
          break;
      }
    }

    if ($tally_passed + $tally_unknown > 0) {
      $total = $tally_passed + $tally_unknown;
      $status = pht(
        '%d more (%d passed, %d unknown)',
        $total,
        $tally_passed,
        $tally_unknown);
      $item = id(new PHUIStatusItemView())
        ->setTarget(pht('...'))
        ->setNote($status);
      $list->addItem($item);
    }

    return id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_MEDIUM_TOP)
      ->addPadding(PHUI::PADDING_MEDIUM_BOTTOM)
      ->appendChild($list);
  }

}
