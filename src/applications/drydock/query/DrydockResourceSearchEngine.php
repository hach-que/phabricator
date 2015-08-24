<?php

final class DrydockResourceSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function getResultTypeDescription() {
    return pht('Drydock Resources');
  }

  public function getApplicationClassName() {
    return 'PhabricatorDrydockApplication';
  }

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter(
      'statuses',
      $this->readListFromRequest($request, 'statuses'));
    $saved->setParameter(
      'types',
      $this->readListFromRequest($request, 'types'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new DrydockResourceQuery());

    $statuses = $saved->getParameter('statuses', array());
    if ($statuses) {
      $query->withStatuses($statuses);
    }

    $types = $saved->getParameter('types', array());
    if ($types) {
      $query->withTypes($types);
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $statuses = $saved->getParameter('statuses', array());

    $status_control = id(new AphrontFormCheckboxControl())
      ->setLabel(pht('Status'));
    foreach (DrydockResourceStatus::getAllStatuses() as $status) {
      $status_control->addCheckbox(
        'statuses[]',
        $status,
        DrydockResourceStatus::getNameForStatus($status),
        in_array($status, $statuses));
    }

    $form
      ->appendChild($status_control);

    $types = $saved->getParameter('types', array());

    $implementations =
      DrydockBlueprintImplementation::getAllBlueprintImplementations();
    $available_types = mpull($implementations, 'getType');
    $available_types = array_unique($available_types);

    $type_control = id(new AphrontFormCheckboxControl())
      ->setLabel(pht('Resource Type'));
    foreach ($available_types as $type) {
      $type_control->addCheckbox(
        'types[]',
        $type,
        $type,
        in_array($type, $types));
    }

    $form
      ->appendChild($type_control);
  }

  protected function getURI($path) {
    return '/drydock/resource/'.$path;
  }

  protected function getBuiltinQueryNames() {
    return array(
      'active' => pht('Active Resources'),
      'all' => pht('All Resources'),
    );
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'active':
        return $query->setParameter(
          'statuses',
          array(
            DrydockResourceStatus::STATUS_PENDING,
            DrydockResourceStatus::STATUS_OPEN,
          ))->setParameter(
          'types',
          array(
            'host',
            'working-copy',
            // Exclude working copy cache resources by default
            // as they are not very useful to look at (and they
            // don't yet get cleaned up with the host resource
            // disappears).
          ));
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  protected function renderResultList(
    array $resources,
    PhabricatorSavedQuery $query,
    array $handles) {

    $list = id(new DrydockResourceListView())
      ->setUser($this->requireViewer())
      ->setResources($resources);

    $result = new PhabricatorApplicationSearchResultView();
    $result->setTable($list);

    return $result;
  }

}
