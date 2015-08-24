<?php

/**
 * @group conduit
 */
final class PhragmentQuerySnapshotsConduitAPIMethod
  extends PhragmentConduitAPIMethod {

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getAPIMethodName() {
    return 'phragment.querysnapshots';
  }

  public function getMethodDescription() {
    return pht('Query snapshots based on their path.');
  }

  public function defineParamTypes() {
    return array(
      'path' => 'required string'
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array();
  }

  protected function execute(ConduitAPIRequest $request) {
    $path = $request->getValue('path');

    $fragment = id(new PhragmentFragmentQuery())
      ->setViewer($request->getUser())
      ->withPaths(array($path))
      ->executeOne();

    $snapshots = id(new PhragmentSnapshotQuery())
      ->setViewer($request->getUser())
      ->withPrimaryFragmentPHIDs(array($fragment->getPHID()))
      ->execute();

    $results = array();
    foreach ($snapshots as $snapshot) {
      $results[] = array(
        'phid' => $snapshot->getPHID(),
        'name' => $snapshot->getName());
    }

    return $results;
  }

}
