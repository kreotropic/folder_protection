# Changelog

## [2.4.0] - 2026-08-02

### Fixed
- **A protected folder reserved its name across the whole server.** With
  `/files/a/b/gama` protected, no user could create a folder called `gama` anywhere —
  `MKCOL /files/gama` returned 403. This was documented as a known limitation up to
  v2.1.0 and believed fixed in v2.2.0: `9363b42` removed the `isAnyProtectedWithBasename`
  heuristic from `StorageWrapper` and the README line was dropped. But the same heuristic
  had been added to `ProtectionPlugin::beforeBind` three months earlier, for a different
  purpose, and was never removed — and DAV is the path both Explorer and the web UI use,
  so the limitation survived in full while no longer being documented. The `beforeBind`
  block is now gone; protection is genuinely path-based again.

- **Rejecting a desktop-client move left an empty folder at the destination.** This is
  what the server-wide name block was really guarding against: the Windows client sends
  `MKCOL destination` before `MOVE`, so refusing the move stripped nothing back out.
  `beforeMove` already had a `deleteEmptyNode()` call for it, but that code was
  unreachable — Sabre emits `beforeUnbind` for the move *source* before `beforeMove`, and
  a protected source aborts the request there. The cleanup now runs from `beforeUnbind`
  via `cleanUpMoveSteppingStone()`, which reads the `Destination` header when the request
  method is MOVE. The orphan is removed for the one folder involved, instead of every
  user being denied the name.

- **Deleting the parent of a protected folder broke desktop sync unrecoverably.** Deleting
  the protected folder itself was fine — it came straight back — but deleting an ancestor
  left the client in a sync error that only a manual re-copy cleared.

  What makes the direct case work is not a retry, it is that the client never attempts the
  delete: `ProtectionPropertyPlugin` strips `D` from `oc:permissions`, so the folder is
  advertised as non-deletable and the client restores it locally. That strip was keyed on
  `isProtected()` — an exact match — so an ancestor still advertised `RGDNVCK`. The client
  deleted it, hit the 403 that `beforeUnbind` raises for a protected descendant, and had
  nowhere to go. Both the property plugin and `StorageWrapper::getPermissions()` now strip
  `D` when `hasProtectedDescendant()` is true as well, so the whole ancestor chain is
  advertised as non-deletable and matches what the server actually enforces.

  `beforeUnbind` additionally calls `touchSubtree()` in this case, giving the subtree fresh
  etags (bounded at 10000 entries) so a client that already removed the contents locally
  pulls them back rather than reading them as a deletion to propagate.

- **The "Move or copy" entry stayed visible in a protected folder's row menu.** The
  selector looked for action id `copy`; Nextcloud's id is `move-copy` — the same one the
  selection bar was already matching correctly. Delete was hidden, copy was not, so the
  action was clickable and only failed once a destination had been picked.

- **Notifications never reached the user.** The notifier was declared only through an
  `<notifications><notifier>` block in `appinfo/info.xml`. That element is not read by the
  server and does not exist in the App Store `info.xsd` — no core app uses it; they all call
  `registerNotifierService()`. The notifier was therefore absent from
  `RegistrationContext::getNotifierServices()`, so every "action blocked" notification was
  created and then discarded in `Notification\Manager::prepare()`. `Application::register()`
  now calls `$context->registerNotifierService()`.

- **`occ folder-protection:protect` had no effect for up to 300 seconds.** The command
  inserted the row and returned success, but never invalidated the protection cache the
  way `AdminController::protect()` does. `isProtected()` caches negative results for 300s,
  and any earlier PROPFIND or MKCOL on the path will have populated it — so a folder
  protected from the CLI could still be deleted or moved straight afterwards. Both
  `Protect` and `Unprotect` now call `clearCacheForPath()`. Found by testing on NC 34;
  it affected NC 33 identically.

  Note the fix only holds where `memcache.distributed` is configured (Redis or similar).
  Falling back to APCu means the CLI and Apache have *separate* caches and no
  invalidation from a command can reach the web process — see `build/README.md`.

- **`occ folder-protection:unprotect` was unusable from a script.** Its confirmation prompt
  resolves to its default under `-n`/`--no-interaction`, and that default is "no" — so the
  command printed `Cancelled.` and exited 0, reporting success while having done nothing.
  It now takes `--force` to confirm non-interactively, and refuses with exit code 1 rather
  than pretending to have run. Piping an answer (`echo y | occ …`) still works.

