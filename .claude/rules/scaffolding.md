<!-- craftcms-claude-skills -->
# Scaffolding

- Always use `ddev craft make <type> --with-docblocks` to scaffold new components.
- Never manually create boilerplate that the generator handles.
- After scaffolding, customize: add section headers, `@author Craftpulse`, `@since` version, `@throws` chains, and project naming conventions.
- Available generators: `element-type`, `service`, `controller`, `command`, `queue-job`, `model`, `record`, `field-type`, `validator`, `widget-type`, `utility`, `asset-bundle`, `behavior`, `twig-extension`, `element-action`, `element-condition-rule`, `element-exporter`, `gql-directive`.
- Run generators from the test environment (`~/dev/craft-plugin-playground/cms_v5`) where Cortex is symlinked into `vendor/`. The generator writes into the symlinked source.
- Plugin-level scaffolding (`ddev craft make plugin`) was bypassed — the repo will be hand-bootstrapped to match the locked architecture (composer.json with dual transport deps, Plugin.php registering services). Don't re-run plugin scaffolding once that bootstrap lands.
