CREATE TABLE itipinvitations (
    token varchar(64) NOT NULL,
    event_uid varchar(255) NOT NULL,
    user_id integer NOT NULL
        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    event TEXT NOT NULL,
    expires timestamp without time zone DEFAULT NULL,
    cancelled smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (token)
);

CREATE INDEX itipinvitations_user_id_event_uid_idx ON itipinvitations (user_id, event_uid);