### Security
- Removed `#[NoCSRFRequired]` from the state-changing POST endpoints (`protect`, `unprotect`,
  `updateReason`, `clearCache`). The attribute disables the CSRF check for *all* HTTP methods,
  so an authenticated admin visiting a malicious page could have folders protected or
  unprotected cross-site. Read-only GETs keep the attribute. The frontend uses
  `@nextcloud/axios`, which sends the request token automatically.
- Removed the `#[AdminRequired]` attribute and its import. **No such attribute exists in
  Nextcloud** (only `NoAdminRequired` and `SubAdminRequired`); it was silently inert. The
  endpoints were in fact protected, because admin-only is the default in
  `SecurityMiddleware::checkSecurity` — but the attribute suggested a guarantee it never
  provided, and the `use` statement pointed at a non-existent class.

### Changed
- `hasProtectedDescendant()` answers from the cached protection list instead of running a
  `COUNT(*)` with a `LIKE`. It is now called once per node on every PROPFIND, where a query
  per listed file was not affordable. The cached list is already invalidated by
  `clearCacheForPath()` on every protect/unprotect, so it is no more stale than
  `isProtected()`. Comparing in PHP also drops the `LIKE` wildcard escaping, where an
  unescaped `_` in a folder name matched any character.

- **Nextcloud 34 compatibility**: `max-version` raised to 34, `min-version` to 33, and the
  PHP requirement to 8.3 (NC 34 requires 8.3+; 8.2 is deprecated).
- Removed the `<dav><properties>` and `<notifications>` blocks from `info.xml`. Neither is a
  valid element in the App Store schema and neither is parsed by the server; both would fail
  XSD validation on upload. The DAV properties are registered by `DAV\ProtectionPropertyPlugin`.
- `folder-protection-ui.js` is now bundled by webpack from `src/` and imports `generateUrl`
  from `@nextcloud/router` instead of using the deprecated `OC.generateUrl` global.
- `AdminApp.vue` uses `translate` from `@nextcloud/l10n` instead of the deprecated
  `OC.L10N.translate` global.
- Aligned `package.json` with its declared dependency versions (`@nextcloud/l10n` was pinned at
  1.6.0 on disk while the manifest asked for ^3.4.1) and with the `info.xml` version number.
- Reordered `info.xml`: its elements are an `xs:sequence`, and `<dependencies>` sat before
  `<website>` while `<admin>` sat after `<admin-section>`. Both violated the schema, so the
  file had never validated and the app could not have been uploaded to the App Store.
- Added `build/docker-compose.nc34.yml` and `build/README.md` — a disposable NC 34 instance
  (port 8085) that verifies the declared `max-version` instead of assuming it. Verified there:
  23/23 unit and 14/14 integration on NC 34.0.2 / PHP 8.5.8.
- Dropped the deprecated `curl_close()` from the integration tests; PHP 8.5, which NC 34
  ships, warns on it and it has been a no-op since 8.0.

### Note
- The 2.3.0 entry below claims `IResult::fetch()` was "removed in NC33". That is incorrect —
  `fetch()` is `@since 21.0.0` and is still present in NC 33 and 34, carrying only a `@note`
  recommending `fetchAssociative()`/`fetchNumeric()`. It is not deprecated. Conversely,
  `fetchAssociative()` is `@since 33.0.0` and does **not** exist in NC 32 or earlier.

## [2.3.2] - 2026-07-02

### Fixed
- `ProtectionChecker::hasProtectedDescendant()` now escapes LIKE wildcards (`_`, `%`) in the folder path before running the descendant lookup. Folder names commonly contain `_`, which LIKE treats as "any single character" — this produced false positives that could wrongly block delete/move/copy operations on folders with no protected descendants.

### Changed
- Corrected the misleading comment on `ProtectedFoldersWidget::load()` (it claimed there was no custom Vue widget while loading exactly that script; the Vue component provides the richer dashboard rendering, while `getItems()` still serves API/mobile clients).

## [2.3.1] - 2026-06-11

