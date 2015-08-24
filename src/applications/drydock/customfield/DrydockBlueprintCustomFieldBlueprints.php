<?php

final class DrydockBlueprintCustomFieldBlueprints
  extends PhabricatorStandardCustomFieldPHIDs {

  public function getFieldType() {
    return 'blueprints';
  }

  public function renderEditControl(array $handles) {
    $value = $this->getFieldValue();

    $control = id(new AphrontFormTokenizerControl())
      ->setUser($this->getViewer())
      ->setLabel($this->getFieldName())
      ->setName($this->getFieldKey())
      ->setDatasource(id(new DrydockBlueprintDatasource())
        ->setParameters(array(
          'type' => $this->getFieldConfigValue('blueprint-type'),
        )))
      ->setCaption($this->getCaption())
      ->setValue(nonempty($value, array()));

    $limit = $this->getFieldConfigValue('limit');
    if ($limit) {
      $control->setLimit($limit);
    }

    return $control;
  }

  public function appendToApplicationSearchForm(
    PhabricatorApplicationSearchEngine $engine,
    AphrontFormView $form,
    $value) {

    $control = id(new AphrontFormTokenizerControl())
      ->setLabel($this->getFieldName())
      ->setName($this->getFieldKey())
      ->setDatasource(id(new DrydockBlueprintDatasource())
        ->setParameters(array(
          'type' => $this->getFieldConfigValue('blueprint-type'),
        )))
      ->setValue(nonempty($value, array()));

    $form->appendControl($control);
  }

}
