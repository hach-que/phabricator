<?php

/**
 * Responsible for configuring and automatically installing SSH or WinRM
 * on Windows EC2 instances when they start.
 */
final class WindowsZeroConf extends Phobject {

  public function getEncodedUserData(
    PassphraseCredential $credential,
    $protocol) {
    
    return base64_encode($this->getUserData($credential, $protocol));
  }

  private function getSSHZeroConfScript() {
    $file =
      dirname(phutil_get_library_root('phabricator')).
      '/resources/windows/sshzeroconf.ps1';
    return Filesystem::readFile($file);
  }

  private function getWinRMZeroConfScript() {
    $file =
      dirname(phutil_get_library_root('phabricator')).
      '/resources/windows/winrmzeroconf.ps1';
    return Filesystem::readFile($file);
  }

  private function getUserData(PassphraseCredential $credential, $protocol) {

    $type = PassphraseCredentialType::getTypeByConstant(
      $credential->getCredentialType());
    if (!$type) {
      throw new Exception(pht('Credential has invalid type "%s"!', $type));
    }

    if ($protocol === 'ssh') {
      if (!$type->hasPublicKey()) {
        throw new Exception(pht('Credential has no public key!'));
      }

      $username = $credential->getUsername();
      $publickey = $type->getPublicKey(
        PhabricatorUser::getOmnipotentUser(),
        $credential);
      $publickey = trim($publickey);

      $username = str_replace('"', '`"', $username);
      $publickey = str_replace('"', '`"', $publickey);

      $start = <<<EOF
<powershell>
\$username = "$username";
\$publickey = "$publickey";

EOF;

      $script = $this->getZeroConfScript('ssh');

      $end = <<<EOF

</powershell>
EOF;

      return $start.$script.$end;
    } else if ($protocol === 'winrm') {
      $username = $credential->getUsername();
      $password = $credential->getSecret();

      $username = str_replace('"', '`"', $username);
      $password = str_replace('"', '`"', $password);

      $start = <<<EOF
<powershell>
\$username = "$username";
\$password = "$password";

EOF;

      $script = $this->getZeroConfScript('winrm');

      $end = <<<EOF

</powershell>
EOF;

      return $start.$script.$end;

    } else {
      throw new Exception('Unknown protocol for automatic setup');
    }
  }

}