### Security
- `/api/status` (`getFolderStatuses`, accessible to any logged-in user for the file-list lock badges) no longer returns the `reason` and `created_by` of protected folders — it now exposes only the paths, preventing information disclosure to non-admin users.

### Fixed
- `ProtectionChecker::normalizePath()` now collapses duplicate slashes (`/a//b` → `/a/b`), so a path entered with redundant slashes produces the same `path_hash` the DAV layer computes. Aligns PHP normalisation with the JS UI.
- Hide the delete action in the file-list context menu for protected folders (the `hideBlockedActionsInMenu` selectors now cover delete in addition to copy).

### Changed
- **Refactor**: the near-identical DAV node→DB path resolution duplicated across `ProtectionPlugin`, `LockPlugin` and `ProtectionPropertyPlugin` is now a single shared `GroupFolderStorageTrait::getNodePathCandidates()`, removing ~120 lines and the leading-slash inconsistency between the copies.
- Removed redundant `StorageWrapper` overrides (`__call`, `is_dir`, `file_exists`) already provided identically by the parent `Wrapper`, and dropped the stray `false` argument passed to `FolderLocked`.
- Removed dead code: the unreachable `file_id` branch and `getSizeByFileId()` in the dashboard widget data service (the `file_id` column is never populated).
- Lowered per-request/per-operation DAV log lines from `info` to `debug` to reduce log noise in production (block events remain `warning`; admin protect/unprotect actions remain `info`).

## [2.3.0] - 2026-05-25

### Changed
- **Nextcloud 33 compatibility**: Replaced all uses of `IResult::fetch()` (removed in NC33) with `fetchAssociative()` across `AdminController`, `ListProtected`, `Unprotect`, `ProtectionChecker`, and the migration class.
- **Nextcloud 33 compatibility**: `Notifier::prepare()` now throws `\OCP\Notification\UnknownNotificationException` instead of `\InvalidArgumentException` (required since NC30, the old class was removed in NC33).
- **NC34 future-proofing**: Replaced all `OC::$server->getUserSession()`, `OC::$server->getNotificationManager()`, and `OC::$server->get(LoggerInterface::class)` calls in `StorageWrapper` and `ProtectionPlugin` with `\OCP\Server::get(...)` (the `OC::$server` global is removed in NC34).
- Removed compat wrappers (`method_exists($result, 'fetchAssociative') ? … : $result->fetch()`) now that `min-version="28"` guarantees `fetchAssociative()` is always available.

## [2.2.1] - 2026-05-22

### Fixed
- Notifier registration corrected to prevent startup warnings
- Lock path resolution fixed for edge cases in LockPlugin
- COPY operation now correctly blocks descendants of protected folders, not just the root
- Removed dead code paths in protection check flow

### Changed
- Admin UI: added edit button to update the protection reason inline
- Admin UI: lock icon overlay on folder entries, styled confirmation dialog (replaces browser native confirm), "Configurações" section label, internal ID shown as monospace chip, reason displayed with label
- Translations: added missing strings for en/pt_PT (lock icon toggle, folder existence check, edit reason)

## [2.2.0] - 2026-05-22

