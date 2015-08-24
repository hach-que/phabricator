<?php

final class DrydockResourceListView extends AphrontView {

  private $resources;

  public function setResources(array $resources) {
    assert_instances_of($resources, 'DrydockResource');
    $this->resources = $resources;
    return $this;
  }

  public function render() {
    $resources = $this->resources;
    $viewer = $this->getUser();

    $blueprint_handles = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(mpull($resources, 'getBlueprintPHID'))
      ->execute();
    $blueprint_handles = mpull($blueprint_handles, null, 'getPHID');

    $view = new PHUIObjectItemListView();
    foreach ($resources as $resource) {
      $name = pht('Resource %d', $resource->getID()).': '.$resource->getName();

      $item = id(new PHUIObjectItemView())
        ->setHref('/drydock/resource/'.$resource->getID().'/')
        ->setHeader($name);

      $status = DrydockResourceStatus::getNameForStatus($resource->getStatus());
      $item->addAttribute($status);

      $blueprint_handle = idx(
        $blueprint_handles,
        $resource->getBlueprintPHID());
      if ($blueprint_handle !== null) {
        $item->addAttribute($blueprint_handle->renderLink());
      }

      switch ($resource->getStatus()) {
        case DrydockResourceStatus::STATUS_ALLOCATING:
        case DrydockResourceStatus::STATUS_PENDING:
          $item->setStatusIcon('fa-dot-circle-o yellow');
          break;
        case DrydockResourceStatus::STATUS_OPEN:
          $item->setStatusIcon('fa-dot-circle-o green');
          break;
        case DrydockResourceStatus::STATUS_DESTROYED:
          $item->setStatusIcon('fa-times-circle-o black');
          break;
        default:
          $item->setStatusIcon('fa-dot-circle-o red');
          break;
      }

      $view->addItem($item);
    }

    return $view;
  }

}
