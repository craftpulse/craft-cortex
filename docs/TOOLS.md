# Cortex Tools

Auto-generated reference for the cortex MCP tool surface. Run
`ddev craft cortex/docs/tools` to refresh.

- **Total tools:** 33
- **Generated:** 2026-05-13T14:20:14-07:00

## `get_initial_context`

Bootstrap snapshot for an AI agent picking up a fresh conversation against this Craft install. Returns Craft version + edition + environment, the primary site handle, a thin sites/sections/element-types index, the bundled cortex skill prompts (the moat content the LLM should consult when authoring against Craft), the `craft_exec` posture, and the effective command allowlist. Call this first — it replaces three or four orientation tool calls with one.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true
- `title`: Get Initial Context

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

**Output schema:**

```json
{
    "type": "object",
    "properties": {
        "craft": {
            "type": "object",
            "properties": {
                "version": {
                    "type": "string"
                },
                "edition": {
                    "type": "string"
                },
                "schemaVersion": {
                    "type": "string"
                },
                "environment": {
                    "type": "string"
                },
                "devMode": {
                    "type": "boolean"
                },
                "primarySiteHandle": {
                    "type": "string"
                }
            },
            "required": [
                "version",
                "edition",
                "schemaVersion",
                "environment",
                "devMode",
                "primarySiteHandle"
            ],
            "additionalProperties": false
        },
        "sites": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "handle": {
                        "type": "string"
                    },
                    "name": {
                        "type": "string"
                    },
                    "language": {
                        "type": "string"
                    },
                    "primary": {
                        "type": "boolean"
                    }
                },
                "required": [
                    "handle",
                    "name",
                    "language",
                    "primary"
                ],
                "additionalProperties": false
            }
        },
        "sections": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "handle": {
                        "type": "string"
                    },
                    "name": {
                        "type": "string"
                    },
                    "type": {
                        "type": "string"
                    },
                    "entryTypeCount": {
                        "type": "integer"
                    }
                },
                "required": [
                    "handle",
                    "name",
                    "type",
                    "entryTypeCount"
                ],
                "additionalProperties": false
            }
        },
        "elementTypes": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "handle": {
                        "type": "string"
                    },
                    "refHandle": {
                        "type": "string"
                    },
                    "displayName": {
                        "type": "string"
                    },
                    "class": {
                        "type": "string"
                    }
                },
                "required": [
                    "handle",
                    "displayName",
                    "class"
                ],
                "additionalProperties": false
            }
        },
        "skillPrompts": {
            "type": "array",
            "description": "Bundled cortex prompts that return authored Craft expertise. Invoke `prompts/get` with one of these names when authoring against Craft.",
            "items": {
                "type": "object",
                "properties": {
                    "name": {
                        "type": "string"
                    },
                    "description": {
                        "type": "string"
                    }
                },
                "required": [
                    "name",
                    "description"
                ],
                "additionalProperties": false
            }
        },
        "exec": {
            "type": "object",
            "properties": {
                "enabled": {
                    "type": "boolean"
                },
                "dryRunDefault": {
                    "type": "boolean"
                }
            },
            "required": [
                "enabled",
                "dryRunDefault"
            ],
            "additionalProperties": false
        },
        "allowlist": {
            "type": "array",
            "description": "Effective allowlist of command-route glob patterns the `craft_command` tool may dispatch. Union of project-config defaults plus active runtime overrides.",
            "items": {
                "type": "string"
            }
        },
        "hints": {
            "type": "array",
            "description": "Operating notes for the agent \u2014 when to invoke which prompts, which tools are stdio-only, etc.",
            "items": {
                "type": "string"
            }
        }
    },
    "required": [
        "craft",
        "sites",
        "sections",
        "elementTypes",
        "skillPrompts",
        "exec",
        "allowlist",
        "hints"
    ],
    "additionalProperties": false
}
```

## `sections`