### Fixed
- **MySQL/MariaDB installation failure** (issue #9): `CREATE TABLE` failed with error 1071 ("Specified key was too long") on MySQL/MariaDB with `utf8mb4` charset because the `path` column (TEXT) exceeded the 3072-byte InnoDB index limit. Fixed by adding a `path_hash` column (MD5 of the normalised path, 32 chars) and using it as the unique index instead.
- **Rename-to-protected-path bypass via storage layer** (issue #10): `StorageWrapper::rename()` only checked the source path, allowing a folder to be renamed INTO a protected path. Now also checks the target path and throws `FolderLocked` if the destination is protected.
- **External storage path mismatch** (issue #11): Protection checks silently failed for external storage (SMB, S3, local mount, etc.) because the storage wrapper received paths relative to the external storage root (e.g. `subfolder`) instead of the full user-relative path (`files/extname/subfolder`). Fixed across `StorageWrapper` and all three DAV plugins by reconstructing the full path from the mount-point suffix.
- **DBAL compatibility** (issue #12): `fetchAssociative()` call in the upgrade migration used the wrong method on older Nextcloud/Doctrine DBAL versions. Now uses a compatibility wrapper that falls back to `fetch()` when needed.

## [2.1.1] - 2026-03-10

### Fixed
- Fixed false positive in `beforeBind()` where chunked upload paths (`/uploads/.../1`, `/2`, etc.) matched protected GroupFolder IDs, blocking file uploads larger than `upload_chunk_size` (128 MB default)
- Affected both web browser and desktop client uploads


## [2.0.1] - 2026-02-22

### Fixed
- DELETE and MOVE operations on regular (non-group) folders are now correctly blocked; previously these were registered in `beforeMethod` which fires too late in the Sabre event lifecycle — moved to `beforeUnbind` / `beforeMove`
- MKCOL blocked operations now correctly return **403 Forbidden** instead of **201 Created**; the old `sendErrorResponse(403) + return false` pattern in `beforeBind` was overridden by Sabre's internal response setter — fixed by throwing `Sabre\DAV\Exception\Forbidden`
- Desktop client rename-bypass via stepping-stone folder (MKCOL with neutral name + MOVE to protected name) is now blocked; the orphaned stepping-stone folder is automatically deleted from the server
- ETag changes now propagate up to all ancestor directories after a blocked MKCOL so the sync client re-lists parent folders and discards its local-only copy
- `ProtectionChecker::getProtectionInfo()` cache now correctly stores a sentinel for "not found" entries (avoids repeated DB queries for unprotected paths)
- `AdminController::protect()` now reads `userId` from the server-side `IUserSession` instead of trusting a client-supplied parameter
- `Notifier` class moved to correct namespace `OCA\FolderProtection\Notification` (was `OCA\FolderProtection\DAV`)
- Removed dead `OperationForbidden` exception class and unused `getCommands()` method
- `LockPlugin::getInternalPath()` no longer checks the parent directory name (causing false positives)
- `AdminApp.vue` add-protection modal now resets to the Group Folders tab when reopened
- `oc:permissions` is no longer modified for protected folders; keeping `D` and `V` allows the desktop client to attempt MOVE/DELETE, receive a 403 with the folder name, and show it in the "Not Synced" activity panel — the folder is restored within ~30 s via ETag-driven re-sync
- DAV error messages now include the visible folder name (e.g. "The folder 'novo_teste' is protected") instead of generic text

---

## [2.0.0] - 2026-02-20

### Added
- Group Folder support: protect group folders by their internal ID (`/__groupfolders/N`) without requiring the admin to be a group member
- Admin panel: two-tab modal ("Group Folders" and "Custom Path") for adding protections; gracefully degrades to single form when the groupfolders app is not installed
- API endpoint `GET /api/groupfolders` listing all group folders with their protection status
- API endpoint `GET /api/status` now emits `/files/<mountPoint>` aliases for group folder paths so the web UI badge system can match them by visible name
- ProtectionPropertyPlugin: removes the `D` flag from `oc:permissions` for protected folders so the Nextcloud desktop client does not attempt deletion locally

### Changed
- Desktop sync recovery: on a blocked DELETE or MOVE, the server updates the node's ETag and mtime so the sync client detects the server state as "newer" and restores the folder instead of showing a permanent sync error
- Database migrations consolidated from versions 1–4 into a single clean migration (Version 2); existing installations are not affected
- App version bumped to 2.0.0
- Admin list now shows the *visible* mount point name alongside internal `/__groupfolders/N` paths, making it easier to remember which folder is which

### Fixed
- Group folder path detection in ProtectionPlugin and ProtectionPropertyPlugin: DAV nodes backed by a group folder storage are now correctly resolved to `__groupfolders/N` by traversing the storage wrapper chain via `getWrapperStorage()` / `getFolderId()`
- Lock icon badge now appears on group folders in the web file browser (path alias fix in `/api/status`)
- COPY operations on group folders are now correctly blocked

---

## [1.0.0] - 2025-11-12

### Added
- Initial release
- Protect folders from delete/move/copy operations
- Two-layer protection system (WebDAV plugin + Storage wrapper)
- OCC commands for CLI management: `folder-protection:list`, `folder-protection:protect`, `folder-protection:unprotect`, `folder-protection:check`
- Web admin interface (Vue.js)
- Distributed cache support (Redis/Memcached) with 5-minute TTL
- Rate-limited Nextcloud notifications on blocked operations
- Database schema with indexed `path`, `file_id`, and `created_at` columns
