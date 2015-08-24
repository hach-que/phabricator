<?php

final class ProjectBoardTaskCard extends Phobject {

  private $viewer;
  private $task;
  private $owner;
  private $canEdit;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }
  public function getViewer() {
    return $this->viewer;
  }

  public function setTask(ManiphestTask $task) {
    $this->task = $task;
    return $this;
  }
  public function getTask() {
    return $this->task;
  }

  public function setOwner(PhabricatorObjectHandle $owner = null) {
    $this->owner = $owner;
    return $this;
  }
  public function getOwner() {
    return $this->owner;
  }

  public function setCanEdit($can_edit) {
    $this->canEdit = $can_edit;
    return $this;
  }

  public function getCanEdit() {
    return $this->canEdit;
  }
  
  public function getItem() {
    $task = $this->getTask();
    $owner = $this->getOwner();
    $can_edit = $this->getCanEdit();

    $color_map = ManiphestTaskPriority::getColorMap();
    $bar_color = idx($color_map, $task->getPriority(), 'grey');

    $card = id(new PHUIObjectItemView())
      ->setObject($task)
      ->setUser($this->getViewer())
      ->setObjectName('T'.$task->getID())
      ->setHeader($task->getTitle())
      ->setGrippable($can_edit)
      ->setHref('/T'.$task->getID())
      ->addSigil('project-card')
      ->setDisabled($task->isClosed())
      ->setMetadata(
        array(
          'objectPHID' => $task->getPHID(),
        ))
      ->addAction(
        id(new PHUIListItemView())
        ->setName(pht('Edit'))
        ->setIcon('fa-pencil')
        ->addSigil('edit-project-card')
        ->setHref('/maniphest/task/edit/'.$task->getID().'/'))
      ->setBarColor($bar_color);

    if ($owner) {
      $card->addAttribute($owner->renderLink());
    }
    
    // HACK: Support showing points in Games Project
    $field_list = PhabricatorCustomField::getObjectFields(
      $task,
      PhabricatorCustomField::ROLE_VIEW);
    $field_list->readFieldsFromStorage($task);
    $field_list = mpull($field_list->getFields(), null, 'getFieldKey');
    $points = idx($field_list, 'std:maniphest:games-project:points');

    $gp_points_name = null;
    if ($points !== null) {
      $gp_points_name = $points->renderPropertyViewValue(array());
      if ($gp_points_name != '' && $gp_points_name !== 'Not Applicable') {
        if (strlen($gp_points_name) < 3) {
          if ($gp_points_name == '1') {
            $gp_points_name = $gp_points_name.' Point';
          } else {
            $gp_points_name = $gp_points_name.' Points';
          }
        }
        if ($gp_points_name == '') {
          $gp_points_name = null;
        }
      }
    }
    
    if ($gp_points_name !== null) {
      $card->addAttribute($gp_points_name);
    }
    
    return $card;
  }

}
