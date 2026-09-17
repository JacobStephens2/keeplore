-- Drop the legacy kept/format columns (spec #10, ticket #17: Contract).
--
-- Every reader and writer is on the new vocabulary (is_kept,
-- is_in_secondary_collection, is_digital, is_physical) after the Migrate
-- tickets, so the legacy columns and the dual-write path go away here,
-- leaving exactly one kept/format vocabulary. MySQL drops indexes that
-- reference a dropped column automatically.

ALTER TABLE games
  DROP COLUMN KeptCol,
  DROP COLUMN InSecondaryCollection,
  DROP COLUMN KeptDig,
  DROP COLUMN KeptPhys;