List all sections, get a single section by handle, or count sections. Returns each section with its type (channel/structure/single), site settings, URI formats, propagation method, and number of entry types.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string",
            "description": "Section handle. Omit to list all sections."
        },
        "count": {
            "type": "boolean",
            "description": "Return only the count of matching sections."
        }
    },
    "additionalProperties": false
}
```

## `entry_types`

List all Craft 5 entry types, get a single one by handle, or count them. In Craft 5 entry types are decoupled from sections — they can be reused across sections, Matrix fields, and CKEditor nested entries. Single-handle mode returns the full field layout (tabs, fields per tab, conditions, and UI elements).

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string",
            "description": "Entry type handle. Omit to list all."
        },
        "count": {
            "type": "boolean",
            "description": "Return only the count."
        }
    },
    "additionalProperties": false
}
```

## `fields`

List all custom fields, get a single field by handle, get its usage map, or count fields. Returns each field with its type class, handle, instructions, translation method, and searchable flag. Use `mode: "usage"` with a handle to find which entry types and field layouts reference the field.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string",
            "description": "Field handle. Required for `mode: \"usage\"`."
        },
        "mode": {
            "type": "string",
            "description": "\"usage\" returns a usage map for the given handle.",
            "enum": [
                "default",
                "usage"
            ]
        },
        "count": {
            "type": "boolean",
            "description": "Return only the count."
        }
    },
    "additionalProperties": false
}
```

## `field_types`

List every registered Craft field type class — built-in plus plugin-provided. For each: the class FQN, display name, supported translation methods, whether multi-instance, and whether relational. Use this to discover what field types are available before creating a new field.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

## `category_groups`

List all category groups, get a single group by handle, or count them. Returns each group with its site settings, max levels, default placement, and field-layout summary.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string"
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `tag_groups`

List all tag groups, get a single group by handle, or count them. Returns each group with its field-layout summary.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string"
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `volumes_and_filesystems`

Combined map of asset volumes, filesystem instances, and registered filesystem type classes. Returns each volume with its filesystem details (type class, hasUrls, base URL), any filesystems not referenced by a volume, and the full registered type registry — built-in `craft\fs\Local` plus plugin-provided types (Servd, S3, GCS, Azure, etc.). Use the type registry to discover what storage backends are available before configuring a new filesystem.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

## `sites`

List all Craft sites with their groups, get a single site by handle, or count sites. Returns each site with language, primary flag, base URL, hasUrls, and the group it belongs to.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string"
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `image_transforms`

List named image transforms, get a single transform by handle, or count them. Returns each transform with its dimensions, mode, position, format, quality, interlace, fill, and upscale settings.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string"
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `element_types`

List every registered Craft element type — 8 core (Entry, Asset, Category, Tag, User, Address, GlobalSet, ContentBlock) plus plugin-provided. For each: class, displayName variants, refHandle, and capability flags (hasTitles, hasUris, hasStatuses, hasDrafts, isLocalized, trackChanges).

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

## `entries`

List, get, or count Craft entries with the full element-query surface: filter by section / type / status / author / related-to, search, eager-load relational fields with `with: [...]`, paginate via limit + offset, sort via orderBy, and use structure params (level, hasDescendants, leaves, descendantOf, etc.). Pass `id` for a single entry. Pass `count: true` to return a count instead of full results.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "id": {
            "description": "Single entry id, or array of ids."
        },
        "uid": {
            "description": "Single uid, or array of uids."
        },
        "slug": {
            "type": "string"
        },
        "title": {
            "type": "string"
        },
        "section": {
            "description": "Section handle, id, array, or `null` for sectionless (nested) entries."
        },
        "type": {
            "description": "Entry-type handle, id, or array."
        },
        "status": {
            "description": "Status string or array (live, pending, expired, disabled, \u2026)."
        },
        "enabled": {
            "type": "boolean"
        },
        "authorId": {
            "description": "Author user id, or array of ids."
        },
        "relatedTo": {
            "description": "Craft relation syntax: single id, array of ids, or hash {targetElement|sourceElement|field}. AND/OR also supported as nested arrays."
        },
        "search": {
            "type": "string"
        },
        "with": {
            "type": "array",
            "description": "Eager-loaded relational field handles. Without this, relational fields appear as `{loaded: false}` stubs to prevent N+1.",
            "items": {
                "type": "string"
            }
        },
        "orderBy": {
            "type": "string"
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        },
        "before": {
            "description": "postDate < this value (Craft date string)."
        },
        "after": {
            "description": "postDate >= this value."
        },
        "level": {
            "description": "Structure level (int or comparison string like \">2\")."
        },
        "hasDescendants": {
            "type": "boolean"
        },
        "leaves": {
            "type": "boolean"
        },
        "descendantOf": {
            "description": "Element id or instance."
        },
        "ancestorOf": {
            "description": "Element id or instance."
        },
        "siblingOf": {
            "description": "Element id or instance."
        },
        "site": {
            "description": "Site handle, id, or \"*\" for all sites."
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `assets`

List, get, or count Craft assets, or with `mode: "folders"` get the folder tree for a volume. Filter by volume, folder, kind, filename, search, relatedTo. Eager-load relational fields with `with: [...]`. Returns assets with filename, kind, size, dimensions, url, alt, focalPoint, and custom fields.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "enum": [
                "default",
                "folders"
            ]
        },
        "volume": {
            "description": "Volume handle, id, or array."
        },
        "folderId": {
            "description": "Folder id or array of ids."
        },
        "kind": {
            "description": "Asset kind: image, video, audio, json, pdf, text, \u2026"
        },
        "id": {
            "description": "Single id or array of ids."
        },
        "uid": {
            "description": "Single uid or array."
        },
        "filename": {
            "type": "string"
        },
        "title": {
            "type": "string"
        },
        "relatedTo": {
            "description": "Craft relation syntax."
        },
        "search": {
            "type": "string"
        },
        "with": {
            "type": "array",
            "items": {
                "type": "string"
            }
        },
        "orderBy": {
            "type": "string"
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        },
        "site": {
            "description": "Site handle, id, or \"*\"."
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `categories`

List, get, or count Craft categories. Supports group filter, structure params (level, hasDescendants, leaves, descendantOf, ancestorOf, siblingOf), relatedTo, search, eager loading via `with: [...]`, and pagination.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "id": {
            "description": "Single id or array."
        },
        "uid": {
            "description": "Single uid or array."
        },
        "slug": {
            "type": "string"
        },
        "title": {
            "type": "string"
        },
        "group": {
            "description": "Group handle, id, or array."
        },
        "status": {
            "description": "Status string or array."
        },
        "enabled": {
            "type": "boolean"
        },
        "level": {
            "description": "Structure level (int or comparison string)."
        },
        "hasDescendants": {
            "type": "boolean"
        },
        "leaves": {
            "type": "boolean"
        },
        "descendantOf": {
            "description": "Element id or instance."
        },
        "ancestorOf": {
            "description": "Element id or instance."
        },
        "siblingOf": {
            "description": "Element id or instance."
        },
        "relatedTo": {
            "description": "Craft relation syntax."
        },
        "search": {
            "type": "string"
        },
        "with": {
            "type": "array",
            "items": {
                "type": "string"
            }
        },
        "orderBy": {
            "type": "string"
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        },
        "site": {
            "description": "Site handle, id, or \"*\"."
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `tags`

List, get, or count Craft tags. Filter by group, id, slug, title, status, relatedTo, search. Eager-load relational fields with `with: [...]`. Supports orderBy, limit, offset, site filter.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "id": {
            "description": "Single id or array."
        },
        "uid": {
            "description": "Single uid or array."
        },
        "slug": {
            "type": "string"
        },
        "title": {
            "type": "string"
        },
        "group": {
            "description": "Group handle, id, or array."
        },
        "status": {
            "description": "Status string or array."
        },
        "enabled": {
            "type": "boolean"
        },
        "relatedTo": {
            "description": "Craft relation syntax."
        },
        "search": {
            "type": "string"
        },
        "with": {
            "type": "array",
            "items": {
                "type": "string"
            }
        },
        "orderBy": {
            "type": "string"
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        },
        "site": {
            "description": "Site handle, id, or \"*\"."
        },
        "count": {
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

## `globals`

Read Craft global set field values. Pass `handle` for a single set, or omit for every set. Pass `site` (handle/id) to localise the read; defaults to the primary site. Eager-load relational fields with `with: [...]` to avoid N+1.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "handle": {
            "type": "string",
            "description": "Global set handle. Omit to read all."
        },
        "site": {
            "description": "Site handle or id. Defaults to primary."
        },
        "with": {
            "type": "array",
            "items": {
                "type": "string"
            }
        }
    },
    "additionalProperties": false
}
```

