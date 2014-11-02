<?php

final class PhabricatorStandardCustomFieldDecimal
  extends PhabricatorStandardCustomField {

  public function getFieldType() {
    return 'decimal';
  }

  public function getValueForStorage() {
    $value = $this->getFieldValue();
    if (strlen($value)) {
      return $value;
    } else {
      return null;
    }
  }

  public function setValueFromStorage($value) {
    if (strlen($value)) {
      $value = (double)$value;
    } else {
      $value = null;
    }
    return $this->setFieldValue($value);
  }


  public function validateApplicationTransactions(
    PhabricatorApplicationTransactionEditor $editor,
    $type,
    array $xactions) {

    $errors = parent::validateApplicationTransactions(
      $editor,
      $type,
      $xactions);

    foreach ($xactions as $xaction) {
      $value = $xaction->getNewValue();
      if (strlen($value)) {
        if (!preg_match('/^\d*\.?\d*$/', $value)) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht('%s must be an decimal.', $this->getFieldName()),
            $xaction);
          $this->setFieldError(pht('Invalid'));
        }
      }
    }

    return $errors;
  }

  public function getApplicationTransactionHasEffect(
    PhabricatorApplicationTransaction $xaction) {

    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();
    if (!strlen($old) && strlen($new)) {
      return true;
    } else if (strlen($old) && !strlen($new)) {
      return true;
    } else {
      return ((double)$old !== (double)$new);
    }
  }


}
