# Agent task: move Watch's form saves off core

Self-contained brief for a coding agent. Work in this repository (`Module_Watchfolder`).
The core repository is `../XC_VM`; read it for reference, but change it only in the phase
that says so.

## Why

Core still knows this module by name in `src/Public/Views/admin/post.php`:

- it imports `XcVm\Module\Watch\WatchService`;
- `case 'settings_watch'` calls `WatchService::editWatchSettings($rData)`;
- `case 'watch_add'` calls `WatchService::processWatchFolder($rData)`.

DVR recordings are not in scope: they moved into core (`Domain\Stream\RecordingService`)
with module 1.1.0, so `case 'record'` and the Record page stay in core.

Core must not depend on a module. The module already owns its other admin endpoints
through `$router->api(...)` in `WatchModule::registerRoutes()` (`enable_watch`,
`watch_output`, `watch_clear_logs`, …). Move these the same way.

## Current contract (keep it)

| Action | Handler | Success response | Failure response |
| --- | --- | --- | --- |
| `settings_watch` | `WatchService::editWatchSettings($data)` | `{"result":true,"location":"settings_watch?status=<n>","status":<n>}` | `{"result":false,"data":…,"status":<n>}` |
| `watch_add` | `WatchService::processWatchFolder($data)` | `{"result":true,"location":"watch?status=<n>","status":<n>}` | same shape |

`$data` is the posted form (`RequestManager::getAll()`). Callers:

- `views/settings_watch_scripts.php`: `fetch('post.php?action=settings_watch', …)`;
- `views/watch_add_scripts.php`: `post.php?action=watch_add`.

## Phase 1: settings and folders (this repo)

1. In `WatchController`, add `apiSaveSettings()` and `apiSaveFolder()`. Each one:
   - **refuses anything but POST**: answer `405` with
     `{"result":false,"status":0,"error":"Method Not Allowed"}`, as `post.php` does.
     State changes must never run from a GET (CSRF via `<img src>`);
   - calls the same service method with `RequestManager::getAll()`;
   - echoes the same JSON as the table above, then `exit()`.
2. Register them in `WatchModule::registerRoutes()`:
   - `$router->api('settings_watch_save', …, ['permission' => ['adv', 'folder_watch_settings']]);`
   - `$router->api('watch_folder_save', …, ['permission' => ['adv', 'folder_watch_add']]);`

   Use new action names: core keeps the old ones until phase 2, and a route collision is
   refused silently.
3. Point the views at `./api?action=settings_watch_save` / `./api?action=watch_folder_save`
   (same `fetch` options, POST `FormData`, `X-Requested-With: XMLHttpRequest`).
4. Bump `module.json` `version` (minor) and set `requires_core` to the core version that
   ships phase 2, or leave it until that version is known. Note it in `RELEASE.md` /
   README.
5. Verify:
   - `php -l` on every changed file, plus the module's `tests/`;
   - in a panel: save Watch settings and add a folder. Success redirects as before, and a
     failure shows the error toast;
   - a GET to each new action answers 405 and changes nothing;
   - an admin without the permission gets `{"result":false}`.

## Phase 2: core (`../XC_VM`, separate PR, only after phase 1 is released)

1. Remove `case 'settings_watch'`, `case 'watch_add'` and the `WatchService` import
   from `src/Public/Views/admin/post.php`.
   Drop `settings_watch` and `watch_add` from `PageAuthorization::MODULE_POST_ACTIONS`
   (`src/Core/Auth/PageAuthorization.php`), which holds them to the module's
   permissions until then.
2. Coordinate with the Plex module's matching task
   (`Module_Plex/docs/agents/migrate-post-actions.md`): once both have moved,
   `post.php` has no module imports left.
3. Run `make gates`, `make phpstan`, the unit suite, and the CRAP gate (see core `CLAUDE.md`).

## Rules

- English code comments, only where they explain why.
- Commit with Conventional Commits. Do not push unless the user asks.
- Don't change the JSON contract. The views and any user scripts rely on it.