## `system_info`

Snapshot of the running Craft install: version, edition, schema version, environment, devMode, PHP version, database driver/version, site count, maintenance flag, and license state. No parameters.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

**Output schema:**

```json
{
    "type": "object",
    "properties": {
        "craft": {
            "type": "object",
            "properties": {
                "version": {
                    "type": "string"
                },
                "edition": {
                    "type": "string",
                    "description": "Solo / Team / Pro / Enterprise."
                },
                "editionId": {
                    "type": "integer"
                },
                "schemaVersion": {
                    "type": "string"
                },
                "fieldVersion": {
                    "type": "string"
                },
                "maintenance": {
                    "type": "boolean"
                },
                "devMode": {
                    "type": "boolean"
                },
                "environment": {
                    "type": "string"
                },
                "isInstalled": {
                    "type": "boolean"
                },
                "systemName": {
                    "type": "string"
                },
                "systemUid": {
                    "type": "string"
                }
            },
            "required": [
                "version",
                "edition",
                "editionId",
                "schemaVersion",
                "fieldVersion",
                "maintenance",
                "devMode",
                "environment",
                "isInstalled",
                "systemName",
                "systemUid"
            ],
            "additionalProperties": false
        },
        "php": {
            "type": "object",
            "properties": {
                "version": {
                    "type": "string"
                },
                "sapi": {
                    "type": "string"
                },
                "memoryLimit": {
                    "type": "string"
                },
                "maxExecutionTime": {
                    "type": "string"
                },
                "timezone": {
                    "type": "string"
                }
            },
            "required": [
                "version",
                "sapi",
                "memoryLimit",
                "maxExecutionTime",
                "timezone"
            ],
            "additionalProperties": false
        },
        "db": {
            "type": "object",
            "properties": {
                "driver": {
                    "type": "string"
                },
                "serverVersion": {
                    "type": "string"
                },
                "isMysql": {
                    "type": "boolean"
                },
                "isPgsql": {
                    "type": "boolean"
                }
            },
            "required": [
                "driver",
                "serverVersion",
                "isMysql",
                "isPgsql"
            ],
            "additionalProperties": false
        },
        "sites": {
            "type": "object",
            "properties": {
                "count": {
                    "type": "integer"
                },
                "primarySiteHandle": {
                    "type": "string"
                }
            },
            "required": [
                "count",
                "primarySiteHandle"
            ],
            "additionalProperties": false
        },
        "license": {
            "type": "object",
            "properties": {
                "status": {
                    "type": "string"
                }
            },
            "required": [
                "status"
            ],
            "additionalProperties": false
        }
    },
    "required": [
        "craft",
        "php",
        "db",
        "sites",
        "license"
    ],
    "additionalProperties": false
}
```

