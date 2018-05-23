CREATE TABLE itipinvitations (
  token varchar(64) NOT NULL PRIMARY KEY,
  event_uid varchar(255) NOT NULL,
  user_id integer NOT NULL default '0',
  event text NOT NULL,
  expires datetime NOT NULL default '1000-01-01 00:00:00',
  cancelled tinyint(1) NOT NULL default '0',
  CONSTRAINT fk_itipinvitations_user_id FOREIGN KEY (user_id)
    REFERENCES users(user_id)
);

CREATE INDEX ix_itipinvitations_uid ON itipinvitations(user_id, event_uid);
