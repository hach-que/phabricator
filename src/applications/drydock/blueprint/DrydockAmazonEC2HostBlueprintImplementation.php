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
    $platform_match =
      $lease->getAttribute('platform') === $this->getDetail('platform');
    $custom_match = DrydockCustomAttributes::hasRequirements(
      $lease->getAttributes(),
      $this->getDetail('attributes'));

    if ($platform_match && $custom_match) {
      $this->log(pht(
        'This blueprint can allocate a resource for the specified lease.'));
    } else {
      $this->log(pht(
        'This blueprint can not allocate a resource for the specified lease.'));
    }

    return $platform_match && $custom_match;
  }

  protected function canAllocateLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $platform_match =
      $lease->getAttribute('platform') === $resource->getAttribute('platform');
    $custom_match = DrydockCustomAttributes::hasRequirements(
      $lease->getAttributes(),
      $this->getDetail('attributes'));

    if ($platform_match && $custom_match) {
      $this->log(pht(
        'This blueprint can allocate the specified lease.'));
    } else {
      $this->log(pht(
        'This blueprint can not allocate the specified lease.'));
    }

    return $platform_match && $custom_match;
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

    $this->log(pht(
      'Using credential %d to allocate.',
      $credential->getID()));

    try {
      $existing_keys = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'DescribeKeyPairs',
          array(
            'KeyName.0' => $this->getAWSKeyPairName()))
        ->resolve();

      $this->log(pht('Credential\'s public key already exists in Amazon.'));
    } catch (PhutilAWSException $ex) {
      // The key pair does not exist, so we need to import it.
      $this->log(pht('Credential\'s public key does not exist in Amazon.'));

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

      $this->log(pht('Imported key pair to Amazon.'));
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
        $this->log(pht('Enabled SSH automatic configuration for Windows.'));
        $settings['UserData'] = id(new WindowsZeroConf())
          ->getEncodedUserData($credential);
      }
    }

    if ($this->getDetail('spot-enabled') &&
      $this->getDetail('spot-price') !== null) {

      $this->log(pht(
        'Spot price allocation is enabled, at a price of %f.',
        $this->getDetail('spot-price')));

      $spot_price = $this->getDetail('spot-price');

      $spot_settings = array(
        'SpotPrice' => $spot_price,
        'InstanceCount' => 1,
        'Type' => 'one-time');

      foreach ($settings as $key => $value) {
        $spot_settings['LaunchSpecification.'.$key] = $value;
      }

      $this->log(pht(
        'Requesting spot instance from Amazon.'));

      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'RequestSpotInstances',
          $spot_settings)
        ->resolve();

      $spot_request = $result->spotInstanceRequestSet->item[0];
      $spot_request_id = (string)$spot_request->spotInstanceRequestId;

      $this->log(pht(
        'Spot instance request ID is %s', $spot_request_id));

      // Wait until the spot instance request exists.
      while (true) {
        try {
          $result = $this->getAWSEC2Future()
            ->setRawAWSQuery(
              'DescribeSpotInstanceRequests',
              array(
                'SpotInstanceRequestId.0' => $spot_request_id))
            ->resolve();
          break;
        } catch (PhutilAWSException $ex) {
          // AWS does not provide immediate consistency, so this may throw
          // "spot request does not exist" right after requesting spot
          // instances.
          $this->log(pht(
            'Spot instance request could not be found (due '.
            'to eventual consistency), trying again in 5 seconds.'));
          sleep(5);

          continue;
        }
      }

      $this->log(pht(
        'Spot instance request %s is now consistent for API access.',
        $spot_request_id));

      $this->log(pht(
        'Tagging the spot instance request with blueprint '.
        'name and resource ID.'));

      try {
        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'CreateTags',
            array(
              'ResourceId.0' => $spot_request_id,
              'Tag.0.Key' => 'Name',
              'Tag.0.Value' => pht(
                'Phabricator (blueprint \'%s\', resource %d)',
                $this->getInstance()->getBlueprintName(),
                $resource->getID()),
              'Tag.1.Key' => 'BlueprintPHID',
              'Tag.1.Value' => $this->getInstance()->getPHID(),
              'Tag.2.Key' => 'BlueprintName',
              'Tag.2.Value' => $this->getInstance()->getBlueprintName(),
              'Tag.3.Key' => 'ResourceID',
              'Tag.3.Value' => $resource->getID(),
              'Tag.4.Key' => 'AllocatedForLeaseID',
              'Tag.4.Value' => $lease->getID(),
              'Tag.5.Key' => 'PhabricatorURI',
              'Tag.5.Value' => PhabricatorEnv::getProductionURI('/'),
              'Tag.6.Key' => 'BlueprintURI',
              'Tag.6.Value' => PhabricatorEnv::getProductionURI(
                '/drydock/blueprint/'.$this->getInstance()->getID().'/'),
              'Tag.7.Key' => 'ResourceURI',
              'Tag.7.Value' => PhabricatorEnv::getProductionURI(
                '/drydock/resource/'.$resource->getID().'/'),
              'Tag.8.Key' => 'AllocatedForLeaseURI',
              'Tag.8.Value' => PhabricatorEnv::getProductionURI(
                '/drydock/lease/'.$lease->getID().'/'),
              ))
          ->resolve();

        $this->log(pht(
          'Tagged spot instance request successfully.'));
      } catch (Exception $ex) {
        $this->log(pht(
          'Unable to tag spot instance request.  Exception was \'%s\'.',
          $ex->getMessage()));
      }

      // Wait until the spot instance request is fulfilled.
      while (true) {
        $result = $this->getAWSEC2Future()
          ->setRawAWSQuery(
            'DescribeSpotInstanceRequests',
            array(
              'SpotInstanceRequestId.0' => $spot_request_id))
          ->resolve();

        $spot_request = $result->spotInstanceRequestSet->item[0];

        $spot_state = (string)$spot_request->state;
        $spot_status = (string)$spot_request->status->code;

        $this->log(pht(
          'Spot instance request is currently in state \'%s\' '.
          'with status \'%s\'.',
          $spot_state,
          $spot_status));

        if ($spot_state == 'open') {
          // Check to see if the price is too low for the request to be
          // fulfilled.
          if ($spot_status == 'price-too-low') {
            // The price is too low.
            $message = pht(
              'The spot instance price used to launch this instance of $%f is '.
              'too low in order for the request to be satisified.  Change the '.
              'blueprint configuration to a higher price.  The Amazon status '.
              'message is \'%s\'',
              $spot_price,
              (string)$spot_request->status->message);

            $this->log($message);

            $this->log(pht(
              'Cancelling spot instance request \'%s\'.',
              $spot_request_id));

            try {
              $result = $this->getAWSEC2Future()
                ->setRawAWSQuery(
                  'CancelSpotInstanceRequests',
                  array(
                    'SpotInstanceRequestId.0' => $spot_request_id))
                ->resolve();
            } catch (PhutilAWSException $ex) {
              $this->log(pht(
                'Unable to cancel spot request \'%s\'; cancel it from the '.
                'AWS console instead.  The error was \'%s\'',
                $spot_request_id,
                $ex->getMessage()));
            }

            $this->log(pht(
              'Spot request \'%s\' cancelled.',
              $spot_request_id));

            throw new Exception($message);
          }

          // We are waiting for the request to be fulfilled.
          sleep(5);
          continue;
        } else if ($spot_state == 'active') {
          // The request has been fulfilled and we now have an instance ID.
          $instance_id = (string)$spot_request->instanceId;
          break;
        } else {
          // The spot request is closed, cancelled or failed.
          $message = pht(
            'Requested a spot instance, but the request is in state '.
            '\'%s\'.  It is likely caused when a spot instance request '.
            'is cancelled from the Amazon Web Console.  It may occur '.
            'when the current bid price exceeds your maximum bid price ($%f).',
            $spot_state,
            $spot_price);

          $this->log($message);

          $this->log(pht(
            'Cancelling spot instance request \'%s\'.',
            $spot_request_id));

          try {
            $result = $this->getAWSEC2Future()
              ->setRawAWSQuery(
                'CancelSpotInstanceRequests',
                array(
                  'SpotInstanceRequestId.0' => $spot_request_id))
              ->resolve();
          } catch (PhutilAWSException $ex) {
            $this->log(pht(
              'Unable to cancel spot request \'%s\'; cancel it from the '.
              'AWS console instead.  The error was \'%s\'',
              $spot_request_id,
              $ex->getMessage()));
          }

          $this->log(pht(
            'Spot request \'%s\' cancelled.',
            $spot_request_id));

          throw new Exception($message);
        }
      }

      $this->log(pht(
        'Spot instance request has been fulfilled, and the instance ID is %s.',
        $instance_id));

    } else {
      $settings['MinCount'] = 1;
      $settings['MaxCount'] = 1;

      $this->log(pht(
        'Requesting on-demand instance from Amazon.'));

      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'RunInstances',
          $settings)
        ->resolve();

      $instance = $result->instancesSet->item[0];
      $instance_id = (string)$instance->instanceId;

      $this->log(pht(
        'The instance that was started is %s.',
        $instance_id));

      // Wait until the instance has appeared.
      while (true) {
        try {
          $result = $this->getAWSEC2Future()
            ->setRawAWSQuery(
              'DescribeInstances',
              array(
                'InstanceId.0' => $instance_id))
            ->resolve();
          break;
        } catch (PhutilAWSException $ex) {
          $this->log(pht(
            'Instance could not be found (due '.
            'to eventual consistency), trying again in 5 seconds.'));
          sleep(5);
          continue;
        }
      }

      $this->log(pht(
        'Instance %s is now consistent for API access.',
        $instance_id));
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

    $this->log(pht(
      'Updated the Drydock resource with the instance information.'));

    $this->log(pht(
      'Tagging the instance with blueprint name and resource ID.'));

    try {
      $result = $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'CreateTags',
          array(
            'ResourceId.0' => $instance_id,
            'Tag.0.Key' => 'Name',
            'Tag.0.Value' => pht(
              'Phabricator (blueprint \'%s\', resource %d)',
              $this->getInstance()->getBlueprintName(),
              $resource->getID()),
            'Tag.1.Key' => 'BlueprintPHID',
            'Tag.1.Value' => $this->getInstance()->getPHID(),
            'Tag.2.Key' => 'BlueprintName',
            'Tag.2.Value' => $this->getInstance()->getBlueprintName(),
            'Tag.3.Key' => 'ResourceID',
            'Tag.3.Value' => $resource->getID(),
            'Tag.4.Key' => 'AllocatedForLeaseID',
            'Tag.4.Value' => $lease->getID(),
            'Tag.5.Key' => 'PhabricatorURI',
            'Tag.5.Value' => PhabricatorEnv::getProductionURI('/'),
            'Tag.6.Key' => 'BlueprintURI',
            'Tag.6.Value' => PhabricatorEnv::getProductionURI(
              '/drydock/blueprint/'.$this->getInstance()->getID().'/'),
            'Tag.7.Key' => 'ResourceURI',
            'Tag.7.Value' => PhabricatorEnv::getProductionURI(
              '/drydock/resource/'.$resource->getID().'/'),
            'Tag.8.Key' => 'AllocatedForLeaseURI',
            'Tag.8.Value' => PhabricatorEnv::getProductionURI(
              '/drydock/lease/'.$lease->getID().'/'),
            ))
        ->resolve();

      $this->log(pht(
        'Tagged instance successfully.'));
    } catch (Exception $ex) {
      $this->log(pht(
        'Unable to tag instance.  Exception was \'%s\'.',
        $ex->getMessage()));
    }

    $this->log(pht(
      'Waiting for the instance to start according to Amazon'));

    // Wait until the instance has started.
    while (true) {
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
        $message = pht(
          'Allocated instance, but ended up in unexpected state \'%s\'! '.
          'Did someone terminate it from the Amazon Web Console?',
          $instance_state);

        $this->log($message);
        throw new Exception($message);
      }
    }

    $this->log(pht(
      'Instance has started in Amazon'));

    $resource->setAttribute('aws-status', 'Started in Amazon');
    $resource->save();

    // Calculate the IP address of the instance.
    $address = '';
    if ($this->getDetail('allocate-elastic-ip')) {
      $this->log(pht(
        'Allocating an Elastic IP as requested'));

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

        while (true) {
          try {
            $result = $this->getAWSEC2Future()
              ->setRawAWSQuery(
                'AssociateAddress',
                array(
                  'InstanceId' => $instance_id,
                  'AllocationId' => $allocation_id))
              ->resolve();
            break;
          } catch (PhutilAWSException $exx) {
            if (substr_count(
              $exx->getMessage(),
              'InvalidAllocationID.NotFound') > 0) {
              // AWS eventual consistency.  Wait a little while.
              sleep(5);
              continue;
            } else {
              throw $exx;
            }
          }
        }

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

        $message =
          'Unable to allocate an elastic IP for the new EC2 instance. '.
          'Check your AWS account limits and ensure your limit on '.
          'elastic IP addresses is high enough to complete the '.
          'resource allocation';

        $this->log($message);
        throw new Exception($message);
      }
    } else {
      $this->log(pht(
        'Not allocating an elastic IP for this instance'));

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

    $this->log(pht(
      'Waiting for a successful SSH connection'));

    // Wait until we get a successful SSH connection.
    $ssh = id(new DrydockSSHCommandInterface())
      ->setConfiguration(array(
        'host' => $resource->getAttribute('host'),
        'port' => $resource->getAttribute('port'),
        'credential' => $resource->getAttribute('credential'),
        'platform' => $resource->getAttribute('platform')));
    $ssh->setConnectTimeout(60);
    $ssh->setExecTimeout(60);

    $resource->setAttribute(
      'aws-status',
      'Waiting for successful SSH connection');
    $resource->save();

    while (true) {
      try {
        $this->log(pht(
          'Attempting to connect to \'%s\' via SSH',
          $instance_id));

        $ssh_future = $ssh->getExecFuture('echo "test"');
        $ssh_future->resolvex();
        if ($ssh_future->getWasKilledByTimeout()) {
          throw new Exception('SSH execution timed out.');
        }

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

        $this->log(pht(
          'SSH connection not yet ready; instance is in state \'%s\'',
          $instance_state));

        if ($instance_state === 'shutting-down' ||
          $instance_state === 'terminated') {

          $this->log(pht(
            'Instance has ended up in state \'%s\' while waiting for an '.
            'SSH connection',
            $instance_state));

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

    $this->log(pht(
      'Resource is now ready for use.'));

    return $resource;
  }

  protected function executeCloseResource(DrydockResource $resource) {

    $this->log(pht(
      'Closing EC2 resource.'));

    // Deallocate and release the public IP address if we allocated one.
    if ($resource->getAttribute('eip-allocated')) {
      try {
        $this->log(pht(
          'Elastic IPs are allocated, so releasing them.'));

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
      } catch (PhutilAWSException $ex) {
        if (substr_count(
          $ex->getMessage(),
          'InvalidAssociationID.NotFound') > 0 ||
          substr_count($ex->getMessage(), 'InvalidAllocationID.NotFound') > 0) {
          // TODO: Should we log this somewhere?
        } else {
          throw $ex;
        }
      }
    }

    $this->log(pht(
      'Requesting instance \'%s\' be terminated.',
      $resource->getAttribute('instance-id')));

    try {
      // Terminate the EC2 instance.
      $this->getAWSEC2Future()
        ->setRawAWSQuery(
          'TerminateInstances',
          array(
            'InstanceId.0' => $resource->getAttribute('instance-id')))
        ->resolve();
    } catch (PhutilAWSException $exx) {
      if (substr_count($exx->getMessage(), 'InvalidInstanceID.NotFound') > 0) {
        return;
      } else {
        throw $exx;
      }
    }

  }

  protected function executeAcquireLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $this->log(pht(
      'Starting acquisition of lease from resource %d',
      $resource->getID()));

    while ($resource->getStatus() == DrydockResourceStatus::STATUS_PENDING) {
      $this->log(pht(
        'Resource %d is still pending, waiting until it is in an open status',
        $resource->getID()));

      // This resource is still being set up by another allocator, wait until
      // it is set to open.
      sleep(5);
      $resource->reload();
    }

    if ($resource->getStatus() != DrydockResourceStatus::STATUS_OPEN) {
      $message = pht(
        'Resource %d did not move into an open status',
        $resource->getID());
      $this->log($message);
      throw new Exception($message);
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

    $this->log(pht(
      'Attempting to create directory \'%s\' on resource %d',
      $full_path,
      $resource->getID()));

    $attempts = 10;
    while ($attempts > 0) {
      $attempts--;
      try {
        if ($platform === 'windows') {
          $cmd->execx('mkdir -Force %s', $full_path);
        } else {
          $cmd->execx('mkdir %s', $full_path);
        }
        break;
      } catch (Exception $ex) {
        if ($attempts == 0) {
          throw ex;
        }

        sleep(5);
      }
    }

    $lease->setAttribute('path', $full_path);

    $this->log(pht(
      'Lease %d acquired on resource %d',
      $lease->getID(),
      $resource->getID()));
  }

  protected function executeReleaseLease(
    DrydockResource $resource,
    DrydockLease $lease) {

    $this->log(pht(
      'Releasing lease %d',
      $lease->getID()));

    $path = $lease->getAttribute('path');

    // Set the path back to null for the lease.  This ensures on Windows
    // machines we don't change to the directory we're about to delete, because
    // Windows implicitly locks a directory from deletion whenever there is a
    // process with it's current working directory within that directory or
    // any of it's sub-directories.
    $lease->setAttribute('path', null);

    $cmd = $lease->getInterface('command');

    try {
      $this->log(pht(
        'Removing contents of \'%s\' on host',
        $path));

      if ($resource->getAttribute('platform') !== 'windows') {
        $cmd->execx('rm -rf %s', $path);
      } else {
        $cmd->execx('rm -Recurse -Force %s', $path);
      }
    } catch (Exception $ex) {
      // We try to clean up, but sometimes files are locked or still in
      // use (this is far more common on Windows).  There's nothing we can
      // do about this, so we ignore it.
      $this->log(pht(
        'An exception occurred while removing files on the host.  This can '.
        'occur when files are locked by the operating system.  The exception '.
        'message was \'%s\'.',
        $ex->getMessage()));
      return;
    }

    $this->log(pht(
      'Removed contents of \'%s\' on host successfully',
      $path));
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
      'attr-header' => array(
        'name' => pht('Host Attributes'),
        'type' => 'header'
      ),
      'attributes' => array(
        'name' => pht('Host Attributes'),
        'type' => 'textarea',
        'caption' => pht(
          'A newline separated list of host attributes.  Each attribute '.
          'should be specified in a key=value format.'),
        'monospace' => true,
      ),
    ) + parent::getFieldSpecifications();
  }

}