## `config`

Read curated Craft configuration. Modes: `general` (whitelisted GeneralConfig fields), `custom` (config/custom.php), `db` (host/name/driver/port — never user/password), `email` (transport adapter from project config), `system_messages` (system email message keys). All output is secrets-redacted.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Required.",
            "enum": [
                "general",
                "custom",
                "db",
                "email",
                "system_messages"
            ]
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `plugins`

List every installed plugin (enabled or not). Returns handle, name, version, edition, developer, schemaVersion, enabled flag, and license status. Use to discover what extension surface the project has before recommending an approach.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

**Output schema:**

```json
{
    "type": "object",
    "properties": {
        "plugins": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "handle": {
                        "type": "string"
                    },
                    "name": {
                        "type": "string"
                    },
                    "version": {
                        "type": "string"
                    },
                    "schemaVersion": {
                        "type": "string"
                    },
                    "edition": {
                        "type": "string"
                    },
                    "hasMultipleEditions": {
                        "type": "boolean"
                    },
                    "developer": {
                        "type": "string"
                    },
                    "developerUrl": {
                        "type": "string"
                    },
                    "documentationUrl": {
                        "type": "string"
                    },
                    "description": {
                        "type": "string"
                    },
                    "isInstalled": {
                        "type": "boolean"
                    },
                    "isEnabled": {
                        "type": "boolean"
                    },
                    "moduleId": {
                        "type": "string"
                    },
                    "licenseKeyStatus": {
                        "type": "string"
                    },
                    "licenseIssues": {
                        "type": "array",
                        "items": []
                    }
                },
                "required": [
                    "handle",
                    "hasMultipleEditions",
                    "isInstalled",
                    "isEnabled",
                    "licenseIssues"
                ],
                "additionalProperties": false
            }
        },
        "count": {
            "type": "integer"
        },
        "enabledCount": {
            "type": "integer"
        }
    },
    "required": [
        "plugins",
        "count",
        "enabledCount"
    ],
    "additionalProperties": false
}
```

