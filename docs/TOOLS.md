# Herald Tools

Auto-generated reference for the herald MCP tool surface. Run
`ddev craft herald/docs/tools` to refresh.

Every tool herald registers is documented and labelled Free or Pro,
on any edition. `search_skills` names the size of the bundled skills
corpus in its description, so that one description moves with the
corpus version below.

- **Total tools:** 42 (33 Free, 9 Pro)
- **Skills corpus:** `v1.6.2` (michtio/craftcms-claude-skills)
- **Generated:** 2026-08-03T18:25:32+00:00

## `get_initial_context`

**Edition:** Free

Bootstrap snapshot for an AI agent picking up a fresh conversation against this Craft install. Returns Craft version + edition + environment, the primary site handle, a thin sites/sections/element-types index, the bundled herald skill prompts (the moat content the LLM should consult when authoring against Craft), the `craft_exec` posture, and the effective command allowlist. Call this first — it replaces three or four orientation tool calls with one.

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
        "herald": {
            "type": "object",
            "description": "The active Herald edition (`free` or `pro`). Pro adds the write tools, the Skill element, and the Streamable HTTP transport; on `free`, plan around the read-only stdio surface.",
            "properties": {
                "edition": {
                    "type": "string"
                }
            },
            "required": [
                "edition"
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
            "description": "Bundled herald prompts that return authored Craft expertise. Invoke `prompts/get` with one of these names when authoring against Craft.",
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
        "herald",
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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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
            "type": "string",
            "description": "Sort expression: `<field> [asc|desc]`, comma-separated for multiple fields. Sortable fields: id, uid, title, slug, uri, postDate, expiryDate, sectionId, typeId, enabled, dateCreated, dateUpdated. Structure sections are returned in structure order when no sort is given."
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

**Edition:** Free

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
            "type": "string",
            "description": "Sort expression: `<field> [asc|desc]`, comma-separated for multiple fields. Sortable fields: id, uid, title, filename, kind, size, width, height, volumeId, folderId, dateModified, enabled, dateCreated, dateUpdated."
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

**Edition:** Free

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
            "type": "string",
            "description": "Sort expression: `<field> [asc|desc]`, comma-separated for multiple fields. Sortable fields: id, uid, title, slug, uri, groupId, enabled, dateCreated, dateUpdated. Categories are returned in structure order when no sort is given."
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

**Edition:** Free

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
            "type": "string",
            "description": "Sort expression: `<field> [asc|desc]`, comma-separated for multiple fields. Sortable fields: id, uid, title, slug, groupId, enabled, dateCreated, dateUpdated."
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

**Edition:** Free

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

## `entry`

**Edition:** Pro

Write tool for Craft entries. Modes: create / update / delete / restore / apply_draft. Per-section permissions required: saveEntries:{sectionUid} for create / update / restore / apply_draft, deleteEntries:{sectionUid} for delete. Returns the serialised entry on success; on field-level validation failure returns {success: false, errors: {handle: [messages]}, mode, id} rather than throwing — the LLM iterates on field values until they validate. Throws only for permission denial, missing arguments, mode misuse, or entry-not-found. Pass `fields: {handle: value}`; Craft normalises per field type at save time. `idempotencyKey` (create / update only) caches the result for 24h so safe retries don't double-save. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Entry — create / update / delete / restore / apply_draft

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "create",
                "update",
                "delete",
                "restore",
                "apply_draft"
            ]
        },
        "id": {
            "type": "integer",
            "description": "Element id. Required for update / delete / restore / apply_draft unless `uid` is supplied."
        },
        "uid": {
            "type": "string",
            "description": "Element uid. Alternative to `id` for update / delete / restore / apply_draft."
        },
        "siteId": {
            "type": "integer",
            "description": "Target site id. Defaults to the primary site."
        },
        "siteHandle": {
            "type": "string",
            "description": "Target site handle. Alternative to `siteId`."
        },
        "sectionId": {
            "type": "integer",
            "description": "Section id (create only \u2014 one of sectionId / sectionUid / sectionHandle required)."
        },
        "sectionUid": {
            "type": "string",
            "description": "Section uid (create only)."
        },
        "sectionHandle": {
            "type": "string",
            "description": "Section handle (create only)."
        },
        "entryTypeId": {
            "type": "integer",
            "description": "Entry-type id (create only \u2014 required when the section has multiple entry types)."
        },
        "entryTypeUid": {
            "type": "string",
            "description": "Entry-type uid (create only)."
        },
        "entryTypeHandle": {
            "type": "string",
            "description": "Entry-type handle (create only)."
        },
        "title": {
            "type": "string"
        },
        "slug": {
            "type": "string",
            "description": "Auto-generated by Craft if omitted on create."
        },
        "authorId": {
            "type": "integer",
            "description": "Author user id. Defaults to the bearer-token user on create."
        },
        "postDate": {
            "type": "string",
            "description": "ISO 8601 datetime. `null` allowed to clear.",
            "format": "date-time"
        },
        "expiryDate": {
            "type": "string",
            "description": "ISO 8601 datetime. `null` allowed to clear.",
            "format": "date-time"
        },
        "enabled": {
            "type": "boolean",
            "description": "Defaults to true on create."
        },
        "parentId": {
            "type": "integer",
            "description": "Structure parent id. Only valid in Structure sections."
        },
        "propagateTo": {
            "type": "array",
            "description": "Site ids or handles to propagate this entry to. Multi-site only.",
            "items": []
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to Entry::setFieldValues(); Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "hardDelete": {
            "type": "boolean",
            "description": "delete only: when true, removes the row entirely (no restore possible). Default false."
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `category`

**Edition:** Pro

Write tool for Craft categories. Modes: create / update / delete. Per-group permissions required: saveCategories:{groupUid} for create / update, deleteCategories:{groupUid} for delete. Returns the serialised category on success; on field-level validation failure returns {success: false, errors: {handle: [messages]}, mode, id} rather than throwing — the LLM iterates on field values until they validate. Throws only for permission denial, missing arguments, mode misuse, or category-not-found. Pass `fields: {handle: value}`; Craft normalises per field type at save time. `parentId` must belong to the same group (cross-group parents are rejected via validation envelope). `idempotencyKey` (create / update only) caches the result for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Category — create / update / delete

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "create",
                "update",
                "delete"
            ]
        },
        "id": {
            "type": "integer",
            "description": "Element id. Required for update / delete unless `uid` is supplied."
        },
        "uid": {
            "type": "string",
            "description": "Element uid. Alternative to `id` for update / delete."
        },
        "siteId": {
            "type": "integer",
            "description": "Target site id. Defaults to the primary site."
        },
        "siteHandle": {
            "type": "string",
            "description": "Target site handle. Alternative to `siteId`."
        },
        "groupId": {
            "type": "integer",
            "description": "Category group id (create only \u2014 one of groupId / groupUid / groupHandle required)."
        },
        "groupUid": {
            "type": "string",
            "description": "Category group uid (create only)."
        },
        "groupHandle": {
            "type": "string",
            "description": "Category group handle (create only)."
        },
        "title": {
            "type": "string"
        },
        "slug": {
            "type": "string",
            "description": "Auto-generated by Craft if omitted on create."
        },
        "enabled": {
            "type": "boolean",
            "description": "Defaults to true on create."
        },
        "parentId": {
            "type": "integer",
            "description": "Structure parent id. Must belong to the same category group."
        },
        "propagateTo": {
            "type": "array",
            "description": "Site ids or handles to propagate this category to. Multi-site only.",
            "items": []
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to Category::setFieldValues(); Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "hardDelete": {
            "type": "boolean",
            "description": "delete only: when true, removes the row entirely (no restore possible). Default false."
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `tag`

**Edition:** Pro

Write tool for Craft tags. Modes: create / update / delete. Admin only — Craft 5 has no per-tag-group permission, so tag management is gated to admin users. Returns the serialised tag on success; on field-level validation failure returns {success: false, errors: {handle: [messages]}, mode, id} rather than throwing. Throws only for permission denial, missing arguments, mode misuse, or tag-not-found. Pass `fields: {handle: value}`; Craft normalises per field type at save time. `idempotencyKey` (create / update only) caches the result for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Tag — create / update / delete (admin only)

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "create",
                "update",
                "delete"
            ]
        },
        "id": {
            "type": "integer",
            "description": "Element id. Required for update / delete unless `uid` is supplied."
        },
        "uid": {
            "type": "string",
            "description": "Element uid. Alternative to `id` for update / delete."
        },
        "siteId": {
            "type": "integer",
            "description": "Target site id. Defaults to the primary site."
        },
        "siteHandle": {
            "type": "string",
            "description": "Target site handle. Alternative to `siteId`."
        },
        "groupId": {
            "type": "integer",
            "description": "Tag group id (create only \u2014 one of groupId / groupUid / groupHandle required)."
        },
        "groupUid": {
            "type": "string",
            "description": "Tag group uid (create only)."
        },
        "groupHandle": {
            "type": "string",
            "description": "Tag group handle (create only)."
        },
        "title": {
            "type": "string"
        },
        "slug": {
            "type": "string",
            "description": "Auto-generated by Craft if omitted on create."
        },
        "enabled": {
            "type": "boolean",
            "description": "Defaults to true on create."
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to Tag::setFieldValues(); Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "hardDelete": {
            "type": "boolean",
            "description": "delete only: when true, removes the row entirely (no restore possible). Default false."
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `global_set`

**Edition:** Pro

Write tool for Craft global sets — updates field values on an existing set. Global sets are created / deleted in project config, not at runtime; this tool only mutates field values. Permission required: editGlobalSet:{globalSetUid}. Identify the set by `handle` (most common), `id`, or `uid`. Returns the serialised set on success; on validation failure returns {success: false, errors: {handle: [messages]}, mode, id}. The `mode` field defaults to `update` (the only allowed value today; reserved for forward compatibility). `idempotencyKey` caches the result for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Global Set — update field values

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform. Currently only `update` is supported.",
            "enum": [
                "update"
            ],
            "default": "update"
        },
        "id": {
            "type": "integer",
            "description": "Global set id. One of id / uid / handle required."
        },
        "uid": {
            "type": "string",
            "description": "Global set uid. Alternative to id / handle."
        },
        "handle": {
            "type": "string",
            "description": "Global set handle. Most common identifier \u2014 operators usually know the handle."
        },
        "siteId": {
            "type": "integer",
            "description": "Target site id. Defaults to the primary site."
        },
        "siteHandle": {
            "type": "string",
            "description": "Target site handle. Alternative to `siteId`."
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to GlobalSet::setFieldValues(); Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "idempotencyKey": {
            "type": "string",
            "description": "Server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "fields"
    ],
    "additionalProperties": false
}
```

## `address`

**Edition:** Pro

Write tool for Craft addresses. Modes: list / get / create / update / delete on user-owned addresses. Per-owner permission gating delegated to Craft's native Elements::canSave / canView / canDelete — for user-owned addresses this resolves to editUsers on the owner. countryCode is validated at execute-time against the ISO 3166-1 alpha-2 list — bad codes surface in the validation envelope (errors.countryCode), not as a thrown error, so the LLM iterates. Owner type: only `user` is supported — other owner types are rejected. Address fields (countryCode, addressLine1, locality, etc.) pass through to Craft's save validation, which honours the per-country required-field map. Returns the serialised address on success; on validation failure returns {success: false, errors: {handle: [messages]}, mode, id}. Throws only for permission denial, missing arguments, mode misuse, ownership-change attempts, or address-not-found. `idempotencyKey` (create / update only) caches the result for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Address — list / get / create / update / delete (user-owned)

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "list",
                "get",
                "create",
                "update",
                "delete"
            ]
        },
        "id": {
            "type": "integer",
            "description": "Address id. Required for get / update / delete unless `uid` is supplied."
        },
        "uid": {
            "type": "string",
            "description": "Address uid. Alternative to `id` for get / update / delete."
        },
        "ownerId": {
            "type": "integer",
            "description": "Owner id. Required for create + list. On update, ownership changes are rejected."
        },
        "ownerType": {
            "type": "string",
            "description": "Owner element type. Only `user` is supported; other owner types are rejected. Defaults to `user`.",
            "default": "user"
        },
        "siteId": {
            "type": "integer",
            "description": "Target site id. Defaults to the primary site."
        },
        "siteHandle": {
            "type": "string",
            "description": "Target site handle. Alternative to `siteId`."
        },
        "title": {
            "type": "string",
            "description": "Human-readable label for the address card (e.g. `Home`, `Work`). Subject to the field layout's `title` requirement."
        },
        "countryCode": {
            "type": "string",
            "description": "ISO 3166-1 alpha-2 country code (e.g. `US`, `BE`). Validated at execute-time. Required for create. Non-nullable on the underlying element \u2014 missing on save surfaces as a validation envelope."
        },
        "administrativeArea": {
            "type": "string",
            "description": "State / province / region (per country format)."
        },
        "locality": {
            "type": "string",
            "description": "City / town."
        },
        "dependentLocality": {
            "type": "string",
            "description": "Neighbourhood / district (per country format)."
        },
        "postalCode": {
            "type": "string",
            "description": "Postal / ZIP code."
        },
        "sortingCode": {
            "type": "string",
            "description": "Sorting code (per country format \u2014 rare)."
        },
        "addressLine1": {
            "type": "string",
            "description": "Street address line 1."
        },
        "addressLine2": {
            "type": "string",
            "description": "Street address line 2."
        },
        "addressLine3": {
            "type": "string",
            "description": "Street address line 3 (rare)."
        },
        "organization": {
            "type": "string",
            "description": "Organisation name."
        },
        "organizationTaxId": {
            "type": "string",
            "description": "Organisation tax id (when the OrganizationTaxIdField is in the field layout)."
        },
        "fullName": {
            "type": "string",
            "description": "Full name (single-field mode \u2014 default)."
        },
        "firstName": {
            "type": "string",
            "description": "First name (when `showFirstAndLastNameFields` is enabled). Read AddressInterface::getGivenName() maps here."
        },
        "lastName": {
            "type": "string",
            "description": "Last name (when `showFirstAndLastNameFields` is enabled). Read AddressInterface::getFamilyName() maps here."
        },
        "latitude": {
            "type": "string",
            "description": "Latitude in decimal degrees (-90 to 90). String to preserve precision."
        },
        "longitude": {
            "type": "string",
            "description": "Longitude in decimal degrees (-180 to 180). String to preserve precision."
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to Address::setFieldValues(); Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "limit": {
            "type": "integer",
            "description": "list mode: page size. Default 50, max 200.",
            "minimum": 1,
            "maximum": 200
        },
        "offset": {
            "type": "integer",
            "description": "list mode: offset. Default 0.",
            "minimum": 0
        },
        "hardDelete": {
            "type": "boolean",
            "description": "delete only: when true, removes the row entirely (no restore possible). Default false."
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `users`

**Edition:** Pro

Write tool for Craft users with PII gating. Modes: list / get / create / update / delete. Per-target permission gating delegated to Craft's native Elements::canSave / canView / canDelete plus a tool-layer admin-protection gate on update (User::canSave does NOT enforce non-admin-cannot-edit-admin natively — added here). Sensitive fields (email/username on non-self, active/suspended/pending/locked, newPassword on non-self) require administrateUsers. Admin promotion / demotion requires the caller is admin. Group assignments require assignUserGroup:{uid} on every newly added group; kept groups do not re-gate. PII fields (email, unverifiedEmail, lockout counters, passwordResetRequired) redacted per caller permission via _serializeUser — viewUsers callers get no email; editUsers callers get email + lockout; administrateUsers gets passwordResetRequired; admin gets all. Custom field values gated by Settings::$userCustomFieldAllowlist — handles NOT on the allowlist are NEVER returned, even for admin callers. Credentials (password, verificationCode, newPassword) NEVER returned. Returns the serialised user on success; on validation failure returns {success: false, errors: {handle: [messages]}, mode, id, uid}. Throws only for permission denial, missing arguments, mode misuse, admin-protection failures, or user-not-found. `idempotencyKey` (create / update only) caches the result for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Users — list / get / create / update / delete with PII gating

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "list",
                "get",
                "create",
                "update",
                "delete"
            ]
        },
        "id": {
            "type": "integer",
            "description": "User id. Resolves get / update / delete. First non-empty among id / uid / email / username wins."
        },
        "uid": {
            "type": "string",
            "description": "User uid. Alternative to `id`."
        },
        "email": {
            "type": "string",
            "description": "Email. On `create`: required. On `get`: alternative lookup key (case-insensitive on MySQL, case-sensitive on Postgres \u2014 delegates to Craft's `getUserByUsernameOrEmail`). On `update`: mutating value; requires administrateUsers for non-self."
        },
        "username": {
            "type": "string",
            "description": "Username. On `get`: alternative lookup key. On `create`/`update`: mutating value; falls back to email when `useEmailAsUsername` is set; non-self update requires administrateUsers."
        },
        "firstName": {
            "type": "string",
            "description": "Forename. Pass-through."
        },
        "lastName": {
            "type": "string",
            "description": "Surname. Pass-through."
        },
        "fullName": {
            "type": "string",
            "description": "Full name. When supplied alongside firstName/lastName, firstName/lastName wins per Craft's NameTrait semantics."
        },
        "admin": {
            "type": "boolean",
            "description": "Promote/demote admin status. Caller must be admin to set true OR false. On `create`, requires admin caller. On `update`, ANY change requires admin caller."
        },
        "active": {
            "type": "boolean",
            "description": "Activate user. Requires administrateUsers; without it, the field is silently ignored on `create` and refused on `update`. On `create` without administrateUsers, defaults to pending=true per Craft convention."
        },
        "suspended": {
            "type": "boolean",
            "description": "Suspend / unsuspend. Requires administrateUsers; silently ignored on `create` without it."
        },
        "pending": {
            "type": "boolean",
            "description": "Pending state. Requires administrateUsers; silently ignored on `create` without it (defaults to pending=true)."
        },
        "newPassword": {
            "type": "string",
            "description": "Set a new password. Update only; never returned. Self-edit allowed without administrateUsers (matches UsersController::actionSaveUser line 1703); non-self requires administrateUsers. Over the HTTP transport this field additionally requires elevation via the /oauth/elevate flow; stdio is implicitly elevated."
        },
        "groupUids": {
            "type": "array",
            "description": "Replacement list of group UIDs the user should belong to. Each ADDED group (not pre-existing) requires assignUserGroup:{uid} on the caller. Kept groups do not re-gate. Pass [] to clear all groups.",
            "items": {
                "type": "string"
            }
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to User::setFieldValues(); Craft normalises per field type. On return, only handles in Settings::$userCustomFieldAllowlist are serialised.",
            "properties": {},
            "additionalProperties": true
        },
        "transferContentTo": {
            "type": "integer",
            "description": "Delete only. User id who inherits the deleted user's authored entries (sets `inheritorOnDelete` which `User::afterDelete()` consumes). Caller needs deleteUsers on themselves; no extra permission against the recipient."
        },
        "hardDelete": {
            "type": "boolean",
            "description": "Delete only. When true, removes the row entirely (no restore possible). Default false."
        },
        "status": {
            "type": "string",
            "description": "List filter: one of `active`, `pending`, `suspended`, `locked`, `inactive`. Pass-through to UserQuery::status().",
            "enum": [
                "active",
                "pending",
                "suspended",
                "locked",
                "inactive"
            ]
        },
        "group": {
            "description": "List filter: group handle (string) or id (int). Pass-through to UserQuery::group()."
        },
        "can": {
            "type": "string",
            "description": "List filter: permission string. Returns users who hold this permission (admins always match). Pass-through to UserQuery::can()."
        },
        "search": {
            "type": "string",
            "description": "List filter: search query, matched against username/email/firstName/lastName via Craft's search service. Pass-through to UserQuery::search()."
        },
        "dateCreated": {
            "description": "List filter: date filter string with `>=`/`<=` operators (e.g. `>= 2024-01-01`). Pass-through to UserQuery::dateCreated()."
        },
        "limit": {
            "type": "integer",
            "description": "list mode: page size. Default 50, max 200.",
            "minimum": 1,
            "maximum": 200
        },
        "offset": {
            "type": "integer",
            "description": "list mode: offset. Default 0.",
            "minimum": 0
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving. Skipped on stdio.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `skill`

**Edition:** Pro

Write tool for Herald skill elements. Modes: list / get / create / update / delete. Skills are markdown documents addressable by handle; element-stored skills override bundled `michtio/craftcms-claude-skills` skills on handle collision (see locked decision 2 of Gate 8.6). list returns the merged corpus with a `source: bundled | element` field on every row; filter via source=bundled / element / all (default all). create requires handle + title; update accepts id / uid / handle but rejects handle changes (natural-key invariant). delete soft-deletes by default; hardDelete=true removes the row entirely and cascades the herald_skills FK. Permission: herald:manage-skills (global, no per-instance ACL). idempotencyKey caches the result for 24h.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Skill — list / get / create / update / delete (Herald skills)

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Operation to perform.",
            "enum": [
                "list",
                "get",
                "create",
                "update",
                "delete"
            ]
        },
        "id": {
            "type": "integer",
            "description": "Skill element id. get / update / delete (one of id / uid / handle)."
        },
        "uid": {
            "type": "string",
            "description": "Skill element uid. get / update / delete (alternative to id)."
        },
        "handle": {
            "type": "string",
            "description": "Skill handle (slug, globally unique). Required for create; one of id / uid / handle on get / update / delete."
        },
        "title": {
            "type": "string",
            "description": "Human label for the skill (maps to the bundled `name` frontmatter field). Required on create."
        },
        "description": {
            "type": "string",
            "description": "Short description. Native column; <= 4096 chars.",
            "maxLength": 4096
        },
        "body": {
            "type": "string",
            "description": "Markdown body content. Stored as the `body` field-layout value when present."
        },
        "fields": {
            "type": "object",
            "description": "Custom field values keyed by handle. Forwarded verbatim to `Element::setFieldValues()`; Craft normalises per field type.",
            "properties": {},
            "additionalProperties": true
        },
        "limit": {
            "type": "integer",
            "description": "list mode: page size. Default 50, max 200.",
            "minimum": 1,
            "maximum": 200
        },
        "offset": {
            "type": "integer",
            "description": "list mode: offset. Default 0.",
            "minimum": 0
        },
        "search": {
            "type": "string",
            "description": "list mode: substring filter applied to handle / title / body bytes."
        },
        "source": {
            "type": "string",
            "description": "list mode: filter by row provenance. Default `all`.",
            "enum": [
                "bundled",
                "element",
                "all"
            ]
        },
        "hardDelete": {
            "type": "boolean",
            "description": "delete only: when true, removes the row entirely (FK CASCADE wipes the herald_skills row). Default false."
        },
        "idempotencyKey": {
            "type": "string",
            "description": "create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.",
            "maxLength": 64
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `bulk_entries`

**Edition:** Pro

Bulk-mutate entries selected by an `EntryQuery`-shaped filter. Modes: set_status (toggle the `enabled` flag — set enabled or disabled; `pending` and `expired` are not status flips, drive postDate / expiryDate via update_fields instead) / update_fields (pass-through `fields: {handle: value}` to setFieldValues) / relate (apply replace / add / remove on a relation field) / migrate (move entries to a new section + entry type — defaults to dryRun:true). Permission: per-row `saveEntries:{sectionUid}`. Denied rows go to `skipped[]` by default; pass `onPermissionDenied: "fail"` to abort. Hard cap 10,000 rows per call; `force: true` to bypass. Streams `notifications/progress` frames every `progressInterval` rows (default 100). Returns a terminal envelope with per-row `results[]` ({kind: success / failure / skipped, id, …}). Cancellation-cooperative: the running stream short-circuits on `notifications/cancelled` or a TCP disconnect. `idempotencyKey` caches the whole-operation envelope for 24h (dry-run and commit are cached separately). Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Bulk Entries — query-driven set_status / update_fields / relate / migrate

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "mode": {
            "type": "string",
            "description": "Mutation mode.",
            "enum": [
                "set_status",
                "update_fields",
                "relate",
                "migrate"
            ]
        },
        "query": {
            "type": "object",
            "description": "Filter shape \u2014 at minimum one selector should be present, otherwise the cap-check rejects the unbounded query.",
            "properties": {
                "section": {
                    "type": "string",
                    "description": "Section handle (single)."
                },
                "entryType": {
                    "type": "string",
                    "description": "Entry-type handle (single)."
                },
                "status": {
                    "description": "Status filter \u2014 string or array of strings (live / pending / expired / disabled)."
                },
                "search": {
                    "type": "string",
                    "description": "Pass-through to ElementQuery::search()."
                },
                "ids": {
                    "type": "array",
                    "description": "Restrict to these element IDs.",
                    "items": {
                        "type": "integer"
                    }
                },
                "authorId": {
                    "type": "integer",
                    "description": "Author user id."
                },
                "dateCreated": {
                    "type": "object",
                    "description": "Range filter \u2014 ISO 8601 datetimes.",
                    "properties": {
                        "gte": {
                            "type": "string"
                        },
                        "lte": {
                            "type": "string"
                        }
                    },
                    "additionalProperties": false
                },
                "dateUpdated": {
                    "type": "object",
                    "description": "Range filter \u2014 ISO 8601 datetimes.",
                    "properties": {
                        "gte": {
                            "type": "string"
                        },
                        "lte": {
                            "type": "string"
                        }
                    },
                    "additionalProperties": false
                },
                "postDate": {
                    "type": "object",
                    "description": "Range filter \u2014 ISO 8601 datetimes.",
                    "properties": {
                        "gte": {
                            "type": "string"
                        },
                        "lte": {
                            "type": "string"
                        }
                    },
                    "additionalProperties": false
                },
                "expiryDate": {
                    "type": "object",
                    "description": "Range filter \u2014 ISO 8601 datetimes.",
                    "properties": {
                        "gte": {
                            "type": "string"
                        },
                        "lte": {
                            "type": "string"
                        }
                    },
                    "additionalProperties": false
                }
            },
            "additionalProperties": false
        },
        "siteId": {
            "description": "Site id (int), site handle (string), or `\"*\"` for all sites. Defaults to primary."
        },
        "status": {
            "type": "string",
            "description": "set_status only \u2014 new status for every visited row.",
            "enum": [
                "enabled",
                "disabled"
            ]
        },
        "fields": {
            "type": "object",
            "description": "update_fields only \u2014 `{handle: value}` map forwarded to setFieldValues.",
            "properties": {},
            "additionalProperties": true
        },
        "targetField": {
            "type": "string",
            "description": "relate only \u2014 relation field handle."
        },
        "targetIds": {
            "type": "array",
            "description": "relate only \u2014 element IDs to relate to.",
            "items": {
                "type": "integer"
            }
        },
        "mergeStrategy": {
            "type": "string",
            "description": "relate only \u2014 relation merge strategy. Defaults to `replace`.",
            "enum": [
                "replace",
                "add",
                "remove"
            ]
        },
        "toSectionUid": {
            "type": "string",
            "description": "migrate only \u2014 target section UID."
        },
        "toEntryTypeUid": {
            "type": "string",
            "description": "migrate only \u2014 target entry type UID."
        },
        "dryRun": {
            "type": "boolean",
            "description": "migrate only \u2014 preview without saving. Defaults to true."
        },
        "force": {
            "type": "boolean",
            "description": "Override the 10,000-row cap. Defaults to false."
        },
        "onPermissionDenied": {
            "type": "string",
            "description": "Behaviour on per-row permission denial. Defaults to `skip`.",
            "enum": [
                "skip",
                "fail"
            ]
        },
        "progressInterval": {
            "type": "integer",
            "description": "Yield a progress frame every N rows. Defaults to 100.",
            "minimum": 1
        },
        "idempotencyKey": {
            "type": "string",
            "description": "Whole-operation idempotency token. Same key within 24h returns the cached envelope. Dry-run and commit cached separately.",
            "maxLength": 64
        }
    },
    "required": [
        "mode",
        "query"
    ],
    "additionalProperties": false
}
```

## `scaffold_entries`

**Edition:** Pro

Bulk-create N entries from a deterministic template. `template.title` and `template.slug` accept `{n}` and `{n:0Nd}` substitution (1-indexed counter; zero-padded N digits). No Twig — the surface stays narrow and predictable. Permission: per-row `saveEntries:{sectionUid}`. Hard cap 10,000 per call; `force: true` to bypass. Streams `notifications/progress` frames every `progressInterval` rows (default 100). Returns a terminal envelope with per-row `results[]` ({kind: success / failure, id, …}). Cancellation-cooperative. `idempotencyKey` caches the envelope for 24h. Pro edition only.

**Annotations:**

- `destructiveHint`: true
- `idempotentHint`: false
- `title`: Scaffold Entries — template-driven bulk create

**Input schema:**

```json
{
    "type": "object",
    "properties": {
        "sectionUid": {
            "type": "string",
            "description": "Target section UID."
        },
        "entryTypeUid": {
            "type": "string",
            "description": "Target entry-type UID."
        },
        "count": {
            "type": "integer",
            "description": "Number of entries to create (1..10,000).",
            "minimum": 1,
            "maximum": 10000
        },
        "template": {
            "type": "object",
            "properties": {
                "title": {
                    "type": "string",
                    "description": "Title template. Accepts `{n}` / `{n:0Nd}` substitution."
                },
                "slug": {
                    "type": "string",
                    "description": "Optional slug template. Accepts the same substitution surface."
                },
                "status": {
                    "type": "string",
                    "description": "Initial status. Defaults to `enabled`.",
                    "enum": [
                        "enabled",
                        "disabled"
                    ]
                },
                "fields": {
                    "type": "object",
                    "description": "Custom-field values applied to every created entry.",
                    "properties": {},
                    "additionalProperties": true
                },
                "authorId": {
                    "type": "integer",
                    "description": "Author user id. Defaults to the dispatch user."
                }
            },
            "required": [
                "title"
            ],
            "additionalProperties": false
        },
        "siteId": {
            "description": "Site id, handle, or `\"*\"`. Defaults to primary."
        },
        "force": {
            "type": "boolean",
            "description": "Override the row cap. Defaults to false."
        },
        "progressInterval": {
            "type": "integer",
            "description": "Yield a progress frame every N rows. Defaults to 100.",
            "minimum": 1
        },
        "idempotencyKey": {
            "type": "string",
            "description": "Whole-operation idempotency token.",
            "maxLength": 64
        }
    },
    "required": [
        "sectionUid",
        "entryTypeUid",
        "count",
        "template"
    ],
    "additionalProperties": false
}
```

## `system_info`

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

Combined diagnostics surface: logs, last_error, deprecations, queue jobs, and project_config_diff. Pick one via `type`. Returns at most `limit` items (default 50, max 500). Pro adds `type=manage_queue` for retry/release actions gated on `utility:queue-manager`.

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
                "project_config_diff",
                "manage_queue"
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
        "action": {
            "type": "string",
            "description": "Required for `type=manage_queue`. `release` deletes the job; Craft has no `cancel()` \u2014 `release` IS the cancel action.",
            "enum": [
                "retry",
                "retry_all",
                "release",
                "release_all"
            ]
        },
        "jobId": {
            "type": "string",
            "description": "Queue row id (string). Required for `manage_queue` action=retry / release."
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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

Full-text search across the bundled craft-skills corpus — 10 skills, their reference deep-dives, and 6 Claude Code agents. `mode: "search"` (default) returns ranked matches with a snippet and the resource URI for follow-up reads; `mode: "topics"` enumerates the corpus without scoring. Filter `kind` to `skill` / `reference` / `agent` to narrow. Element-stored Herald skills override bundled ones by handle and are included in the merged corpus.

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

**Edition:** Free

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

**Edition:** Free

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

**Edition:** Free

Re-save elements through Craft's resave pipeline. Required `type`: entries / assets / categories / tags / users / addresses. Optional filters: section, entryType, group, volume, status, limit. `updateSearchIndex` and `touch` mirror the corresponding CLI flags. Returns a structured envelope {success, type, route, total, processed, succeeded, failed, cancelled, results[]} where `results[]` enumerates per-element failures (successes are counted but not listed). Streaming clients (Accept: text/event-stream) additionally get one `notifications/progress` frame per element (subject to a 60Hz wire-level throttle); non-streaming clients get the same terminal envelope without the intermediate frames. `queue: true` and the field-rewrite options (`set`, `to`, `ifEmpty`, `ifInvalid`, `propagateTo`, `toDefault`, `setEnabledForSite`) are not supported — use `craft_command` for raw controller access.

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
        "touch": {
            "type": "boolean"
        },
        "updateSearchIndex": {
            "type": "boolean"
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

**Edition:** Free

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

**Edition:** Free

Evaluate a single PHP expression in the running Craft context using PHP `eval` behind six layered security gates (same approach as Craft's ExecController, not a wrapper around it). **stdio only.** Defaults to dry-run: pass `confirm: true` to actually evaluate. Destructive patterns (delete*, drop*, truncate*, Elements::deleteElement, migrate/down) additionally require `dangerous: true`. Result is JSON-serialised and secrets are redacted before return. Errors come back typed (parse_error / runtime_error) with a stack trace.

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

**Edition:** Free

Inspect entry drafts and revisions. Read modes: `list_drafts`, `list_revisions`, `compare` (field-level diff). Pro modes: `apply` (merge a draft into its canonical) and `discard` (hard-delete the draft, canonical untouched). Pro modes require `saveEntries:{section}`.

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
                "compare",
                "apply",
                "discard"
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
        "id": {
            "type": "integer",
            "description": "Draft id for `apply` / `discard` modes."
        },
        "uid": {
            "type": "string",
            "description": "Draft uid for `apply` / `discard` modes."
        },
        "siteId": {
            "type": "integer",
            "description": "Site id for the draft lookup in `apply` / `discard`."
        },
        "siteHandle": {
            "type": "string",
            "description": "Site handle for the draft lookup in `apply` / `discard`."
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

**Edition:** Free

Content health reports. Read modes: `relations` lists broken relational references (target element missing or soft-deleted); `unused_assets` lists assets not referenced by any element field; `propagation` lists entries in multi-site sections that don't exist in every enabled site. Pro fix modes: `fix_relations` deletes the broken rows, `prune_unused_assets` hard-deletes unreferenced assets, `repair_propagation` force-resaves entries with missing per-site copies. Pro modes require `saveEntries:{section}` / `deleteAssets:{volume}` per row.

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
                "propagation",
                "fix_relations",
                "prune_unused_assets",
                "repair_propagation"
            ]
        },
        "volume": {
            "type": "string",
            "description": "Volume handle filter for `unused_assets` / `prune_unused_assets`."
        },
        "section": {
            "type": "string",
            "description": "Section handle filter for `propagation` / `repair_propagation`."
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
        "progressInterval": {
            "type": "integer",
            "description": "Streaming-only. Emit one `notifications/progress` frame every N rows processed (default 100). Ignored on non-streaming dispatch.",
            "minimum": 1
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

## `import_export`

**Edition:** Free

Export entries to a structured JSON envelope suitable for cross-environment sync. Free mode: `export` returns the envelope (filter by `section`, `id`, `site`). Pro mode: `import` consumes the same envelope, validates each entry against the target section's field layout, and saves create/update by uid. `import` defaults to `dryRun: true` — pass `dryRun: false` to commit. Per-item permission `saveEntries:{section}`.

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
                "export",
                "import"
            ]
        },
        "section": {
            "type": "string",
            "description": "Section handle filter (for `export`)."
        },
        "id": {
            "description": "Single entry id or array of ids. Overrides section filter (for `export`)."
        },
        "site": {
            "type": "string",
            "description": "Site handle. Defaults to primary site (for `export`)."
        },
        "siteHandle": {
            "type": "string",
            "description": "Site handle override for `import` \u2014 picks the per-entry site context if the payload's `site` is unknown to this install."
        },
        "payload": {
            "type": "object",
            "description": "Required for `import`. The envelope shape returned by `export`: `{format, entries: [...]}`.",
            "properties": {},
            "additionalProperties": true
        },
        "dryRun": {
            "type": "boolean",
            "description": "Defaults to `true` for `import`. Pass `false` to actually write. Validates against the target section's field layout either way."
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
        "progressInterval": {
            "type": "integer",
            "description": "Streaming-only. Emit one `notifications/progress` frame every N items processed (default 100). Ignored on non-streaming dispatch.",
            "minimum": 1
        }
    },
    "required": [
        "mode"
    ],
    "additionalProperties": false
}
```

