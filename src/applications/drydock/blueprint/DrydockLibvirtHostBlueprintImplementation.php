<?php

final class DrydockLibvirtHostBlueprintImplementation
  extends DrydockMinMaxExpiryBlueprintImplementation {

  public function isEnabled() {
    return true;
  }

  public function getBlueprintName() {
    return pht('Libvirt Hosts');
  }

  public function getDescription() {
    return pht(
      'Allows Drydock to allocate and execute commands on '.
      'libvirt-based remote hosts.');
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

  protected function executeInitializePendingResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    // We must set the platform so that other allocators will lease
    // against it successfully.
    $resource
      ->setAttribute(
        'platform',
        $this->getDetail('platform'))
      ->save();
  }

  private function getSSHFuture($credential, $command) {
    $argv = func_get_args();
    array_shift($argv);
    $future = new ExecFuture(
      'ssh '.
      '-o LogLevel=quiet '.
      '-o StrictHostKeyChecking=no '.
      '-o UserKnownHostsFile=/dev/null '.
      '-o BatchMode=yes '.
      '-p %s -i %P %P@%s -- %s',
      $this->getDetail('port'),
      $credential->getKeyfileEnvelope(),
      $credential->getUsernameEnvelope(),
      $this->getDetail('host'),
      call_user_func_array('csprintf', $argv));
    return $future;
  }

  protected function executeAllocateResource(
    DrydockResource $resource,
    DrydockLease $lease) {

    $credential = id(new PassphraseCredentialQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($this->getDetail('keypair')))
      ->executeOne();

    if ($credential === null) {
      throw new Exception('Specified credential does not exist!');
    }

    $this->log(pht(
      'Using credential %d to allocate.',
      $credential->getID()));

    $loaded_credential = PassphraseSSHKey::loadFromPHID(
      $this->getDetail('keypair'),
      PhabricatorUser::getOmnipotentUser());

    $winrm_auth_id = null;
    if ($this->getDetail('platform') === 'windows') {
      $winrm_auth = id(new PassphraseCredentialQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($this->getDetail('winrm-auth')))
        ->executeOne();

      if ($winrm_auth === null) {
        throw new Exception(
          'Specified credential for WinRM auth does not exist!');
      }

      $winrm_auth_id = $winrm_auth->getID();

      $this->log(pht(
        'Using credential %d to authenticate over WinRM.',
        $winrm_auth_id));
    }

    $pool_name = $this->getDetail('storage-pool');
    $image_name = 'image-'.$resource->getID();
    $vm_name = 'vm-'.$resource->getID();

    $this->log(pht(
      'Allocating new image from "%s" as "%s".',
      $this->getDetail('base-image'),
      $image_name));

    $future = $this->getSSHFuture(
      $loaded_credential,
      '/usr/bin/virsh vol-create-as %s %s 256GB '.
      '--format qcow2 --backing-vol %s '.
      '--backing-vol-format qcow2',
      $this->getDetail('storage-pool'),
      $image_name,
      $this->getDetail('base-image'));
    $future->resolvex();

    $this->log(pht(
      'Allocated new image from "%s" as "%s".',
      $this->getDetail('base-image'),
      $image_name));

    $this->log(pht(
      'Retrieving path to new image "%s".',
      $image_name));

    $future = $this->getSSHFuture(
      $loaded_credential,
      '/usr/bin/virsh vol-path %s --pool %s',
      $image_name,
      $this->getDetail('storage-pool'));
    list($stdout, $stderr) = $future->resolvex();
    $image_path = trim($stdout);

    $this->log(pht(
      'Retrieved image path of "%s" as "%s".',
      $image_name,
      $image_path));

    $ram = $this->getDetail('ram');
    $vcpu = $this->getDetail('cpu');
    $network = $this->getDetail('network-id');

    $xml = <<<EOF
<domain type='kvm'>
  <name>$vm_name</name>
  <memory unit='MiB'>$ram</memory>
  <currentMemory unit='MiB'>$ram</currentMemory>
  <vcpu placement='static'>$vcpu</vcpu>
  <os>
    <type arch='x86_64' machine='pc-1.1'>hvm</type>
    <boot dev='hd'/>
  </os>
  <features>
    <acpi/>
    <apic/>
    <pae/>
  </features>
  <cpu mode='custom' match='exact'>
    <model fallback='allow'>Nehalem</model>
  </cpu>
  <clock offset='localtime'>
    <timer name='rtc' tickpolicy='catchup'/>
    <timer name='pit' tickpolicy='delay'/>
    <timer name='hpet' present='no'/>
  </clock>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2' cache='writeback'/>
      <source file='$image_path' />
      <target dev='vda' bus='virtio'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x0b' function='0x0'/>
    </disk>
    <controller type='usb' index='0' model='ich9-ehci1'>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x05' function='0x0'/>
    </controller>
    <controller type='usb' index='0' model='ich9-uhci1'>
      <master startport='0'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x06' function='0x0'/>
    </controller>
    <controller type='usb' index='0' model='ich9-uhci2'>
      <master startport='2'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x07' function='0x0'/>
    </controller>
    <controller type='usb' index='0' model='ich9-uhci3'>
      <master startport='4'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x08' function='0x0'/>
    </controller>
    <controller type='ide' index='0'>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x01' function='0x1'/>
    </controller>
    <controller type='virtio-serial' index='0'>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x09' function='0x0'/>
    </controller>
    <interface type='network'>
      <source network='$network'/>
      <model type='virtio'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x03' function='0x0'/>
    </interface>
    <serial type='pty'>
      <target port='0'/>
    </serial>
    <console type='pty'>
      <target type='serial' port='0'/>
    </console>
    <channel type='spicevmc'>
      <target type='virtio' name='com.redhat.spice.0'/>
      <address type='virtio-serial' controller='0' bus='0' port='1'/>
    </channel>
    <input type='tablet' bus='usb'/>
    <input type='mouse' bus='ps2'/>
    <graphics type='spice' autoport='yes' listen='0.0.0.0'>
      <listen type='address' address='0.0.0.0'/>
    </graphics>
    <sound model='ich6'>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x04' function='0x0'/>
    </sound>
    <video>
      <model type='cirrus' vram='9216' heads='1'/>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x02' function='0x0'/>
    </video>
    <memballoon model='virtio'>
      <address type='pci' domain='0x0000'
        bus='0x00' slot='0x0a' function='0x0'/>
    </memballoon>
  </devices>
</domain>
EOF;

    $this->log(pht(
      'Creating new virtual machine "%s".',
      $vm_name));

    $future = $this->getSSHFuture(
      $loaded_credential,
      '/usr/bin/virsh create /dev/stdin');
    $future->write($xml);
    $future->resolvex();

    $this->log(pht(
      'Created new virtual machine "%s".',
      $vm_name));

    $blueprint = $this->getInstance();
    $resource
      ->setName($vm_name)
      ->setStatus(DrydockResourceStatus::STATUS_PENDING)
      ->setAttributes(array(
        'platform' => $this->getDetail('platform'),
        'protocol' => $this->getDetail('protocol'),
        'path' => $this->getDetail('storage-path'),
        'credential' => $credential->getID(),
        'winrm-auth' => $winrm_auth_id,
        'vm-name' => $vm_name,
        'image-name' => $image_name,
        'image-path' => $image_path,
        'storage-pool' => $this->getDetail('storage-pool'),
      ))
      ->save();

    $this->log(pht(
      'Retrieving MAC address of virtual machine "%s".',
      $vm_name));

    $future = $this->getSSHFuture(
      $loaded_credential,
      '/usr/bin/virsh dumpxml %s',
      $vm_name);
    list($stdout, $stderr) = $future->resolvex();
    $status_xml = simplexml_load_string(trim($stdout));
    if ($status_xml === false) {
      throw new Exception('Unable to read VM XML!');
    }

    $mac_address = $status_xml->devices->interface->mac->attributes()->address;

    $this->log(pht(
      'MAC address of virtual machine "%s" is "%s".',
      $vm_name,
      $mac_address));

    $resource->setAttribute('mac-address', $mac_address);

    if ($resource->getAttribute('platform') === 'windows') {
      $resource->setAttribute('port', 5985);
    } else {
      $resource->setAttribute('port', 22);
    }

    $resource->save();

    $this->log(pht(
      'Waiting until the virtual machine is allocated an IP address...'));

    $host = $this->discoverIPAddressFromMACAddress(
      $resource,
      $loaded_credential);

    $this->log(pht(
      'Virtual machine "%s" currently has an IP address of "%s".',
      $vm_name,
      $host));

    $lease->setAttribute('host', $host);

    $protocol_name = '';
    if ($resource->getAttribute('platform') === 'windows') {
      $protocol_name = 'WinRM';
    } else {
      $protocol_name = 'SSH';
    }

    $this->log(pht(
      'Waiting for a successful %s connection', $protocol_name));

    // Wait until we get a successful connection.
    $ssh = $this->getInterface($resource, $lease, 'command');
    $ssh->setExecTimeout(60);
    if ($resource->getAttribute('platform') !== 'windows') {
      $ssh->setConnectTimeout(60);
    }

    $resource->save();

    while (true) {
      try {
        $this->log(pht(
          'Attempting to connect to "%s" via %s',
          $vm_name,
          $protocol_name));

        $ssh_future = $ssh->getExecFuture('echo "test"');
        $ssh_future->resolvex();
        if ($ssh_future->getWasKilledByTimeout()) {
          throw new Exception(pht('%s execution timed out.', $protocol_name));
        }

        break;
      } catch (Exception $ex) {
        $this->log(pht(
          '%s connection not yet ready',
          $protocol_name));

        continue;
      }
    }

    // Update the resource into open status.
    $resource->setStatus(DrydockResourceStatus::STATUS_OPEN);
    $resource->save();

    $this->log(pht(
      'Resource is now ready for use.'));

    return $resource;
  }

  private function discoverIPAddressFromMACAddress(
    DrydockResource $resource,
    $loaded_credential) {

    $mac_address = $resource->getAttribute('mac-address');
    if (is_array($mac_address)) {
      $mac_address = head($mac_address);
    }
    phlog(print_r($mac_address, true));

    $has_ip_assigned = false;
    while (!$has_ip_assigned) {
      $found = false;
      $future = $this->getSSHFuture(
        $loaded_credential,
        'cat %s',
        $this->getDetail('dnsmasq-path'));
      list($stdout, $stderr) = $future->resolvex();
      foreach (phutil_split_lines($stdout) as $line) {
        $components = explode(' ', $line);
        if (trim($components[1]) === trim($mac_address)) {
          // We have found the allocation for this machine.
          return $components[2];
          $has_ip_assigned = true;
          break;
        } else {
          phlog(pht('%s !== %s', $components[1], $mac_address));
        }
      }

      if (!$has_ip_assigned) {
        sleep(10);
      }
    }

    return null;
  }

  protected function executeCloseResource(DrydockResource $resource) {

    if (!$resource->getAttribute('vm-name') ||
        !$resource->getAttribute('image-name') ||
        !$resource->getAttribute('storage-pool')) {
      return;
    }

    try {
      $loaded_credential = PassphraseSSHKey::loadFromPHID(
        $this->getDetail('keypair'),
        PhabricatorUser::getOmnipotentUser());

      $this->log(pht(
        'Shutting down virtual machine "%s"...',
        $resource->getAttribute('vm-name')));

      $future = $this->getSSHFuture(
        $loaded_credential,
        '/usr/bin/virsh destroy %s',
        $resource->getAttribute('vm-name'));
      $future->resolvex();

      $this->log(pht(
        'Shut down of virtual machine "%s" complete.',
        $resource->getAttribute('vm-name')));

      $this->log(pht(
        'Removing image "%s"...',
        $resource->getAttribute('image-name')));

      $future = $this->getSSHFuture(
        $loaded_credential,
        '/usr/bin/virsh vol-delete %s --pool %s',
        $resource->getAttribute('image-name'),
        $resource->getAttribute('storage-pool'));
      $future->resolvex();

      $this->log(pht(
        'Removed image "%s".',
        $resource->getAttribute('image-name')));
    } catch (Exception $ex) {
      $this->log(pht(
        'Unable to cleanly close resource (was the VM '.
        'already been shut down?)'));
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

    $loaded_credential = PassphraseSSHKey::loadFromPHID(
      $this->getDetail('keypair'),
      PhabricatorUser::getOmnipotentUser());

    $this->log(pht(
      'Waiting until the virtual machine is allocated an IP address...'));

    $host = $this->discoverIPAddressFromMACAddress(
      $resource,
      $loaded_credential);

    $this->log(pht(
      'Virtual machine "%s" currently has an IP address of "%s".',
      $resource->getName(),
      $host));

    $lease->setAttribute('host', $host);

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
          throw $ex;
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
      case 'command-'.PhutilCommandString::MODE_POWERSHELL:
      case 'command-'.PhutilCommandString::MODE_WINDOWSCMD:
      case 'command-'.PhutilCommandString::MODE_BASH:
        $interface = new DrydockSSHCommandInterface();
        if ($resource->getAttribute('platform') === 'windows') {
          $interface = new DrydockWinRMCommandInterface();
        }

        switch ($type) {
          case 'command':
          case 'command-'.PhutilCommandString::MODE_POWERSHELL:
            $interface->setEscapingMode(PhutilCommandString::MODE_POWERSHELL);
            break;
          case 'command-'.PhutilCommandString::MODE_WINDOWSCMD:
            $interface->setEscapingMode(PhutilCommandString::MODE_WINDOWSCMD);
            break;
          case 'command-'.PhutilCommandString::MODE_BASH:
            $interface->setEscapingMode(PhutilCommandString::MODE_BASH);
            break;
        }

        $loaded_credential = PassphraseSSHKey::loadFromPHID(
          $this->getDetail('keypair'),
          PhabricatorUser::getOmnipotentUser());

        if ($resource->getAttribute('platform') !== 'windows') {
          return $interface
            ->setConfiguration(array(
              'host' => $lease->getAttribute('host'),
              'port' => $resource->getAttribute('port'),
              'credential' => $resource->getAttribute('credential'),
              'platform' => $resource->getAttribute('platform'),
            ))
            ->setWorkingDirectory($lease->getAttribute('path'))
            ->setSSHProxy(
              $this->getDetail('host'),
              $this->getDetail('port'),
              $loaded_credential);
        } else if ($resource->getAttribute('platform') === 'windows') {
          return $interface
            ->setConfiguration(array(
              'host' => $lease->getAttribute('host'),
              'port' => $resource->getAttribute('port'),
              'credential' => $resource->getAttribute('winrm-auth'),
              'platform' => $resource->getAttribute('platform'),
            ))
            ->setWorkingDirectory($lease->getAttribute('path'))
            ->setSSHProxy(
              $this->getDetail('host'),
              $this->getDetail('port'),
              $loaded_credential);
        } else {
          throw new Exception('Unsupported protocol for remoting');
        }
      case 'filesystem':
        return id(new DrydockSFTPFilesystemInterface())
          ->setConfiguration(array(
            'host' => $lease->getAttribute('host'),
            'port' => $resource->getAttribute('port'),
            'credential' => $resource->getAttribute('credential'),
          ));
    }

    throw new Exception("No interface of type '{$type}'.");
  }

  public function getFieldSpecifications() {
    return array(
      'libvirt' => array(
        'name' => pht('libvirt Configuration'),
        'type' => 'header',
      ),
      'host' => array(
        'name' => pht('SSH Host'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. 10.0.0.1'),
      ),
      'port' => array(
        'name' => pht('SSH Port'),
        'type' => 'text',
        'required' => false,
        'caption' => pht('Defaults to port 22'),
      ),
      'keypair' => array(
        'name' => pht('SSH Key Pair'),
        'type' => 'credential',
        'required' => true,
        'credential.provides'
          => PassphraseSSHPrivateKeyCredentialType::PROVIDES_TYPE,
        'caption' => pht(
          'Used to connect over SSH to the libvirt host.'),
      ),
      'winrm-auth' => array(
        'name' => pht('WinRM Credentials'),
        'type' => 'credential',
        'credential.provides'
          => PassphrasePasswordCredentialType::PROVIDES_TYPE,
        'caption' => pht(
          'This is only required if the platform is "windows".'),
      ),
      'platform' => array(
        'name' => pht('Platform Name'),
        'type' => 'text',
        'required' => true,
        'caption' => pht('e.g. %s or %s', 'windows', 'linux'),
      ),
      'storage-path' => array(
        'name' => pht('Storage Path'),
        'type' => 'text',
        'required' => true,
        'caption' => pht(
          'A writable location on the instance where new directories / files '.
          'can be created and data can be stored in.'),
      ),
      'machine' => array(
        'name' => pht('Instance Configuration'),
        'type' => 'header',
      ),
      'cpu' => array(
        'name' => pht('CPUs'),
        'type' => 'int',
        'required' => true,
        'caption' => pht('The number of CPUs to allocate to instances.'),
      ),
      'ram' => array(
        'name' => pht('RAM'),
        'type' => 'int',
        'required' => true,
        'caption' => pht(
          'The amount of RAM (in megabytes) to '.
          'allocate to instances.'),
      ),
      'storage-pool' => array(
        'name' => pht('Storage Pool'),
        'type' => 'text',
        'required' => true,
        'caption' => pht(
          'The storage pool to clone instance images into.'),
      ),
      'base-image' => array(
        'name' => pht('Base Image Name'),
        'type' => 'text',
        'required' => true,
        'caption' => pht(
          'The name of the qcow2 image in the storage pool, which '.
          'is cloned for new instances.'),
      ),
      'network' => array(
        'name' => pht('Networking Configuration'),
        'type' => 'header',
      ),
      'network-id' => array(
        'name' => pht('Network Name'),
        'type' => 'text',
        'caption' => pht(
          'The name of the libvirt network to assign to the instance.'),
      ),
      'dnsmasq-path' => array(
        'name' => pht('DNSMasq Leases Path'),
        'type' => 'text',
        'caption' => pht(
          'The path to the DNSMasq leases file, usually located at a path '.
          'such as /var/lib/libvirt/dnsmasq/<network>.leases.'),
      ),
      'attr-header' => array(
        'name' => pht('Host Attributes'),
        'type' => 'header',
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