## `routes`

Combined route map: config-file routes (config/routes.php), project-config routes (admin-edited), section URI formats per site, and category-group URIs per site. Tells you what URL patterns exist on the install and where to add a new one.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

**Output schema:**

```json
{
    "type": "object",
    "properties": {
        "configFileRoutes": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "pattern": {
                        "type": "string"
                    },
                    "target": []
                },
                "required": [
                    "pattern",
                    "target"
                ],
                "additionalProperties": false
            }
        },
        "projectConfigRoutes": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "pattern": {
                        "type": "string"
                    },
                    "target": []
                },
                "required": [
                    "pattern",
                    "target"
                ],
                "additionalProperties": false
            }
        },
        "sectionRoutes": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "sectionHandle": {
                        "type": "string"
                    },
                    "sectionType": {
                        "type": "string"
                    },
                    "siteId": {
                        "type": "integer"
                    },
                    "siteHandle": {
                        "type": "string"
                    },
                    "uriFormat": {
                        "type": "string"
                    },
                    "template": {
                        "type": "string"
                    }
                },
                "required": [
                    "sectionHandle",
                    "sectionType",
                    "siteId"
                ],
                "additionalProperties": false
            }
        },
        "categoryGroupRoutes": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "groupHandle": {
                        "type": "string"
                    },
                    "siteId": {
                        "type": "integer"
                    },
                    "siteHandle": {
                        "type": "string"
                    },
                    "uriFormat": {
                        "type": "string"
                    },
                    "template": {
                        "type": "string"
                    }
                },
                "required": [
                    "groupHandle",
                    "siteId"
                ],
                "additionalProperties": false
            }
        },
        "siteCount": {
            "type": "integer"
        }
    },
    "required": [
        "configFileRoutes",
        "projectConfigRoutes",
        "sectionRoutes",
        "categoryGroupRoutes",
        "siteCount"
    ],
    "additionalProperties": false
}
```

## `system_diagnostics`

