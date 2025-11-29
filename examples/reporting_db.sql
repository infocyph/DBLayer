-- reporting_db.sql
-- PostgreSQL schema for the "reporting_db" database used by DBLayer examples.

-- NOTE: usually you run CREATE DATABASE from the "postgres" DB,
-- then connect to "reporting_db" and run the rest.
-- Adjust ownership/encoding as needed for your setup.

CREATE
DATABASE reporting_db
  WITH
    TEMPLATE = template0
    ENCODING = 'UTF8'
    LC_COLLATE = 'en_US.utf8'
    LC_CTYPE = 'en_US.utf8';

-- After creating the DB, connect to it, then run:

-- \c reporting_db

CREATE TABLE public.user_events
(
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT      NOT NULL,
    event_type  TEXT        NOT NULL,
    data        JSONB NULL,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX user_events_user_id_occurred_at_idx
    ON public.user_events (user_id, occurred_at DESC);

-- Optional: GIN index for searching inside JSON data
CREATE INDEX user_events_data_gin_idx
    ON public.user_events USING GIN (data);
