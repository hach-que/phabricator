<?php

final class DrydockLogListView extends AphrontView {

  private $logs;

  public function setLogs(array $logs) {
    assert_instances_of($logs, 'DrydockLog');
    $this->logs = $logs;
    return $this;
  }

  public function render() {
    $logs = $this->logs;
    $viewer = $this->getUser();

    $view = new PHUIObjectItemListView();

    $rows = array();
    foreach ($logs as $log) {
      $blueprint_id = null;
      if ($log->getBlueprintPHID() !== null) {
        $blueprint_id = $log->getBlueprint()->getID();
      }

      $blueprint_uri = '/drydock/blueprint/'.$blueprint_id.'/';

      if ($log->getResource()) {
        $resource_uri = '/drydock/resource/'.$log->getResourceID().'/';
        $resource_tag = phutil_tag(
          'a',
          array(
            'href' => $resource_uri,
          ),
          $log->getResourceID());
      } else {
        $resource_tag = $log->getResourceID();
      }

      if ($log->getLease()) {
        $lease_uri = '/drydock/lease/'.$log->getLeaseID().'/';
        $lease_tag = phutil_tag(
          'a',
          array(
            'href' => $lease_uri,
          ),
          $log->getLeaseID());
      } else {
        $lease_tag = $log->getLeaseID();
      }

      $rows[] = array(
        phutil_tag(
          'a',
          array(
            'href' => $blueprint_uri,
          ),
          $blueprint_id),
        $resource_tag,
        $lease_tag,
        $log->getMessage(),
        phabricator_datetime($log->getEpoch(), $viewer),
      );
    }

    $table = new AphrontTableView($rows);
    $table->setDeviceReadyTable(true);
    $table->setHeaders(
      array(
        'Blueprint',
        'Resource',
        'Lease',
        'Message',
        'Date',
      ));
    $table->setShortHeaders(
      array(
        'B',
        'R',
        'L',
        'Message',
        '',
      ));
    $table->setColumnClasses(
      array(
        '',
        '',
        '',
        'wide',
        '',
      ));

    return $table;
  }

}