Combined diagnostics surface: logs, last_error, deprecations, queue jobs, and project_config_diff. Pick one via `type`. Returns at most `limit` items (default 50, max 500).

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "type": {
            "type": "string",
            "description": "Required.",
            "enum": [
                "logs",
                "last_error",
                "deprecations",
                "queue",
                "project_config_diff"
            ]
        },
        "channel": {
            "type": "string",
            "description": "Log file basename (e.g. \"web\", \"queue\"). Defaults to \"web\"."
        },
        "minLevel": {
            "type": "string",
            "description": "Minimum severity to include. Defaults to \"warning\".",
            "enum": [
                "trace",
                "info",
                "warning",
                "error"
            ]
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 500
        }
    },
    "required": [
        "type"
    ],
    "additionalProperties": false
}
```

## `database_schema`

Read the database schema: tables, columns (type / nullable / default), primary keys, indexes, foreign keys (with onDelete/onUpdate). Pass `tables: ["x", "y"]` to scope to specific tables, or `mode: "list"` for a names-only summary. Never executes data queries.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "enum": [
                "default",
                "list"
            ]
        },
        "tables": {
            "type": "array",
            "description": "Limit output to these table names (with or without prefix).",
            "items": {
                "type": "string"
            }
        }
    },
    "additionalProperties": false
}
```

## `extensibility`

Inventory of registered extensibility points: class-level events, Twig extensions (functions, filters, custom craft.* variables), CP utilities, and console commands. Pass `mode: "events" | "twig" | "utilities" | "commands"` for one section, or omit for the full map.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Limit to one section. Omit for all.",
            "enum": [
                "events",
                "twig",
                "utilities",
                "commands"
            ]
        }
    },
    "additionalProperties": false
}
```

## `permissions_and_groups`

The full Craft permissions tree (built-in + plugin-registered) and the user-group structure with each group's assigned permissions. Use to discover what permission identifiers exist and how groups carve up access. Returns no user data — membership is Pro-tier with PII gating.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {},
    "additionalProperties": false
}
```

**Output schema:**

```json
{
    "type": "object",
    "properties": {
        "permissions": {
            "type": "object",
            "description": "Recursive permissions tree keyed by category name.",
            "properties": {},
            "additionalProperties": true
        },
        "permissionCount": {
            "type": "integer"
        },
        "permissionNames": {
            "type": "array",
            "items": {
                "type": "string"
            }
        },
        "groups": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {
                        "type": "integer"
                    },
                    "uid": {
                        "type": "string"
                    },
                    "name": {
                        "type": "string"
                    },
                    "handle": {
                        "type": "string"
                    },
                    "description": {
                        "type": "string"
                    },
                    "permissions": {
                        "type": "array",
                        "items": {
                            "type": "string"
                        }
                    }
                },
                "required": [
                    "id",
                    "uid",
                    "name",
                    "handle",
                    "permissions"
                ],
                "additionalProperties": false
            }
        },
        "groupCount": {
            "type": "integer"
        }
    },
    "required": [
        "permissions",
        "permissionCount",
        "permissionNames",
        "groups",
        "groupCount"
    ],
    "additionalProperties": false
}
```

## `search_skills`

