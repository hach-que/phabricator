<?php

final class DrydockMinMaxTestBlueprintImplementation
  extends DrydockMinMaxExpiryBlueprintImplementation {

  public function isEnabled() {
    return true;
  }

  public function isTest() {
    return true;
  }

  public function getBlueprintName() {
    return pht('Min / Max Count Test');
  }

  public function getDescription() {
    return pht('Used to test min / max counts.');
  }

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $path = '/srv/alloctest/'.$resource->getID();

    $resource
      ->setName($path)
      ->setStatus(DrydockResourceStatus::STATUS_PENDING)
      ->setAttributes(array(
        'path' => $path))
      ->save();

    mkdir($path);

    sleep($this->getDetail('seconds-to-open'));

    $resource->setStatus(DrydockResourceStatus::STATUS_OPEN);
    $resource->save();
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    return true;
  }

  protected function executeAcquireLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    while ($resource->getStatus() == DrydockResourceStatus::STATUS_PENDING) {
      // This resource is still being set up by another allocator, wait until
      // it is set to open.
      sleep(5);
      $resource->reload();
    }

    $path = $resource->getAttribute('path').'/'.$lease->getID();

    mkdir($path);

    sleep($this->getDetail('seconds-to-lease'));

    $lease->setAttribute('path', $path);
  }

  public function getType() {
    return 'storage';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    throw new Exception("No interface of type '{$type}'.");
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    execx('rm -R %s', $lease->getAttribute('path'));
  }

  protected function shouldCloseUnleasedResource(
    DrydockAllocationContext $context,
    DrydockResource $resource) {

    return $context->getBlueprintOpenResourceCount() >
      $this->getDetail('min-count');
  }

  protected function executeCloseResource(DrydockResource $resource) {
    execx('rm -R %s', $resource->getAttribute('path'));
  }

  public function getFieldSpecifications() {
    return array(
      'test-configuration' => array(
        'name' => pht('Test Configuration'),
        'type' => 'header',
      ),
      'seconds-to-open' => array(
        'name' => pht('Seconds to Open'),
        'type' => 'int',
        'caption' => pht(
          'The time to sleep during creation of a resource.'),
        'required' => true,
      ),
      'seconds-to-lease' => array(
        'name' => pht('Seconds to Lease'),
        'type' => 'int',
        'caption' => pht(
          'The time to sleep while acquiring a lease.'),
        'required' => true,
      ),
    ) + parent::getFieldSpecifications();
  }
}
