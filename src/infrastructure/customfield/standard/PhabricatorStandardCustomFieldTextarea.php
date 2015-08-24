<?php

final class PhabricatorStandardCustomFieldTextarea
  extends PhabricatorStandardCustomField {

  public function getFieldType() {
    return 'textarea';
  }

  public function renderEditControl(array $handles) {
    $monospace = $this->getFieldConfigValue('monospace', false);
    $custom_class =
      $monospace ? 'aphront-form-control-textarea-monospace' : null;

    return id(new AphrontFormTextAreaControl())
      ->setLabel($this->getFieldName())
      ->setName($this->getFieldKey())
      ->setCaption($this->getCaption())
      ->setValue($this->getFieldValue())
      ->setCustomClass($custom_class);
  }

  public function getStyleForPropertyView() {
    return 'block';
  }

  public function getApplicationTransactionRemarkupBlocks(
    PhabricatorApplicationTransaction $xaction) {
    return array(
      $xaction->getNewValue(),
    );
  }

  public function renderPropertyViewValue(array $handles) {
    $value = $this->getFieldValue();

    if (!strlen($value)) {
      return null;
    }

    return phutil_tag(
      'pre',
      array(),
      $value);
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();

    // TODO: Expose fancy transactions.

    return pht(
      '%s edited %s.',
      $xaction->renderHandleLink($author_phid),
      $this->getFieldName());
  }

  public function shouldAppearInHerald() {
    return true;
  }

  public function getHeraldFieldConditions() {
    return array(
      HeraldAdapter::CONDITION_CONTAINS,
      HeraldAdapter::CONDITION_NOT_CONTAINS,
      HeraldAdapter::CONDITION_IS,
      HeraldAdapter::CONDITION_IS_NOT,
      HeraldAdapter::CONDITION_REGEXP,
    );
  }


}