Full-text search across the bundled craft-skills corpus — 8 skills, their reference deep-dives, and 5 Claude Code agents. `mode: "search"` (default) returns ranked matches with a snippet and the resource URI for follow-up reads; `mode: "topics"` enumerates the corpus without scoring. Filter `kind` to `skill` / `reference` / `agent` to narrow.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true
- `title`: Search Bundled Skills

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Optional. `search` (default) ranks documents; `topics` enumerates without scoring.",
            "enum": [
                "search",
                "topics"
            ]
        },
        "query": {
            "type": "string",
            "description": "Free-text query. Required for `search` mode; ignored for `topics`.",
            "examples": [
                "element save lifecycle",
                "matrix block field",
                "multi-site propagation"
            ]
        },
        "kind": {
            "type": "string",
            "description": "Optional filter. `skill` = router only; `reference` = deep dives; `agent` = Claude Code agents.",
            "enum": [
                "skill",
                "reference",
                "agent"
            ]
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 50
        }
    },
    "additionalProperties": false
}
```

## `graphql`

Read-only GraphQL introspection. Modes: `list_schemas` (id/name/uid/isPublic/scope summary), `get_sdl` (printed SDL — accepts `name` for a specific schema or omits it for the public schema), `list_tokens` (token metadata only — names, expiry, enabled flag, fingerprint; never the access-token value itself).

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Required.",
            "enum": [
                "list_schemas",
                "get_sdl",
                "list_tokens"
            ]
        },
        "name": {
            "type": "string",
            "description": "For `get_sdl`: schema name. Omit for the public schema."
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `clear_caches`

Clear Craft caches. `mode: "list"` returns available cache keys; `mode: "all"` (default) clears every registered cache; `mode: "<key>"` clears one specific cache (e.g. data / asset / compiled-templates / compiled-classes / cp-resources / temp-files / transform-indexes / asset-indexing-data, plus any plugin-registered keys).

**Annotations:**

- `idempotentHint`: true
- `title`: Clear Caches

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Optional. `list`, `all` (default), or a specific cache key."
        }
    },
    "additionalProperties": false
}
```

## `resave`

Re-save elements through Craft's resave commands. Required `type`: entries / assets / categories / tags / users / addresses. Optional filters: section, entryType, group, volume, status, limit. Optional rewrite: pair `set` (field handle) with `to` (PHP value expression — see Craft's resave/--to documentation). `queue: true` dispatches as a background job; otherwise runs in-process. `updateSearchIndex` and `touch` mirror the corresponding CLI flags. Returns the dispatched route, exit code, captured output, and any error.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: true
- `openWorldHint`: false
- `title`: Resave Elements

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "type": {
            "type": "string",
            "description": "Element kind to resave. Required.",
            "enum": [
                "entries",
                "assets",
                "categories",
                "tags",
                "users",
                "addresses"
            ]
        },
        "section": {
            "type": "string",
            "description": "For type=entries. Comma-separated handles or `*` for all."
        },
        "entryType": {
            "type": "string",
            "description": "For type=entries. Comma-separated entry-type handles."
        },
        "group": {
            "type": "string",
            "description": "For type=categories|tags|users."
        },
        "volume": {
            "type": "string",
            "description": "For type=assets. Comma-separated volume handles."
        },
        "status": {
            "type": "string",
            "description": "Element status filter (default `any`)."
        },
        "limit": {
            "type": "integer",
            "minimum": 1
        },
        "set": {
            "type": "string",
            "description": "Field handle to rewrite. Pair with `to`."
        },
        "to": {
            "type": "string",
            "description": "Replacement expression. See Craft resave docs."
        },
        "ifEmpty": {
            "type": "boolean"
        },
        "ifInvalid": {
            "type": "boolean"
        },
        "touch": {
            "type": "boolean"
        },
        "updateSearchIndex": {
            "type": "boolean"
        },
        "propagateTo": {
            "type": "string"
        },
        "queue": {
            "type": "boolean",
            "description": "Dispatch as a background queue job instead of running in-process."
        },
        "batchSize": {
            "type": "integer",
            "minimum": 1
        }
    },
    "required": [
        "type"
    ],
    "additionalProperties": false
}
```

## `craft_command`

Run an allowlisted Craft / Yii console command in-process. Required `command` is the route (e.g. `cache/flush-all`, `migrate/up`, `project-config/apply`, `resave/entries`). Optional `options` is an object whose keys map to the controller's public option properties (e.g. `{section: "news"}`). Use `mode: "list"` to see the active allowlist patterns. Returns the dispatched route, exit code, captured output, matched allowlist pattern, and any error.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `openWorldHint`: false
- `title`: Run Craft Command

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Optional. `list` returns the active allowlist; `run` (default) dispatches.",
            "enum": [
                "list",
                "run"
            ]
        },
        "command": {
            "type": "string",
            "description": "Console route to dispatch. Required when mode=run."
        },
        "options": {
            "type": "object",
            "description": "Object of CLI option overrides bound to the controller properties.",
            "properties": {},
            "additionalProperties": true
        }
    },
    "additionalProperties": false
}
```

