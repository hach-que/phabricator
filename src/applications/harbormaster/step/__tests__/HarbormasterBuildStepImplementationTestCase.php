<?php

final class HarbormasterBuildStepImplementationTestCase
  extends PhabricatorTestCase {

  public function testGetImplementations() {
    HarbormasterBuildStepImplementation::getImplementations();
    $this->assertTrue(true);
  }

  public function testVariableMergeForRequiredVariables() {
    $variables = array(
      'mandatory' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${mandatory}b c${withvalue}d',
      $variables);

    $this->assertEqual("echo a''b c'val'd", (string)$result);
  }

  public function testVariableMergeForMixedVariables1() {
    $variables = array(
      'mandatory' => '',
      'optional' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${mandatory}b c${withvalue}d e${?optional}f',
      $variables);

    $this->assertEqual("echo a''b c'val'd ef", (string)$result);
  }

  public function testVariableMergeForMixedVariables2() {
    $variables = array(
      'mandatory' => '',
      'optional' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${mandatory}b e${?optional}f c${withvalue}d',
      $variables);

    $this->assertEqual("echo a''b ef c'val'd", (string)$result);
  }

  public function testVariableMergeForMixedVariables3() {
    $variables = array(
      'mandatory' => '',
      'optional' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo e${?optional}f a${mandatory}b c${withvalue}d',
      $variables);

    $this->assertEqual("echo ef a''b c'val'd", (string)$result);
  }

  public function testVariableMergeForMixedVariables4() {
    $variables = array(
      'mandatory' => '',
      'optional' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${?optional}b c${mandatory}d e${?optional}f '.
      'g${withvalue}h i${?optional}j',
      $variables);

    $this->assertEqual("echo ab c''d ef g'val'h ij", (string)$result);
  }

  public function testVariableMergeForMissingVariables1() {
    $variables = array(
      'mandatory' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${mandatory}b c${withvalue}d e${?optional}f',
      $variables);

    $this->assertEqual("echo a''b c'val'd ef", (string)$result);
  }

  public function testVariableMergeForMissingVariables2() {
    $variables = array(
      'mandatory' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${mandatory}b e${?optional}f c${withvalue}d',
      $variables);

    $this->assertEqual("echo a''b ef c'val'd", (string)$result);
  }

  public function testVariableMergeForMissingVariables3() {
    $variables = array(
      'mandatory' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo e${?optional}f a${mandatory}b c${withvalue}d',
      $variables);

    $this->assertEqual("echo ef a''b c'val'd", (string)$result);
  }

  public function testVariableMergeForMissingVariables4() {
    $variables = array(
      'mandatory' => '',
      'withvalue' => 'val',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${?optional}b c${mandatory}d '.
      'e${?optional}f g${withvalue}h i${?optional}j',
      $variables);

    $this->assertEqual("echo ab c''d ef g'val'h ij", (string)$result);
  }

  public function testVariableMergeReplication1() {
    $variables = array(
      'mandatory' => '',
    );

    $method = new ReflectionMethod(
      'HarbormasterBuildStepImplementation',
      'mergeVariables');
    $method->setAccessible(true);
    $result = $method->invoke(
      new HarbormasterCommandBuildStepImplementation(),
      'vcsprintf',
      'echo a${?optional}b c${mandatory}d e${?optional}f '.
      'g${mandatory}h i${mandatory}j k${?optional}l m${?optional}n',
      $variables);

    $this->assertEqual("echo ab c''d ef g''h i''j kl mn", (string)$result);
  }


}
