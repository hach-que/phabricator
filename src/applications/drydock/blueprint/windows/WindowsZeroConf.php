<?php

/**
 * Responsible for configuring and automatically installing SSH on Windows
 * EC2 instances when they start.
 */
final class WindowsZeroConf extends Phobject {

  public function getEncodedUserData(PassphraseCredential $credential) {
    return base64_encode($this->getUserData($credential));
  }

  private function getZeroConfScript() {
    $file =
      dirname(phutil_get_library_root('phabricator')).
      '/resources/windows/zeroconf.ps1';
    return Filesystem::readFile($file);
  }

  private function getUserData(PassphraseCredential $credential) {

    $type = PassphraseCredentialType::getTypeByConstant(
      $credential->getCredentialType());
    if (!$type) {
      throw new Exception(pht('Credential has invalid type "%s"!', $type));
    }

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

    $script = $this->getZeroConfScript();

    $end = <<<EOF

</powershell>
EOF;

    return $start.$script.$end;
  }

}