## `craft_exec`

Evaluate a single PHP expression in the running Craft context — wraps Craft's ExecController. **stdio only.** Defaults to dry-run: pass `confirm: true` to actually evaluate. Destructive patterns (delete*, drop*, truncate*, Elements::deleteElement, migrate/down) additionally require `dangerous: true`. Result is JSON-serialised and secrets are redacted before return. Errors come back typed (parse_error / runtime_error) with a stack trace.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `openWorldHint`: false
- `title`: Evaluate Craft Expression
- `stdioOnly`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "expression": {
            "type": "string",
            "description": "PHP expression to evaluate. `<?php` prefix optional. Required."
        },
        "confirm": {
            "type": "boolean",
            "description": "Required to actually evaluate (otherwise returns dry-run analysis)."
        },
        "dangerous": {
            "type": "boolean",
            "description": "Required in addition to `confirm` for destructive expressions."
        }
    },
    "required": [
        "expression"
    ],
    "additionalProperties": false
}
```

## `drafts_and_revisions`

Inspect entry drafts and revisions. Modes: `list_drafts` lists drafts (optionally for a section, canonical entry, or creator); `list_revisions` lists revisions of a canonical entry id; `compare` returns a field-level diff between two entries (canonical / draft / revision in any combination). Read-only — apply/discard unlocks in Pro.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Required.",
            "enum": [
                "list_drafts",
                "list_revisions",
                "compare"
            ]
        },
        "section": {
            "type": "string",
            "description": "Section handle. Filters list_drafts."
        },
        "canonicalId": {
            "type": "integer",
            "description": "Canonical entry id. Required for list_revisions; optional filter for list_drafts."
        },
        "creatorId": {
            "type": "integer",
            "description": "User id of draft creator. Filters list_drafts."
        },
        "leftId": {
            "type": "integer",
            "description": "First entry/draft/revision id for `compare` mode."
        },
        "rightId": {
            "type": "integer",
            "description": "Second entry/draft/revision id for `compare` mode."
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 500
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `content_audit`

Read-only content health reports. Modes: `relations` lists broken relational references (target element missing or soft-deleted); `unused_assets` lists assets not referenced by any element field; `propagation` lists entries in multi-site sections that don't exist in every enabled site. Fix modes (delete broken relations, prune unused assets, force-propagate) unlock in Pro.

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Required.",
            "enum": [
                "relations",
                "unused_assets",
                "propagation"
            ]
        },
        "volume": {
            "type": "string",
            "description": "Volume handle filter for `unused_assets`."
        },
        "section": {
            "type": "string",
            "description": "Section handle filter for `propagation`."
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `import_export`

Export entries to a structured JSON envelope suitable for cross-environment sync. Modes: `export` returns the envelope. Filter by `section`, `id`, or `site`. The same envelope shape is consumed by the Pro `import` mode (which unlocks create / update / dry-run import behind save permissions).

**Annotations:**

- `readOnlyHint`: true
- `idempotentHint`: true

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Required. Pro adds `import`.",
            "enum": [
                "export"
            ]
        },
        "section": {
            "type": "string",
            "description": "Section handle filter."
        },
        "id": {
            "description": "Single entry id or array of ids. Overrides section filter."
        },
        "site": {
            "type": "string",
            "description": "Site handle. Defaults to primary site."
        },
        "limit": {
            "type": "integer",
            "minimum": 1,
            "maximum": 1000
        },
        "offset": {
            "type": "integer",
            "minimum": 0
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

