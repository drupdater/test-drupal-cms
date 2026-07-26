# test-drupal-cms

A [Drupal CMS](https://www.drupal.org/project/cms) site that exists for one
reason: to be updated by [drupdater](https://github.com/drupdater/drupdater),
over and over, so that breakage in drupdater is caught against a real Drupal
site instead of against mocks.

> [!WARNING]
> **`composer.lock` is deliberately out of date and must never be updated on
> `main`.** Drupal core is held a minor version behind while `composer.json`
> still allows current releases. That gap is the fixture: it is what gives
> drupdater something to update on every run, and it widens on its own as
> upstream releases. A well-meaning `composer update` here silently turns every
> future regression run into a `no_changes` no-op that tests nothing.
>
> Do not run `composer update`, `composer require` without `--no-update`, or
> `drush config:export` on `main`. Do not enable Dependabot or Renovate. Do not
> merge the pull requests drupdater opens against this repository — close them.

## How it is used

drupdater's `Integration Test` workflow clones this repository at full depth and
runs drupdater against the checkout:

```bash
drupdater --working-dir <checkout> --dry-run --report report.json
```

The run is expected to reach `status: "success"` with a non-empty `packages`
list. Anything else is a drupdater regression, a fixture that has rotted, or an
upstream Drupal change worth knowing about.

## What each part of this repository is for

| Path | Exercises |
|---|---|
| `composer.lock` (core held a minor behind) | `composer_diff`, `update_hooks`, and `composer_audit` in `--security` mode |
| `composer.json` `extra.patches` + `patches/` | `composer_patches` |
| `composer.json` `config.allow-plugins` | `composer_allow_plugins` |
| `phpcs.xml` (with a `<file>` element) + `web/modules/custom/drupdater_fixture` | `code_beautifier` |
| `web/modules/custom/drupdater_fixture/src/DeprecatedApiUser.php` | `deprecations_remover` |
| `ergebnis/composer-normalize` in `require-dev` | `composer_normalizer` |
| `locale_deploy` enabled, a second language (`de`), and `translations/` | `translations_updater` |
| `update` module enabled | `unsupported_modules` |
| Committed `web/sites/default/settings.php` | preflight's settings.php check, and the installer's SQLite append |
| `config/sync/` with a profile-less `core.extension.yml` | `drush site:install --existing-config` |

`drupal/coder` and `palantirnet/drupal-rector` are deliberately **not** required.
Leaving them out is what makes `code_beautifier` and `deprecations_remover`
exercise their install-then-remove paths on every run.

## Verified

A full `drupdater --dry-run` against this repository reaches, in order:
`acquire working copy`, `preflight` (all six checks), `composer install`,
`baseline site install`, `update shared code`, `site update`. The update
resolves Drupal core 11.3.9 -> 11.4.x, which is a minor-crossing update and
therefore carries `post_update_*` hooks for the `update_hooks` addon.

Two findings worth keeping in mind if you rebuild this repository:

- `drush site:install --existing-config` **does** work against Drupal CMS's
  recipe-exported config, but only after the `user_picture` field is removed
  from the export. Core's `SiteConfigureForm` saves the root user in the same
  request that imported the config, before the entity field manager has seen
  the newly imported field definitions, and the install dies with
  `InvalidArgumentException: Field user_picture is unknown`.
- `drupal_cms_installer` uninstalls itself at the end of installation and core
  clears the `profile` key when the install profile is uninstalled, so the
  exported `core.extension.yml` is profile-less. Nothing needs to strip it.

## Rebuilding from scratch

Only do this if the fixture has genuinely rotted (for example, the constraint
floor stops installing on a supported PHP version).

Run these inside a drupdater image (`ghcr.io/drupdater/drupdater-php8.4`) so PHP
and the composer environment match CI. Outside it, as root, export
`COMPOSER_ALLOW_SUPERUSER=1` first -- without it composer silently disables
every plugin, `composer/installers` never places the Drupal packages, and you
get a broken tree from a command that still exits 0.

```bash
composer create-project drupal/cms:2.0.0 test-drupal-cms --no-install
cd test-drupal-cms

composer config --no-plugins allow-plugins.cweagans/composer-patches true
composer config --no-plugins allow-plugins.ergebnis/composer-normalize true
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true

# Pin the platform, or the lock ends up resolved for whatever PHP you happen to
# be running. Resolving on 8.4 without this pulled in Symfony 8
# (symfony/css-selector requires >=8.4.1) and the lock then failed drupdater's
# preflight on any earlier PHP.
composer config platform.php 8.4.1
composer require --no-update drush/drush:^13.7 cweagans/composer-patches:^1.7 drupal/locale_deploy:^1
composer require --no-update --dev ergebnis/composer-normalize:^2
composer update --with-all-dependencies

# The staleness mechanism: pin core exactly, re-resolve, then relax the
# constraints back to carets and re-sync ONLY the lock's content-hash. The hash
# is derived from composer.json, not from resolved versions, so the result is a
# composer.json that allows current releases and a lock that holds an old core.
#
# Pick a core version BELOW the floor of a published advisory (11.3.9 is under
# the <11.3.10 SQL-injection SA) and at least one minor behind current. The
# advisory is what gives `--security` mode something to do; the minor gap is
# what guarantees hook_update_N for the update_hooks addon.
CORE="drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message drupal/core-recipe-unpack drupal/core-vendor-hardening"
composer require --no-update $(for p in $CORE; do echo -n "$p:11.3.9 "; done)
composer update --with-all-dependencies      # whole tree, so contrib can follow core down
composer require --no-update $(for p in $CORE; do echo -n "$p:^11.3 "; done)
composer update --lock --no-install           # re-sync the hash, do NOT re-resolve

drush --yes site:install recipes/drupal_cms_starter --sites-subdir=default
drush --yes pm:enable update locale locale_deploy drupdater_fixture
drush --yes language:add de
# locale.settings translation.path is committed as ../translations, relative to
# the Drupal root, so locale_deploy writes .po files to the repository-root
# translations/ directory rather than burying them under web/sites/*/files
# (which is gitignored, and where nothing would ever be committed).
drush --yes config:export
```

Verify the result before committing — these are the two properties the fixture
depends on:

```bash
composer audit --locked      # must be non-empty, or --security mode aborts
composer outdated --direct --locked   # must be non-empty, or every run is a no-op
```

Flooring *every* package with `--prefer-lowest` does not work: it produces a
combination that will not install (a floored `drupal_cms_starter` updates
`tagify.settings` that its floored tagify does not ship, and the recipe apply
dies mid-install). Keep the staleness scoped to core.

`web/sites/default/settings.php` is hand-written, not the installer's output —
see the comments in that file for why the scaffolded
`realpath('../config/sync')` form cannot be used here.
