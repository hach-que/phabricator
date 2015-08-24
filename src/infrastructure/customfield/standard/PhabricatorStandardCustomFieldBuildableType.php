<?php

final class PhabricatorStandardCustomFieldBuildableType
  extends PhabricatorStandardCustomField {

  public function getFieldType() {
    return 'buildabletype';
  }

  public function buildFieldIndexes() {
    $indexes = array();

    $value = $this->getFieldValue();
    if (strlen($value)) {
      $indexes[] = $this->newStringIndex($value);
    }

    return $indexes;
  }

  public function readApplicationSearchValueFromRequest(
    PhabricatorApplicationSearchEngine $engine,
    AphrontRequest $request) {
    return $request->getArr($this->getFieldKey());
  }

  public function applyApplicationSearchConstraintToQuery(
    PhabricatorApplicationSearchEngine $engine,
    PhabricatorCursorPagedPolicyAwareQuery $query,
    $value) {
    if ($value) {
      $query->withApplicationSearchContainsConstraint(
        $this->newStringIndex(null),
        $value);
    }
  }

  public function appendToApplicationSearchForm(
    PhabricatorApplicationSearchEngine $engine,
    AphrontFormView $form,
    $value,
    array $handles) {

    if (!is_array($value)) {
      $value = array();
    }
    $value = array_fuse($value);

    $control = id(new AphrontFormCheckboxControl())
      ->setLabel($this->getFieldName());

    foreach ($this->getOptions() as $name => $option) {
      $control->addCheckbox(
        $this->getFieldKey().'[]',
        $name,
        $option,
        isset($value[$name]));
    }

    $form->appendChild($control);
  }

  private function getOptions() {
    return array(
      'PhabricatorRepositoryCommit' => pht('Commits'),
      'DifferentialRevision' => pht('Differential Revisions'));
  }

  public function renderEditControl(array $handles) {
    return id(new AphrontFormCheckboxControl())
      ->setLabel($this->getFieldName())
      ->setCaption($this->getCaption())
      ->setName($this->getFieldKey())
      ->addCheckbox(
        $this->getFieldKey().'commit',
        'PhabricatorRepositoryCommit',
        'Commits',
        in_array('commit', $this->getFieldValue()))
      ->addCheckbox(
        $this->getFieldKey().'revision',
        'DifferentialRevision',
        'Differential Revisions',
        in_array('revision', $this->getFieldValue()));
  }

  public function renderPropertyViewValue(array $handles) {
    if (!strlen($this->getFieldValue())) {
      return null;
    }
    return idx($this->getOptions(), $this->getFieldValue());
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $value = array();
    if (strlen($request->getStr($this->getFieldKey().'commit'))) {
      $value[] = 'commit';
    }
    if (strlen($request->getStr($this->getFieldKey().'revision'))) {
      $value[] = 'revision';
    }
    $this->setFieldValue($value);
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    // TODO: This doesn't work right because the transaction system isn't
    // storing the arrays correctly.  Both $old and $new are always null.

    if ($old === null) {
      $old = array();
    }

    if ($new === null) {
      $new = array();
    }

    $commit_old = array_key_exists('commit', $old);
    $commit_new = array_key_exists('commit', $new);
    $revision_old = array_key_exists('revision', $old);
    $revision_new = array_key_exists('revision', $new);

    $commit_change = '';
    if ($commit_old && !$commit_new) {
      $commit_change = pht(
        'disabled %s for commits',
        $this->getFieldName());
    } else if (!$commit_old && $commit_new) {
      $commit_change = pht(
        'enabled %s for commits',
        $this->getFieldName());
    }

    $revision_change = '';
    if ($revision_old && !$revision_new) {
      $revision_change = pht(
        'disabled %s for revisions',
        $this->getFieldName());
    } else if (!$revision_old && $revision_new) {
      $revision_change = pht(
        'enabled %s for revisions',
        $this->getFieldName());
    }

    $final_change = '';
    if (!empty($commit_change) && !empty($revision_change)) {
      $final_change = $commit_change.', '.$revision_change;
    } else {
      $final_change = $commit_change.$revision_change;
    }

    if (!empty($final_change)) {
      return pht(
        '%s %s.',
        $xaction->renderHandleLink($author_phid),
        $final_change);
    }

    return pht(
      '%s set %s to the defaults',
      $xaction->renderHandleLink($author_phid),
      $this->getFieldName());
  }

}
