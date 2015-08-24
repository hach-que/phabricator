<?php

final class PhragmentQueryFragmentsConduitAPIMethod
  extends PhragmentConduitAPIMethod {

  public function getAPIMethodName() {
    return 'phragment.queryfragments';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodDescription() {
    return pht('Query fragments based on their paths.');
  }

  protected function defineParamTypes() {
    return array(
      'paths' => 'required list<string>',
      'snapshot' => 'string'
    );
  }

  protected function defineReturnType() {
    return 'nonempty dict';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR_BAD_FRAGMENT' => pht('No such fragment exists'),
      'ERR_BAD_SNAPSHOT' => pht('No such snapshot exists'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $paths = $request->getValue('paths');
    $snapshot_name = $request->getValue('snapshot');

    $fragments = id(new PhragmentFragmentQuery())
      ->setViewer($request->getUser())
      ->needLatestVersion(true)
      ->withPaths($paths)
      ->execute();
    $fragments = mpull($fragments, null, 'getPath');
    foreach ($paths as $path) {
      if (!array_key_exists($path, $fragments)) {
        throw new ConduitException('ERR_BAD_FRAGMENT');
      }
    }

    $results = array();
    foreach ($fragments as $path => $fragment) {

      $snapshot_cache = null;
      if ($snapshot_name !== null) {
        $snapshot = id(new PhragmentSnapshotQuery())
          ->setViewer($request->getUser())
          ->withPrimaryFragmentPHIDs(array($fragment->getPHID()))
          ->withNames(array($snapshot_name))
          ->executeOne();
        if ($snapshot === null) {
          throw new ConduitException('ERR_BAD_SNAPSHOT');
        }

        $cache = id(new PhragmentSnapshotChildQuery())
          ->setViewer($request->getUser())
          ->needFragmentVersions(true)
          ->withSnapshotPHIDs(array($snapshot->getPHID()))
          ->execute();
        $snapshot_cache = mpull(
          $cache,
          'getFragmentVersion',
          'getFragmentPHID');
      }

      $mappings = $this->getFragmentMappings(
        $request->getUser(),
        $fragment,
        $fragment->getPath(),
        $snapshot_cache);

      $files = id(new PhabricatorFileQuery())
        ->setViewer($request->getUser())
        ->withPHIDs(ipull($mappings, 'filePHID'))
        ->execute();
      $files = mpull($files, null, 'getPHID');

      $result = array();
      foreach ($mappings as $cpath => $pair) {
        $child = $pair['fragment'];
        $file_phid = $pair['filePHID'];

        if (!isset($files[$file_phid])) {
          // Skip any files we don't have permission to access.
          continue;
        }

        $file = $files[$file_phid];
        $cpath = substr($child->getPath(), strlen($fragment->getPath()) + 1);
        $result[] = array(
          'phid' => $child->getPHID(),
          'phidVersion' => $pair['versionPHID'],
          'path' => $cpath,
          'hash' => $file->getContentHash(),
          'size' => $file->getByteSize(),
          'version' => $pair['version']->getSequence(),
          'uri' => $file->getMostDirectURI(),
        );
      }
      $results[$path] = $result;
    }
    return $results;
  }

  /**
   * Returns a list of mappings like array('some/path.txt' => 'file PHID');
   */
  private function getFragmentMappings(
    PhabricatorUser $user,
    PhragmentFragment $current,
    $base_path,
    $snapshot_cache) {

    $mappings = $current->getFragmentMappings(
      $user,
      $base_path);

    $result = array();
    foreach ($mappings as $path => $fragment) {
      $version = $this->getVersion($snapshot_cache, $fragment);
      if ($version !== null) {
        $result[$path] = array(
          'fragment' => $fragment,
          'version' => $version,
          'versionPHID' => $version->getPHID(),
          'filePHID' => $version->getFilePHID());
      }
    }
    return $result;
  }

  private function getVersion($snapshot_cache, $fragment) {
    if ($snapshot_cache === null) {
      return $fragment->getLatestVersion();
    } else {
      return idx($snapshot_cache, $fragment->getPHID(), null);
    }
  }

}
