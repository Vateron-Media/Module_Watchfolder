# Agent task: move Watch's form saves (and the Record page) off core

Self-contained brief for a coding agent. Work in this repository (`Module_Watchfolder`).
The core repository is `../XC_VM`; read it for reference, but change it only in the phase
that says so.

## Why

Core still knows this module by name in `src/Public/Views/admin/post.php`:

- it imports `XcVm\Module\Watch\WatchService` and `XcVm\Module\Watch\RecordingService`;
- `case 'settings_watch'` calls `WatchService::editWatchSettings($rData)`;
- `case 'watch_add'` calls `WatchService::processWatchFolder($rData)`;
- `case 'record'` calls `RecordingService::schedule($rData)`, then dispatches
  `StreamsChangedEvent([stream_id])`.

The **Record page itself is core**: `src/Public/Views/admin/record.php` (opened from
`archive.php`, `stream_view.php` and `epg_view.php` via `record?…`) posts to
`post.php?action=record`, and its page rule lives in core's
`PageAuthorization::checkPermissions()` (`case 'record'` → `add_movie`). Without this
module the page is dead.

Core must not depend on a module. The module already owns its other admin endpoints
through `$router->api(...)` in `WatchModule::registerRoutes()` (`enable_watch`,
`watch_output`, `watch_clear_logs`, …). Move these the same way.

## Current contract (keep it)

| Action | Handler | Success response | Failure response |
| --- | --- | --- | --- |
| `settings_watch` | `WatchService::editWatchSettings($data)` | `{"result":true,"location":"settings_watch?status=<n>","status":<n>}` | `{"result":false,"data":…,"status":<n>}` |
| `watch_add` | `WatchService::processWatchFolder($data)` | `{"result":true,"location":"watch?status=<n>","status":<n>}` | same shape |
| `record` | `RecordingService::schedule($data)` + `StreamsChangedEvent([(int) $data['stream_id']])` | `{"result":true,"location":"archive?status=<n>","status":<n>}` | same shape |

`$data` is the posted form (`RequestManager::getAll()`). Callers:

- `views/settings_watch_scripts.php`: `fetch('post.php?action=settings_watch', …)`;
- `views/watch_add_scripts.php`: `post.php?action=watch_add`;
- core `src/Public/Views/admin/record.php`: `fetch('post.php?action=record', …)`.

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

   Use new action names: core keeps the old ones until phase 3, and a route collision is
   refused silently.
3. Point the views at `./api?action=settings_watch_save` / `./api?action=watch_folder_save`
   (same `fetch` options, POST `FormData`, `X-Requested-With: XMLHttpRequest`).

## Phase 2: the Record page (this repo)

1. Copy core's `src/Public/Views/admin/record.php` into `views/record.php`, and serve it
   from a module route, `$router->get('record', [WatchController::class, 'record'], ['permission' => ['adv', 'add_movie']])`,
   rendered the way the module's other pages are (`renderUnifiedLayoutHeader/Footer`).
   The permission matches core's current page rule. Keep the URL `record?…` so the links
   in `archive.php`, `stream_view.php` and `epg_view.php` keep working. Check that core's
   router lets a module own a path core still serves; if core wins, keep the core page
   until phase 3 and only move the save.
2. Add `apiSchedule()`: POST-only as above, it calls `RecordingService::schedule()`, then
   `EventDispatcher::dispatch(new StreamsChangedEvent([(int) ($data['stream_id'] ?? 0)]))`
   (a recording scheduled on a node rides on the stream's record) and echoes the table's
   JSON. Register it as `$router->api('record_schedule', …, ['permission' => ['adv', 'add_movie']])`,
   and point the view's `fetch` at `./api?action=record_schedule`.
3. Bump `module.json` `version` (minor) and set `requires_core` to the core version that
   ships phase 3, or leave it until that version is known. Note it in `RELEASE.md` /
   README.
4. Verify:
   - `php -l` on every changed file, plus the module's `tests/`;
   - in a panel: save Watch settings, add a folder, and schedule a recording from
     Archive, Stream View and the EPG view. Success redirects as before, and a failure
     shows the error toast;
   - a GET to each new action answers 405 and changes nothing;
   - an admin without the permission gets `{"result":false}`.

## Phase 3: core (`../XC_VM`, separate PR, only after phases 1–2 are released)

1. Remove `case 'settings_watch'`, `case 'watch_add'`, `case 'record'` and the
   `WatchService` / `RecordingService` imports from `src/Public/Views/admin/post.php`.
   Drop `settings_watch` and `watch_add` from `PageAuthorization::MODULE_POST_ACTIONS`
   (`src/Core/Auth/PageAuthorization.php`), which holds them to the module's
   permissions until then.
2. Remove core's `src/Public/Views/admin/record.php` and its route (if any), and drop
   `case 'record'` from `PageAuthorization::checkPermissions()` if the module owns the
   page now. Keep the `record?…` links in the core views; they now land on the module's
   page, and they should be hidden when the module isn't installed.
3. Coordinate with the Plex module's matching task
   (`Module_Plex/docs/agents/migrate-post-actions.md`): once both have moved,
   `post.php` has no module imports left.
4. Run `make gates`, `make phpstan`, the unit suite, and the CRAP gate (see core `CLAUDE.md`).

## Rules

- English code comments, only where they explain why.
- Commit with Conventional Commits. Do not push unless the user asks.
- Don't change the JSON contract. The views and any user scripts rely on it.
