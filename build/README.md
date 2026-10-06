<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is excluded from the App Store tarball.
It exists for working on the app, not for running it.

| File | What it is |
|---|---|
| `nc-instance.sh` | `up` / `test` / `down` / `ls` for one disposable Nextcloud instance per supported version. |
| `docker-compose.nc.yml` | The instance itself (Nextcloud + MariaDB + Redis), parameterised by `NC_VERSION` / `NC_PORT`. Not meant to be run by hand. |

## Checking the app against a Nextcloud version

Declaring a `<nextcloud min-version max-version>` range without running anything
on its ends is a guess, so each version in the range gets its own throwaway
instance. Each has its own project name, containers, port and volumes, so they
run side by side and cannot disturb the development instance on 8080.

```bash
build/nc-instance.sh up 33      # first run pulls the image and installs: a couple of minutes
build/nc-instance.sh test 33    # unit + integration suites against it
build/nc-instance.sh down 33    # -v: without it the next `up` resumes the old instance
build/nc-instance.sh ls
```

| NC | Port |
|---|---|
| 31 | 8100 |
| 32 | 8101 |
| 33 | 8102 |
| 34 | 8103 |
| 35 | 8104 |

Admin login is `ncadmin` / `folderprot-ncNN-verify` (NN = the version). To try a
version that is not in the table, add it to `VERSIONS` / `PORTS` in the script.

### What `up` does

- **Mounts this app only.** `groupfolders`, which the integration suite needs, is
  installed from the App Store, so each version gets the release built for it
  (19.x for NC 31 … 23.x for NC 35). It cannot come from a shared `apps/`
  directory: one copy there cannot satisfy every version's `max-version`.
- **Widens the app's `info.xml` inside the container.** Nextcloud refuses to enable
  an app outside its declared range, and `occ app:enable --force` waives only
  `max-version`, never `min-version` — so a version below the declared minimum
  could not be tested at all. `up` writes a copy of `appinfo/info.xml` with the
  `<nextcloud>` range set to exactly that version (into `build/.generated/`,
  git-ignored) and bind-mounts it read-only over the real one. The file in the
  repo is untouched, and `up` says so when the version is outside the range it
  declares. That is what lets the matrix answer "would this work on NC N?" for
  an N that `info.xml` does not yet list.
- **Upgrades an existing instance.** Every release bumps `info.xml`'s `<version>`,
  which leaves an instance from an earlier `up` in "requires upgrade" — occ then
  refuses everything but `upgrade`, `app:enable` included. `up` runs `occ upgrade`
  when `occ status` says `needsDbUpgrade: true`.
- **Creates the two fixtures the integration suite needs**: a group folder named
  `team` (the `admin` group has all permissions) and a local external storage
  mounted at `/exttest`. Without them three tests fail on PROPFIND with 404.
  If there is no `groupfolders` release for a brand-new Nextcloud yet, `up` warns
  and carries on, and the suites then show exactly which tests needed it.

`test` runs both suites with the `phpunit` in this app's own `vendor/`, so
`composer install` must have been run on the host first. A clean run is 25/25
unit and 15/15 integration.

### Last verified

Run on 2026-09-26 against 2.4.1 plus the lock-badge rewrite on `main` (the badge
rendered through `@nextcloud/files`):

| NC | PHP | groupfolders | unit | integration | browser (badge, hidden actions, admin, widget) |
|---|---|---|---|---|---|
| 31.0.14 | 8.3.30 | 19.1.20 | 25/25 | 15/15 | **fails** |
| 32.0.15 | 8.3.35 | 20.1.18 | 25/25 | 15/15 | **fails** |
| 33.0.9 | 8.4.26 | 21.0.15 | 25/25 | 15/15 | 15/15 |
| 34.0.4 | 8.5.10 | 22.0.6 | 25/25 | 15/15 | 15/15 |
| 35.0.0 | 8.5.10 | 23.0.1 | 25/25 | 15/15 | 15/15 |

That is why `info.xml` says 33–35 and not 31–35: on 31 and 32 the server side is
fine, but the new badge never renders and, because the "hide move/copy" logic keys
on the rendered badge, move/copy is no longer hidden for protected folders (the
server still refuses it with 403). On NC 31 the listing's PROPFIND does not ask
for `nc:is-protected` / `nc:is-deletable` at all — `window._nc_dav_properties`
lacks them — although the server answers both when asked directly; the
`@nextcloud/files` 4.x registration evidently does not reach the Files app of
those versions. The UI before the rewrite (2.4.0) did pass on all five.

Every instance ran PHP 8.3 or newer — that is all the images ship — so nothing
here says anything about PHP 8.1/8.2, and `info.xml` keeps `php min-version="8.3"`.
Re-run the matrix whenever the declared range changes or a new Nextcloud is
released.

The two suites cover enforcement over WebDAV and the caching logic. They do not
cover the browser side, which depends on the Files app's DOM and is the part most
likely to differ between Nextcloud versions — the browser column above was checked
by driving a real Chromium against each instance, and that script is not in this
repo. Check it by hand (or script it) before widening the range.

## Why the compose includes Redis

`ProtectionChecker` caches through `ICacheFactory::createDistributed()`. With no
distributed backend configured that falls back to `memcache.local`, which in the
Docker image is APCu — and **APCu is not shared between the CLI and Apache**. The
consequence is not a slow cache but a wrong one: `occ folder-protection:protect`
invalidates the cache in the CLI process, the web process never sees it, and the
protection silently does nothing for up to 300 seconds (`isProtected()` caches
negative results for that long, and any earlier PROPFIND or MKCOL on the path
will have populated it).

This is worth knowing beyond the test rig: **on a production instance without
`memcache.distributed`, protections added from the command line do not take
effect immediately.** The web UI is unaffected — it invalidates in the same
process that serves the next request.
