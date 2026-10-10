# Module_Watch Release Preparation Checklist

Step-by-step guide for preparing and publishing a release of the `watchfolder` module.

How releases work here: the panel's weekly cron (`cron:module_updates`) reads this
repo's releases, and `ModuleManager::updateModuleFromSource()` downloads the asset
**`module.tar.gz`** at the tag **equal to the new version** (md5-verified via
`hashes.md5`). The GitHub Actions workflow (`.github/workflows/release.yml`) builds
and attaches both assets automatically on tag push — no manual builds are needed.

---

## 1. Prepare Release Baseline

Finish all feature/fix work and make sure it is already in `main`.

Set the version variable once and reuse it in all commands below:

```bash
VERSION="X.Y.Z"
```

> ⚠️ The git tag must be the **bare semver** (`1.0.3`, no `v` prefix) — the panel
> downloads the asset at the tag `== module.json version`.

---

## 2. Bump the Version — It Lives in TWO Places

The module declares its version twice. **Keep them identical:**

| Place | What to edit |
| --- | --- |
| `module.json` | `"version": "X.Y.Z"` |
| `WatchfolderModule.php` | `getVersion()` return value |

```bash
sed -i "s/\"version\": *\"[0-9.]*\"/\"version\": \"${VERSION}\"/" module.json
sed -i "s/return '[0-9.]*'; *$/return '${VERSION}';/" WatchfolderModule.php
grep -n "$VERSION" module.json WatchfolderModule.php   # verify both hit
```

Also check:

- [ ] `requires_core` in `module.json` still matches the minimum core version the
      module actually needs (bump it if this release uses newer core APIs).
- [ ] `hash_id` is **untouched** — it is the module's permanent identity
      (identity-pinned on update; the panel rejects an archive whose `hash_id`
      differs from the installed one). Never regenerate it.

---

## 3. Database Migrations

If this release changes the schema:

- [ ] Add a forward delta `migrations/${VERSION}.sql` — forward-only and
      **idempotent** (`IF NOT EXISTS` / conditional `ALTER`), because the panel
      replays every delta in `(installed, current]` on update.
- [ ] Fold the same change into `database.sql` (master schema) — fresh installs
      run only `database.sql` and never see the deltas.
- [ ] Update `database_drop.sql` if new tables/views were added (single drop
      file, no per-version `.down` files).
- [ ] The version comment inside the new delta file matches the file name.

If the schema did not change — no new migration file; do **not** rename or edit
already-shipped deltas.

---

## 4. Pre-Release Validation

**Syntax check** (module has no own CI test suite — lint locally):

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v 'No syntax errors' || echo OK
```

**Local build sanity check** — same target CI runs on the tag:

```bash
make release
tar -tzf module.tar.gz | sort | head -30   # module.json must be at the archive root
md5sum -c hashes.md5
make clean
```

Verify the archive contains **no** `.git/`, `.github/`, `Makefile`, `README.md`,
`LICENSE`, `RELEASE.md` — the Makefile excludes them.

**Live test in a panel** (recommended for schema or import-flow changes): copy the
tree into a dev panel as `src/Modules/watch_2541a/` (`{name}_{hash5}`, hash5 =
first 5 chars of `hash_id`), run `php console.php status`, open the Watch pages,
and run a scan against a test folder.

---

## 5. Changelog / Release Notes

Notes are generated automatically on tag push by
[git-cliff](https://git-cliff.org) (default config) from the conventional commits
since the previous tag; non-conventional commits are skipped. Preview locally:

```bash
npx git-cliff --unreleased --strip header
```

> 💡 To write the notes by hand instead, **create the GitHub release with notes
> first** — the workflow is idempotent and will only upload assets to it.

---

## 6. Single Release Commit

```bash
git add module.json WatchfolderModule.php migrations/ database.sql database_drop.sql
git commit -m "Prepare release ${VERSION}"
git push
```

---

## 7. Tag and Publish

```bash
git tag "${VERSION}"
git push origin "${VERSION}"
```

GitHub Actions (`release.yml`) will then:

- run `make release` (builds `module.tar.gz` + `hashes.md5`),
- create the release for the tag with generated notes if it doesn't exist,
- upload/overwrite both assets (`--clobber`).

> ✅ Wait for the Actions run to finish, then check
> [Releases](https://github.com/Vateron-Media/Module_Watch/releases).

---

## 8. Post-Release

- [ ] Both assets (`module.tar.gz`, `hashes.md5`) are attached and downloadable.
- [ ] `md5sum -c hashes.md5` passes on the downloaded pair.
- [ ] Generated release notes read well (edit the release description if needed).
- [ ] On a panel with the previous version installed: the **Update to X.Y.Z**
      button appears (weekly cron `cron:module_updates`, or trigger the check
      manually) and the update completes — the panel backs up, replaces files,
      runs the new delta, and rolls back automatically on failure.
- [ ] Close related issues/milestones.

---

## Command Reference

| Command | Purpose |
| --- | --- |
| `make release` | Build `module.tar.gz` + `hashes.md5` locally (same as CI) |
| `make clean` | Remove locally built release assets |
| `git tag ${VERSION} && git push origin ${VERSION}` | Trigger the CI release build |
