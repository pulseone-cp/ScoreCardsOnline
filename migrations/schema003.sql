-- Schema version 3: add voting_open flag to laps to allow moderators to stop/resume voting mid-lap
ALTER TABLE laps ADD COLUMN voting_open TINYINT(1) NOT NULL DEFAULT 1 AFTER ended_at;