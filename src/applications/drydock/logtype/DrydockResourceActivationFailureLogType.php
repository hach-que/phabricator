<?php

final class DrydockResourceActivationFailureLogType extends DrydockLogType {

  const LOGCONST = 'core.resource.activation-failure';

  public function getLogTypeName() {
    return pht('Activation Failed');
  }

  public function getLogTypeIcon(array $data) {
    return 'fa-times red';
  }

  public function renderLog(array $data) {
    $class = idx($data, 'class');
    $message = idx($data, 'message');
    $stdout = idx($data, 'stdout', null);
    $stderr = idx($data, 'stderr', null);

    $primary = pht('Resource activation failed: [%s] %s', $class, $message);
    if ($stdout !== null || $stderr !== null) {
      $stdout = phutil_split_lines($stdout);
      $stderr = phutil_split_lines($stderr);

      $formatted_stdout = array();
      $formatted_stderr = array();
      foreach ($stdout as $line) {
        $formatted_stdout[] = $line;
        $formatted_stdout[] = phutil_tag('br', array(), null);
      }
      foreach ($stderr as $line) {
        $formatted_stderr[] = $line;
        $formatted_stderr[] = phutil_tag('br', array(), null);
      }

      array_pop($formatted_stderr);

      $primary = array(
        $primary,
        phutil_tag('br', array(), null),
        pht('STDOUT'),
        phutil_tag('br', array(), null),
        $formatted_stdout,
        pht('STDERR'),
        phutil_tag('br', array(), null),
        $formatted_stderr,
      );
    }

    return $primary;
  }

}
