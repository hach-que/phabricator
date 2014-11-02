<?php

final class DrydockAmazonEC2HostBlueprintImplementation
  extends DrydockMinMaxExpiryBlueprintImplementation {

  public function isEnabled() {
    // This blueprint is only available if the Amazon EC2 keys are configured.
    return
      PhabricatorEnv::getEnvConfig('amazon-ec2.access-key') &&
      PhabricatorEnv::getEnvConfig('amazon-ec2.secret-key');
  }

  public function getBlueprintName() {
    return pht('Amazon EC2 Remote Hosts');
  }

  public function getDescription() {
    return pht(
      'Allows Drydock to allocate and execute commands on '.
      'Amazon EC2 remote hosts.');
  }

  private function getAWSEC2Future() {
    return id(new PhutilAWSEC2Future())
      ->setAWSKeys(
        PhabricatorEnv::getEnvConfig('amazon-ec2.access-key'),
        PhabricatorEnv::getEnvConfig('amazon-ec2.secret-key'))
      ->setAWSRegion($this->getDetail('region'));
  }

  private function getAWSKeyPairName() {
    return 'phabricator-'.$this->getDetail('keypair');
  }

  public function canAllocateResourceForLease(DrydockLease $lease) {
    return
      $lease->getAttribute('platform') === $this->getDetail('platform');
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {
    return
      $lease->getAttribute('platform') === $resource->getAttribute('platform');
  }

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    // We need to retrieve this as we need to use it for both importing the
    // key and looking up the ID for the resource attributes.
    $credential = id(new PassphraseCredentialQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($this->getDetail('keypair')))
      ->executeOne();

    try {
      $existing_keys = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'DescribeKeyPairs',
          array(
            'KeyName.0' => $this->getAWSKeyPairName()))
        ->resolve();
    } catch (PhutilAWSException $ex) {
      // The key pair does not exist, so we need to import it.

      $type = PassphraseCredentialType::getTypeByConstant(
        $credential->getCredentialType());
      if (!$type) {
        throw new Exception(pht('Credential has invalid type "%s"!', $type));
      }

      if (!$type->hasPublicKey()) {
        throw new Exception(pht('Credential has no public key!'));
      }

      $public_key = $type->getPublicKey(
        PhabricatorUser::getOmnipotentUser(),
        $credential);

      $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'ImportKeyPair',
          array(
            'KeyName' => $this->getAWSKeyPairName(),
            'PublicKeyMaterial' => base64_encode($public_key)))
        ->resolve();
    }

    $settings = array(
      'ImageId' => $this->getDetail('ami'),
      'KeyName' => $this->getAWSKeyPairName(),
      'InstanceType' => $this->getDetail('size'),
      'SubnetId' => $this->getDetail('subnet-id')
    );

    $i = 0;
    $security_groups = explode(',', $this->getDetail('security-group-ids'));
    foreach ($security_groups as $security_group) {
      $settings['SecurityGroupId.'.$i] = $security_group;
      $i++;
    }

    if (!$this->getDetail('skip-ssh-setup-windows')) {
      if ($this->getDetail('platform') === 'windows') {
        $settings['UserData'] = id(new WindowsZeroConf())
          ->getEncodedUserData($credential);
      }
    }

    if ($this->getDetail('spot-enabled') &&
      $this->getDetail('spot-price') !== null) {

      $spot_settings = array(
        'SpotPrice' => $this->getDetail('spot-price'),
        'InstanceCount' => 1,
        'Type' => 'one-time');

      foreach ($settings as $key => $value) {
        $spot_settings['LaunchSpecification.'.$key] = $value;
      }

      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'RequestSpotInstances',
          $spot_settings)
        ->resolve();

      $spot_request = $result->spotInstanceRequestSet->item[0];
      $spot_request_id = (string)$spot_request->spotInstanceRequestId;

      // Wait until the spot instance request is fulfilled.
      while (true) {
        try {
          $result = $this->getAWSEC2Future()
            ->setRawAWSQuery(
              'DescribeSpotInstanceRequests',
              array(
                'SpotInstanceRequestId.0' => $spot_request_id))
            ->resolve();
        } catch (PhutilAWSException $ex) {
          // AWS does not provide immediate consistency, so this may throw
          // "spot request does not exist" right after requesting spot
          // instances.
          continue;
        }

        $spot_request = $result->spotInstanceRequestSet->item[0];

        $spot_state = (string)$spot_request->state;

        if ($spot_state == 'open') {
          // We are waiting for the request to be fulfilled.
          sleep(5);
          continue;
        } else if ($spot_state == 'active') {
          // The request has been fulfilled and we now have an instance ID.
          $instance_id = (string)$spot_request->instanceId;
          break;
        } else {
          // The spot request is closed, cancelled or failed.
          throw new Exception(
            'Requested a spot instance, but the request is in state '.
            '"'.$spot_state.'".  This may occur when the current bid '.
            'price exceeds your maximum bid price ('.
            $this->getDetail('spot-price').
            ').');
        }
      }
    } else {
      $settings['MinCount'] = 1;
      $settings['MaxCount'] = 1;

      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'RunInstances',
          $settings)
        ->resolve();

      $instance = $result->instancesSet->item[0];
      $instance_id = (string)$instance->instanceId;
    }

    // Allocate the resource and place it into Pending status while
    // we wait for the instance to start.
    $blueprint = $this->getInstance();
    $resource
      ->setName($instance_id)
      ->setStatus(DrydockResourceStatus::STATUS_PENDING)
      ->setAttributes(array(
        'instance-id' => $instance_id,
        'platform' => $this->getDetail('platform'),
        'path' => $this->getDetail('storage-path'),
        'credential' => $credential->getID(),
        'aws-status' => 'Instance Requested'))
      ->save();

    // Wait until the instance has started.
    while (true) {
      try {
        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'DescribeInstances',
            array(
              'InstanceId.0' => $instance_id))
          ->resolve();

        $reservation = $result->reservationSet->item[0];
        $instance = $reservation->instancesSet->item[0];
        $instance_state = (string)$instance->instanceState->name;

        if ($instance_state === 'pending') {
          sleep(5);
          continue;
        } else if ($instance_state === 'running') {
          break;
        } else {
          // Instance is shutting down or is otherwise terminated.
          throw new Exception(
            'Allocated instance, but ended up in unexpected state \''.
            $instance_state.'\'!');
        }
      } catch (PhutilAWSException $ex) {
        // TODO: This can happen because the instance doesn't exist yet, but
        // we should check specifically for that error.
        sleep(5);
        continue;
      }
    }

    $resource->setAttribute('aws-status', 'Started in Amazon');
    $resource->save();

    // Calculate the IP address of the instance.
    $address = '';
    if ($this->getDetail('allocate-elastic-ip')) {
      $resource->setAttribute('eip-status', 'Allocating Elastic IP');
      $resource->setAttribute('eip-allocated', true);
      $resource->save();

      try {
        // Allocate, assign and use a public IP address.
        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'AllocateAddress',
            array(
              'Domain' => 'vpc'))
          ->resolve();

        $public_ip = (string)$result->publicIp;
        $allocation_id = (string)$result->allocationId;

        $resource->setAttribute('eip-allocation-id', $allocation_id);
        $resource->setAttribute('eip-status', 'Associating Elastic IP');
        $resource->save();

        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'AssociateAddress',
            array(
              'InstanceId' => $instance_id,
              'AllocationId' => $allocation_id))
          ->resolve();

        $association_id = (string)$result->associationId;

        $resource->setAttribute('eip-association-id', $association_id);
        $resource->setAttribute('eip-status', 'Associated');
        $resource->save();

        if ($this->getDetail('always-use-private-ip')) {
          // Use the private IP address.
          $result = $this->getAWSEC2Future()
            ->setRawAWSQuery(
              'DescribeInstances',
              array(
                'InstanceId.0' => $instance_id))
            ->resolve();

          $reservation = $result->reservationSet->item[0];
          $instance = $reservation->instancesSet->item[0];

          $address = (string)$instance->privateIpAddress;
        } else {
          // Use the public IP address.
          $address = $public_ip;
        }
      } catch (PhutilAWSException $ex) {
        // We can't allocate an Elastic IP (probably because we've reached
        // the maximum allowed on the account).  Terminate the EC2 instance
        // we just started and fail the resource allocation.
        $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'TerminateInstances',
            array(
              'InstanceId.0' => $instance_id))
          ->resolve();

        $resource->setAttribute(
          'aws-status',
          'Terminated');
        $resource->save();

        throw new Exception(
          'Unable to allocate an elastic IP for the new EC2 instance. '.
          'Check your AWS account limits and ensure your limit on '.
          'elastic IP addresses is high enough to complete the '.
          'resource allocation');
      }
    } else {
      $resource->setAttribute('eip-allocated', false);

      // Use the private IP address.
      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'DescribeInstances',
          array(
            'InstanceId.0' => $instance_id))
        ->resolve();

      $reservation = $result->reservationSet->item[0];
      $instance = $reservation->instancesSet->item[0];

      $address = (string)$instance->privateIpAddress;
    }

    // Update address and port attributes.
    $resource->setAttribute('host', $address);
    $resource->setAttribute('port', 22);
    $resource->save();

    // Wait until we get a successful SSH connection.
    $ssh = id(new DrydockSSHCommandInterface())
      ->setConfiguration(array(
        'host' => $resource->getAttribute('host'),
        'port' => $resource->getAttribute('port'),
        'credential' => $resource->getAttribute('credential'),
        'platform' => $resource->getAttribute('platform')));
    $ssh->setConnectTimeout(60);

    $resource->setAttribute(
      'aws-status',
      'Waiting for successful SSH connection');
    $resource->save();

    while (true) {
      try {
        $ssh->getExecFuture('echo "test"')->resolvex();
        break;
      } catch (Exception $ex) {

        // Make sure the instance hasn't been terminated or shutdown while
        // we've been trying to connect.
        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'DescribeInstances',
            array(
              'InstanceId.0' => $instance_id))
          ->resolve();

        $reservation = $result->reservationSet->item[0];
        $instance = $reservation->instancesSet->item[0];
        $instance_state = (string)$instance->instanceState->name;

        if ($instance_state === 'shutting-down' ||
          $instance_state === 'terminated') {

          // Deallocate and release the public IP address if we allocated one.
          if ($resource->getAttribute('eip-allocated')) {
            try {
              $this->getAWSEC2Future()
                ->setRawAWSQuery(
                  'DisassociateAddress',
                  array(
                    'AssociationId' =>
                      $resource->getAttribute('eip-association-id')))
                ->resolve();
            } catch (PhutilAWSException $ex) {
            }

            try {
              $this->getAWSEC2Future()
                ->setRawAWSQuery(
                  'ReleaseAddress',
                  array(
                    'AllocationId' =>
                      $resource->getAttribute('eip-allocation-id')))
                ->resolve();
            } catch (PhutilAWSException $ex) {
            }

            $resource->setAttribute(
              'eip-status',
              'Released');
            $resource->save();
          }

          $resource->setAttribute(
            'aws-status',
            'Terminated');
          $resource->save();

          throw new Exception(
            'Allocated instance, but ended up in unexpected state \''.
            $instance_state.'\'!');
        }

        continue;
      }
    }

    // Update the resource into open status.
    $resource->setStatus(DrydockResourceStatus::STATUS_OPEN);
    $resource->setAttribute(
      'aws-status',
      'Ready for Use');
    $resource->save();
    return $resource;
  }

  protected function executeCloseResource(DrydockResource $resource) {

    // Deallocate and release the public IP address if we allocated one.
    if ($resource->getAttribute('eip-allocated')) {
      $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'DisassociateAddress',
          array(
            'AssociationId' => $resource->getAttribute('eip-association-id')))
        ->resolve();

      $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'ReleaseAddress',
          array(
            'AllocationId' => $resource->getAttribute('eip-allocation-id')))
        ->resolve();
    }

    // Terminate the EC2 instance.
    $this->getAWSEC2Future()
      ->setRawAWSQuery(
        'TerminateInstances',
        array(
          'InstanceId.0' => $resource->getAttribute('instance-id')))
      ->resolve();

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

    $platform = $resource->getAttribute('platform');
    $path = $resource->getAttribute('path');

    $lease_id = $lease->getID();

    // Can't use DIRECTORY_SEPERATOR here because that is relevant to
    // the platform we're currently running on, not the platform we are
    // remoting to.
    $separator = '/';
    if ($platform === 'windows') {
      $separator = '\\';
    }

    // Clean up the directory path a little.
    $base_path = rtrim($path, '/');
    $base_path = rtrim($base_path, '\\');
    $full_path = $base_path.$separator.$lease_id;

    $cmd = $lease->getInterface('command');

    $cmd->execx('mkdir %s', $full_path);

    $lease->setAttribute('path', $full_path);
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $cmd = $lease->getInterface('command');
    $path = $lease->getAttribute('path');

    try {
      if ($resource->getAttribute('platform') !== 'windows') {
        $cmd->execx('rm -rf %s', $path);
      } else {
        $cmd->execx('rm -Recurse -Force %s', $path);
      }
    } catch (Exception $ex) {
      // We try to clean up, but sometimes files are locked or still in
      // use (this is far more common on Windows).  There's nothing we can
      // do about this, so we ignore it.
    }
  }

  public function getType() {
    return 'host';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {

    switch ($type) {
      case 'command':
        return id(new DrydockSSHCommandInterface())
          ->setConfiguration(array(
            'host' => $resource->getAttribute('host'),
            'port' => $resource->getAttribute('port'),
            'credential' => $resource->getAttribute('credential'),
            'platform' => $resource->getAttribute('platform')))
          ->setWorkingDirectory($lease->getAttribute('path'));
      case 'filesystem':
        return id(new DrydockSFTPFilesystemInterface())
          ->setConfiguration(array(
            'host' => $resource->getAttribute('host'),
            'port' => $resource->getAttribute('port'),
            'credential' => $resource->getAttribute('credential')));
    }

    throw new Exception("No interface of type '{$type}'.");
  }

  public function getFieldSpecifications() {
    return array(
      'amazon' => array(
        'name' => pht('Amazon Configuration'),
        'type' => 'header'
      ),
      'region' => array(
        'name' => pht('Region'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s', 'us-west-1')
      ),
      'ami' => array(
        'name' => pht('AMI (Amazon Image)'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s', 'ami-7fd3ae4f')
      ),
      'keypair' => array(
        'name' => pht('Key Pair'),
        'type' => 'credential',
        'required' => true,
        'credential.provides'
          => PassphraseCredentialTypeSSHPrivateKey::PROVIDES_TYPE,
        'caption' => pht(
          'Only the public key component is transmitted to Amazon.')
      ),
      'size' => array(
        'name' => pht('Instance Size'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s', 't2.micro')
      ),
      'platform' => array(
        'name' => pht('Platform Name'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s or %s', 'windows', 'linux')
      ),
      'subnet-id' => array(
        'name' => pht('VPC Subnet'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s', 'subnet-2a67439b')
      ),
      'security-group-ids' => array(
        'name' => pht('VPC Security Groups'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s', 'sg-3fa3491f,sg-bg18dea2')
      ),
      'storage-path' => array(
        'name' => pht('Storage Path'),
        'type' => 'text',
        'required' => true,
        'caption' => pht(
          'A writable location on the instance where new directories / files '.
          'can be created and data can be stored in.')
      ),
      'allocate-elastic-ip' => array(
        'name' => pht('Allocate Public IP'),
        'type' => 'bool',
        'caption' => pht(
          'If Phabricator is running in the same subnet as the allocated '.
          'machines, then you do not need to turn this option on.  If '.
          'phabricator is hosted externally to Amazon EC2, then enable this '.
          'option to automatically allocate and assign elastic IP addresses '.
          'to instances so that Phabricator can SSH to them from the '.
          'internet (instances are still only accessible by SSH key pairs)')
      ),
      'always-use-private-ip' => array(
        'name' => pht('Always Use Private IP'),
        'type' => 'bool',
        'caption' => pht(
          'When instances are placed in a VPC, and are not placed behind '.
          'a NAT, an elastic IP may be required in order to establish '.
          'outbound internet connections.  Enable this option if elastic IP '.
          'allocation is only enabled for this purpose, and you want to '.
          'connect to the machine on it\'s private IP address (because of '.
          'firewall rules).'),
      ),
      'skip-ssh-setup-windows' => array(
        'name' => pht('Skip SSH setup on Windows'),
        'type' => 'bool',
        'caption' => pht(
          'If SSH is already configured on a Windows AMI, check this option.  '.
          'By default, Phabricator will automatically install and configure '.
          'SSH on the Windows image.')
      ),
      'spot' => array(
        'name' => pht('Spot Instances'),
        'type' => 'header'
      ),
      'spot-enabled' => array(
        'name' => pht('Use Spot Instances'),
        'type' => 'bool',
        'caption' => pht(
          'Use spot instances when allocating EC2 instances.  Spot instances '.
          'are cheaper, but can be terminated at any time (for example, in '.
          'the middle of a Harbormaster build)'),
      ),
      'spot-price' => array(
        'name' => pht('Maximum Bid'),
        'type' => 'decimal',
        'caption' => pht(
          'The maximum bid to pay per hour when running spot instances.  If '.
          'the current bid price exceeds this amount, then the instance will '.
          'be terminated.  WARNING: You should not set this higher '.
          'than the On Demand price for this instance type, or you could end '.
          'up paying more than the non-spot instance price.'),
      ),
    ) + parent::getFieldSpecifications();
  }

}
