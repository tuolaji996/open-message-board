ALTER TABLE posts
    ADD COLUMN client_timezone VARCHAR(80) NULL AFTER user_agent,
    ADD COLUMN browser_language VARCHAR(120) NULL AFTER client_timezone;

ALTER TABLE comments
    ADD COLUMN client_timezone VARCHAR(80) NULL AFTER user_agent,
    ADD COLUMN browser_language VARCHAR(120) NULL AFTER client_timezone;
