<?php

final class PhragmentFragment extends PhragmentDAO
  implements PhabricatorPolicyInterface {

  protected $path;
  protected $depth;
  protected $latestVersionPHID;
  protected $viewPolicy;
  protected $editPolicy;

  private $latestVersion = self::ATTACHABLE;

  protected function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_COLUMN_SCHEMA => array(
        'path' => 'path',
        'depth' => 'uint32',
        'latestVersionPHID' => 'phid?',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_path' => array(
          'columns' => array('path'),
          'unique' => true,
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhragmentFragmentPHIDType::TYPECONST);
  }

  public function getURI() {
    return '/phragment/browse/'.$this->getPath();
  }

  public function getName() {
    return basename($this->path);
  }

  public function getFile() {
    return $this->assertAttached($this->file);
  }

  public function attachFile(PhabricatorFile $file) {
    return $this->file = $file;
  }

  public function isDirectory() {
    return $this->latestVersionPHID === null;
  }

  public function isDeleted() {
    return $this->getLatestVersion()->getFilePHID() === null;
  }

  public function getLatestVersion() {
    if ($this->latestVersionPHID === null) {
      return null;
    }
    return $this->assertAttached($this->latestVersion);
  }

  public function attachLatestVersion(PhragmentFragmentVersion $version) {
    return $this->latestVersion = $version;
  }


