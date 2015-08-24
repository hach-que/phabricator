ALTER TABLE {$NAMESPACE}_harbormaster.harbormaster_build
  ADD buildParameters LONGTEXT COLLATE {$COLLATE_TEXT} NOT NULL;

ALTER TABLE {$NAMESPACE}_harbormaster.harbormaster_build
  ADD buildParametersHash VARCHAR(32) COLLATE {$COLLATE_TEXT}
  NOT NULL DEFAULT '2c08d653e1ee60d55cd0da551026ea56';
