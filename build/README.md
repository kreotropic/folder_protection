<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Maintainer tooling

Nothing in this directory ships: `build` is excluded from the App Store tarball.
It exists for working on the app, not for running it.

| File | What it is |
|---|---|
| `docker-compose.nc34.yml` | Disposable Nextcloud 34 instance, port 8085, used to verify the app against the `<max-version>` it declares. |
| `docker-compose.nc35.yml` | Disposable Nextcloud 35 instance, port 8086, same purpose for the current `<max-version>`. |

## Verifying against Nextcloud 34

The development instance on 8080 runs NC 33. Declaring `max-version="34"` without
running anything on 34 is a guess, so this compose brings up a throwaway 34
alongside it. Its own project name, containers, port and volumes mean it cannot
disturb the 8080 instance.

```bash
docker compose -p folderprot-nc34 -f build/docker-compose.nc34.yml up -d
```

Admin credentials are `ncadmin` / `folderprot-nc34-verify`. Tear it down with
`down -v` — without the `-v` the volumes survive and the next `up` resumes the
old instance instead of building a clean one.

```bash
docker compose -p folderprot-nc34 -f build/docker-compose.nc34.yml down -v
```

### Two things the compose alone does not give you

**`groupfolders` cannot come from `apps/`.** The compose mounts the whole `apps/`
tree (the integration tests need group folders), but the copy checked in there is
21.0.13, which declares `max-version="33"` and so refuses to install on 34.
Replacing it would break the NC 33 instance, which shares the same directory.
Instead give the 34 instance its own apps directory *inside its own volume* and
install a 34-compatible build there:

```bash
docker exec folderprot-nc34-app sh -c \
    'mkdir -p /var/www/html/nc34_apps && chown www-data:www-data /var/www/html/nc34_apps'
docker exec folderprot-nc34-app bash -c '
    cd /var/www/html
    php occ config:system:set apps_paths 2 path --value=/var/www/html/nc34_apps
    php occ config:system:set apps_paths 2 url  --value=/nc34_apps
    php occ config:system:set apps_paths 2 writable --value=true --type=boolean'

docker exec folderprot-nc34-app bash -c '
    cd /tmp && curl -sL -o gf.tar.gz \
      https://github.com/nextcloud-releases/groupfolders/releases/download/v22.0.6/groupfolders-v22.0.6.tar.gz
    tar xzf gf.tar.gz -C /var/www/html/nc34_apps
    chown -R www-data:www-data /var/www/html/nc34_apps/groupfolders'

docker exec folderprot-nc34-app php /var/www/html/occ app:enable groupfolders
```

`occ app:getpath groupfolders` should print the `nc34_apps` path, confirming the
22.x build won over the 21.x one in the shared mount.

**The tests need two fixtures.** A group folder named `team` and an external
storage mounted at `/exttest`; without them three tests fail on PROPFIND with 404.

```bash
docker exec folderprot-nc34-app php /var/www/html/occ groupfolders:create team
docker exec folderprot-nc34-app php /var/www/html/occ groupfolders:group 1 admin read write share delete

docker exec folderprot-nc34-app sh -c 'mkdir -p /tmp/nc-exttest && chown www-data:www-data /tmp/nc-exttest'
docker exec folderprot-nc34-app php /var/www/html/occ app:enable files_external
docker exec folderprot-nc34-app php /var/www/html/occ files_external:create \
    /exttest local null::null -c datadir=/tmp/nc-exttest
```

Note `groupfolders:group` takes the permissions as a whitespace-separated list
including `read`; omitting `read` leaves the group with none. The plain-text
`groupfolders:list` table does not render them — check `--output=json_pretty`,
where the group should show `"permissions": 31`.

Then run the suites:

```bash
docker exec folderprot-nc34-app \
    php /var/www/html/custom_apps/folder_protection/vendor/bin/phpunit \
    -c /var/www/html/custom_apps/folder_protection/phpunit.xml

docker exec -e FP_TEST_PASSWORD=folderprot-nc34-verify -e FP_TEST_BASE_URL=http://localhost \
    folderprot-nc34-app \
    php /var/www/html/custom_apps/folder_protection/vendor/bin/phpunit \
    -c /var/www/html/custom_apps/folder_protection/phpunit.integration.xml
```

A clean run is 23/23 unit and 14/14 integration.

## Verifying against Nextcloud 35

`max-version="35"` gets the same treatment, on its own port and project name:

```bash
docker compose -p folderprot-nc35 -f build/docker-compose.nc35.yml up -d
docker compose -p folderprot-nc35 -f build/docker-compose.nc35.yml down -v
```

Admin credentials are `ncadmin` / `folderprot-nc35-verify`.

Two things differ from the 34 rig:

**`groupfolders` installs straight from the App Store.** The 34 notes above work
around a checked-in 21.x copy that refuses to install on 34; on 35 the released
build is 23.0.1 and `occ app:install groupfolders` just works, so the whole
second-`apps_paths` dance above is unnecessary. `custom_apps` does have to be
writable by `www-data` first, which the bind mount does not guarantee:

```bash
docker exec folderprot-nc35-app chown www-data:www-data /var/www/html/custom_apps
docker exec -u www-data folderprot-nc35-app php /var/www/html/occ app:install groupfolders
```

**The database floor moved.** NC 35 dropped MariaDB 10.6 (minimum 10.11 LTS) and
MySQL 8.0 (minimum 8.4 LTS); the compose pins `mariadb:11.4` rather than the
10.11 the 34 compose uses, so the instance is not sitting on the new floor.

The fixtures and the two suites are otherwise identical to the 34 procedure:

```bash
docker exec -u www-data folderprot-nc35-app php /var/www/html/occ groupfolders:create team
docker exec -u www-data folderprot-nc35-app php /var/www/html/occ groupfolders:group 1 admin read write share delete

docker exec folderprot-nc35-app sh -c 'mkdir -p /tmp/nc-exttest && chown www-data:www-data /tmp/nc-exttest'
docker exec -u www-data folderprot-nc35-app php /var/www/html/occ app:enable files_external
docker exec -u www-data folderprot-nc35-app php /var/www/html/occ files_external:create \
    /exttest local null::null -c datadir=/tmp/nc-exttest
```

A clean run on 35.0.1 / PHP 8.5.11 is 25/25 unit and 15/15 integration.

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