/* -(  Updating  )  --------------------------------------------------------- */


  /**
   * Create a new fragment from a file.
   */
  public static function createFromFile(
    PhabricatorUser $viewer,
    PhabricatorFile $file = null,
    $path = null,
    $view_policy = null,
    $edit_policy = null) {

    // Calculate all preceding paths.
    $path_components = explode('/', $path);
    array_pop($path_components);
    $path_preceding = array();
    $path_buffer = '';
    for ($i = 0; $i < count($path_components); $i++) {
      $path_buffer .= $path_components[$i].'/';
      $path_preceding[] = rtrim($path_buffer, '/');
    }

    // Query for preceding fragments (use omnipotent user so
    // we don't double up on paths because the user can't see
    // them).
    $preceding_fragments = id(new PhragmentFragmentQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPaths($path_preceding)
      ->execute();
    $preceding_fragments = mpull($preceding_fragments, null, 'getPath');

    // Create preceding path fragments.
    foreach ($path_preceding as $path_to_check) {
      if (!array_key_exists($path_to_check, $preceding_fragments)) {
        $fragment = id(new PhragmentFragment());
        $fragment->setPath($path_to_check);
        $fragment->setDepth(count(explode('/', $path_to_check)));
        $fragment->setLatestVersionPHID(null);
        $fragment->setViewPolicy($view_policy);
        $fragment->setEditPolicy($edit_policy);
        $fragment->save();
      }
    }

    // Create the new fragment.
    $fragment = id(new PhragmentFragment());
    $fragment->setPath($path);
    $fragment->setDepth(count(explode('/', $path)));
    $fragment->setLatestVersionPHID(null);
    $fragment->setViewPolicy($view_policy);
    $fragment->setEditPolicy($edit_policy);
    $fragment->save();

    // Directory fragments have no versions associated with them, so we
    // just return the fragment at this point.
    if ($file === null) {
      return $fragment;
    }

    if ($file->getMimeType() === 'application/zip') {
      $fragment->updateFromZIP($viewer, $file);
    } else {
      $fragment->updateFromFile($viewer, $file);
    }

    return $fragment;
  }


  /**
   * Set the specified file as the next version for the fragment.
   */
  public function updateFromFile(
    PhabricatorUser $viewer,
    PhabricatorFile $file) {

    $existing = id(new PhragmentFragmentVersionQuery())
      ->setViewer($viewer)
      ->withFragmentPHIDs(array($this->getPHID()))
      ->execute();
    $sequence = count($existing);

    $this->openTransaction();
      $version = id(new PhragmentFragmentVersion());
      $version->setSequence($sequence);
      $version->setFragmentPHID($this->getPHID());
      $version->setFilePHID($file->getPHID());
      $version->save();

      $this->setLatestVersionPHID($version->getPHID());
      $this->save();
    $this->saveTransaction();

    $file->attachToObject($version->getPHID());
  }

  /**
   * Apply the specified ZIP archive onto the fragment, removing
   * and creating fragments as needed.
   */
  public function updateFromZIP(
    PhabricatorUser $viewer,
    PhabricatorFile $file) {

    if ($file->getMimeType() !== 'application/zip') {
      throw new Exception(
        pht("File must have mimetype '%s'.", 'application/zip'));
    }

    // First apply the ZIP as normal.
    $this->updateFromFile($viewer, $file);

    // Ensure we have ZIP support.
    $zip = null;
    try {
      $zip = new ZipArchive();
    } catch (Exception $e) {
      // The server doesn't have php5-zip, so we can't do recursive updates.
      return;
    }

    $temp = new TempFile();
    Filesystem::writeFile($temp, $file->loadFileData());
    if (!$zip->open($temp)) {
      throw new Exception(pht('Unable to open ZIP.'));
    }

    // Get all of the paths and their data from the ZIP.
    $mappings = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $path = trim($zip->getNameIndex($i), '/');
      $stream = $zip->getStream($path);
      $data = null;
      // If the stream is false, then it is a directory entry. We leave
      // $data set to null for directories so we know not to create a
      // version entry for them.
      if ($stream !== false) {
        $data = stream_get_contents($stream);
        fclose($stream);
      }
      $mappings[$path] = $data;
    }


    // Sort the mappings by key in the dictionary, so that iterating over it
    // will result in entries like:
    //
    // a/ => null
    // a/e.txt => ...
    // b/c.txt => ...
    // b/d.txt => ...
    //
    // This is important because if there are explicit directory entries in the
    // ZIP, that might be because they are empty directories (in which case
    // there's no call to self::createFromFile that would create them) or they
    // could be out-of-order explicit directories where an unsorted list
    // would cause a duplicate key exception.  By sorting this, we ensure that
    // explicit directories are always created before files that reside in them.
    ksort($mappings);

    // We no longer need to explicitly create directories that are not
    // set in the ZIP.  The self::createFromFile function automatically
    // creates preceding directories for file entries.

    // Adjust the paths relative to this fragment so we can look existing
    // fragments up in the DB.
    $base_path = $this->getPath();
    $paths = array();
    foreach ($mappings as $p => $data) {
      $paths[] = $base_path.'/'.$p;
    }

    // FIXME: What happens when a child exists, but the current user
    // can't see it.  We're going to place a new child under a path
    // that the current user can't access.
    $children = id(new PhragmentFragmentQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->needLatestVersion(true)
      ->withLeadingPath($this->getPath().'/')
      ->execute();
    $children = mpull($children, null, 'getPath');

    // Iterate over the existing fragments.
    foreach ($children as $full_path => $child) {
      $path = substr($full_path, strlen($base_path) + 1);
      if (array_key_exists($path, $mappings)) {
        if ($child->isDirectory() && $mappings[$path] === null) {
          // Don't create a version entry for a directory
          // (unless it's been converted into a file).
          continue;
        }

        // The file is being updated.
        $file = PhabricatorFile::newFromFileData(
          $mappings[$path],
          array('name' => basename($path)));
        $child->updateFromFile($viewer, $file);
      } else {
        // The file is being deleted.
        $child->deleteFile($viewer);
      }
    }

    // Iterate over the mappings to find new files.
    foreach ($mappings as $path => $data) {
      if (!array_key_exists($base_path.'/'.$path, $children)) {
        // The file is being created. If the data is null,
        // then this is explicitly a directory being created.
        $file = null;
        if ($mappings[$path] !== null) {
          $file = PhabricatorFile::newFromFileData(
            $mappings[$path],
            array('name' => basename($path)));
        }
        self::createFromFile(
          $viewer,
          $file,
          $base_path.'/'.$path,
          $this->getViewPolicy(),
          $this->getEditPolicy());
      }
    }
  }

  /**
   * Delete the contents of the specified fragment.
   */
  public function deleteFile(PhabricatorUser $viewer) {
    $existing = id(new PhragmentFragmentVersionQuery())
      ->setViewer($viewer)
      ->withFragmentPHIDs(array($this->getPHID()))
      ->execute();
    $sequence = count($existing);

    $this->openTransaction();
      $version = id(new PhragmentFragmentVersion());
      $version->setSequence($sequence);
      $version->setFragmentPHID($this->getPHID());
      $version->setFilePHID(null);
      $version->save();

      $this->setLatestVersionPHID($version->getPHID());
      $this->save();
    $this->saveTransaction();
  }


/* -(  Utility  )  ---------------------------------------------------------- */


  public function getFragmentMappings(
    PhabricatorUser $viewer,
    $base_path) {

    $children = id(new PhragmentFragmentQuery())
      ->setViewer($viewer)
      ->needLatestVersion(true)
      ->withLeadingPath($this->getPath().'/')
      ->withDepths(array($this->getDepth() + 1))
      ->execute();

    if (count($children) === 0) {
      $path = substr($this->getPath(), strlen($base_path) + 1);
      return array($path => $this);
    } else {
      $mappings = array();
      foreach ($children as $child) {
        $child_mappings = $child->getFragmentMappings(
          $viewer,
          $base_path);
        foreach ($child_mappings as $key => $value) {
          $mappings[$key] = $value;
        }
      }
      return $mappings;
    }
  }


/* -(  Policy Interface  )--------------------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getEditPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return false;
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}
