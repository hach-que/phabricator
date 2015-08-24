ALTER TABLE {$NAMESPACE}_drydock.drydock_lease
  ADD isTransientLease TINYINT(1) NOT NULL DEFAULT 0;
