<?php

final class DrydockCustomAttributesTestCase extends PhabricatorTestCase {

  public function testNullAttributes() {
    $result = DrydockCustomAttributes::parse(null);
    $this->assertEqual(array(), $result);
  }

  public function testEmptyAttributes() {
    $result = DrydockCustomAttributes::parse('');
    $this->assertEqual(array(), $result);
  }

  public function testSingleAttribute() {
    $result = DrydockCustomAttributes::parse('abc=123');
    $this->assertEqual(array('attr_abc' => '123'), $result);
  }

  public function testMultipleAttributes() {
    $result = DrydockCustomAttributes::parse("abc=123\nxyz=456");
    $this->assertEqual(array(
        'attr_abc' => '123',
        'attr_xyz' => '456',
      ), $result);
  }
}
