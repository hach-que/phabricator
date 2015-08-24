<?php

final class PhragmentFileController extends PhragmentController {

  private $dblob;
  private $snapshot;

  private $snapshotCache;

  public function shouldAllowPublic() {
    return true;
  }

  public function willProcessRequest(array $data) {
    $this->dblob = idx($data, 'dblob', '');
    $this->snapshot = idx($data, 'snapshot', null);
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $parents = $this->loadParentFragments($this->dblob);
    if ($parents === null) {
      return new Aphront404Response();
    }
    $fragment = idx($parents, count($parents) - 1, null);

    if ($this->snapshot !== null) {
      $snapshot = id(new PhragmentSnapshotQuery())
        ->setViewer($viewer)
        ->withPrimaryFragmentPHIDs(array($fragment->getPHID()))
        ->withNames(array($this->snapshot))
        ->executeOne();
      if ($snapshot === null) {
        return new Aphront404Response();
      }

      $cache = id(new PhragmentSnapshotChildQuery())
        ->setViewer($viewer)
        ->needFragmentVersions(true)
        ->withSnapshotPHIDs(array($snapshot->getPHID()))
        ->execute();
      $this->snapshotCache = mpull(
        $cache,
        'getFragmentVersion',
        'getFragmentPHID');
    }

    $version = null;
    if ($this->snapshot === null) {
      $version = $fragment->getLatestVersion();
    } else {
      $version = idx($this->snapshotCache, $fragment->getPHID(), null);
    }

    $return = $fragment->getURI();
    if ($request->getExists('return')) {
      $return = $request->getStr('return');
    };

    $file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($version->getFilePHID()))
      ->executeOne();
    return id(new AphrontRedirectResponse())
      ->setURI($file->getDownloadURI($return))
      ->setIsExternal(true);
  }

}
