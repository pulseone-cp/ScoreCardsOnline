-- Schema version 2: add moderator secret per room
ALTER TABLE rooms ADD COLUMN mod_hash VARCHAR(64) NULL AFTER hash;

-- Backfill existing rooms with unique moderator secrets
UPDATE rooms SET mod_hash = SUBSTRING(REPLACE(UUID(),'-',''), 1, 16) WHERE mod_hash IS NULL;

-- Enforce NOT NULL and uniqueness for moderator hash
ALTER TABLE rooms
  MODIFY mod_hash VARCHAR(64) NOT NULL,
  ADD UNIQUE KEY uniq_rooms_mod_hash (mod_hash);
