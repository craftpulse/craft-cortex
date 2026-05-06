<!-- craftcms-claude-skills -->
# Migrations

- Scaffold with `ddev craft make migration --with-docblocks`.
- `Install.php` for the full schema on fresh install. Numbered migrations for incremental changes.
- Idempotent: check if column/table exists before creating. `safeDown()` should reverse `safeUp()`.
- Foreign keys with explicit `CASCADE` or `SET NULL` behavior. Index columns used in queries.
- `muteEvents = true` when writing to project config in migrations — prevents infinite event loops.
- Project-config-driven settings (command allowlist, edition gates, tool toggles) use `Craft::$app->getProjectConfig()->set()` — never direct DB inserts for managed settings.
- After adding columns, update the Record class and the element query's `addSelect()`.
- Test: `ddev craft migrate/up` succeeds, `ddev craft migrate/down` reverses cleanly, `ddev craft migrate/up` succeeds again.
- Deploy: `ddev craft up` runs both migrations and project config apply. Never run `migrate/all` and `project-config/apply` separately.
- Run migrations against the test environment in `~/dev/craft-plugin-playground/cms_v5`, not against this repo (this repo has no Craft install).
