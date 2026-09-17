-- Self-documenting kept/format columns on games (spec #10, ticket #11: Expand).
--
-- Adds the new vocabulary beside the legacy columns and backfills every row
-- exactly once from current data:
--   is_kept                  <- KeptCol (kept in the primary collection; kept means kept only)
--   is_in_secondary_collection <- InSecondaryCollection ('yes' -> 1, anything else -> 0)
--   is_digital               <- KeptDig (pure format flag, decoupled from kept)
--   is_physical              <- KeptPhys (pure format flag, decoupled from kept)
--
-- The UPDATEs are guarded so the migration is safe to rerun: rows already
-- carrying new-form values are left untouched. Dual-write in the application
-- layer keeps both forms in agreement until the Contract ticket (#17) drops
-- the legacy columns.

ALTER TABLE games
  ADD COLUMN is_kept TINYINT(1) NULL DEFAULT NULL,
  ADD COLUMN is_in_secondary_collection TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_digital TINYINT(1) NULL DEFAULT NULL,
  ADD COLUMN is_physical TINYINT(1) NULL DEFAULT NULL;

UPDATE games SET is_kept = KeptCol WHERE is_kept IS NULL;

UPDATE games
  SET is_in_secondary_collection = CASE WHEN InSecondaryCollection = 'yes' THEN 1 ELSE 0 END;

UPDATE games SET is_digital = KeptDig WHERE is_digital IS NULL;

UPDATE games SET is_physical = KeptPhys WHERE is_physical IS NULL;
