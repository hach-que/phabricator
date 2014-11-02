<?php

final class DrydockCustomAttributes extends Phobject {

  public static function parse($text) {
    $pairs = phutil_split_lines($text);
    $attributes = array();

    foreach ($pairs as $line) {
      $kv = explode('=', $line, 2);
      if (count($kv) === 0) {
        continue;
      } else if (count($kv) === 1) {
        $attributes['attr_'.$kv[0]] = true;
      } else {
        $attributes['attr_'.$kv[0]] = trim($kv[1]);
      }
    }

    return $attributes;
  }

  public static function hasRequirements($attributes, $provided) {
    $provided = self::parse($provided);

    foreach ($attributes as $key => $value) {
      // Only compare custom attributes.
      if (substr($key, 0, 5) === 'attr_') {
        if ($value === true) {
          if (!array_key_exists($provided, $key)) {
            return false;
          }
        } else {
          if (idx($provided, $key) !== $value) {
            return false;
          }
        }
      }
    }

    return true;
  }

}
