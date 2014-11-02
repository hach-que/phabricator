<?php

final class DrydockSetupCheckWinRM extends PhabricatorSetupCheck {

  protected function executeChecks() {

    $drydock_app = 'PhabricatorDrydockApplication';
    if (!PhabricatorApplication::isClassInstalled($drydock_app)) {
      return;
    }

    if (!Filesystem::binaryExists('winrm')) {
      $preamble = pht(
        "The 'winrm' binary could not be found. This utility is used to ".
        "run commands on remote Windows machines when they are leased through ".
        "Drydock.\n\n".
        "You will most likely need to download and compile it from ".
        "%s, using the Go compiler.  Once you have, place the binary ".
        "somewhere in your %s.",
        phutil_tag(
          'a',
          array('href' => 'https://github.com/masterzen/winrm'),
          'https://github.com/masterzen/winrm'),
        phutil_tag('tt', array(), 'PATH'));

      $message = pht(
        'You only need this binary if you are leasing Windows hosts in '.
        'Drydock or Harbormaster.  If you don\'t need to run commands on '.
        'Windows machines, you can safely ignore this message.');

      $this->newIssue('bin.winrm')
        ->setShortName(pht("'%s' Missing", 'winrm'))
        ->setName(pht("Missing '%s' Binary", 'winrm'))
        ->setSummary(
          pht("The '%s' binary could not be located or executed.", 'winrm'))
        ->setMessage(pht("%s\n\n%s", $preamble, $message));
    }

  }

}
