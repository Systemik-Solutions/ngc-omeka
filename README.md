# Curated Collections

An enduring web publishing platform solution for Humanities, Arts and Social Sciences (HASS) and Indigenous research 
data collections.

The Omeka S distribution is provided to support researchers to build and maintain HASS digital research collections 
consistent with FAIR principles. This distribution package for Omeka S prepared by 
[Systemik Solutions](https://systemiksolutions.com/) bundles the Omeka S core, specified modules and themes, and 
pre-defined content resources including vocabularies, taxonomies and resource templates.

## Introduction

A common requirement for HASS research is the ability to make digital research collections available online. This is 
often a requirement of national grant funding but is also consistent with making the outputs of research open, 
accessible and reusable. This has led to a proliferation of bespoke websites in a variety of commercial and 
open-source platforms, which require specialist skills, are generally not cyber-secure and which are sustained on 
fragile infrastructure. Current solutions are frequently short-lived due to high maintenance demands and 
reinvent digital infrastructure for hosting collections. Unfortunately, a high number of important collections are 
currently hosted on legacy platforms that are end-of-life. This makes it difficult for HASS and Indigenous researchers 
to process, publish and share enduring collections of research consistent with FAIR (Findable, Accessible, 
Interoperable, Reusable) principles.

This Curated Collection Omeka S distribution addresses HASS researcher-specific metadata and functionality 
requirements in a standard web publishing platform solution. This distribution will support most common types of 
media natively (including text, images, audio, video, basic maps etc.) and may be extended to support linked storage of 
bespoke types (e.g. 3D-models, blueprints, point clouds etc.). The basic Omeka S web interface is user-friendly and can 
be administered with relatively little technical support and the platform is extensible through the use of plug-in 
“themes” and “modules”. These projects are characterised as incremental, cumulative, granular, and collaborative 
semantic annotation and linking of heterogenous content and data. Rather than follow a research paradigm of repeatable 
experiments (text set-algorithm-result set) the research output is a node graph data structure and a persistent web 
presence.

Refer for detail on project objectives, refer to 
[Curated Collections for Enduring HASS and Indigenous Data](https://ardc.edu.au/project/curated-collections-for-enduring-hass-indigenous-data/).

## System Requirements

- PHP 8.2 or higher with the following extensions enabled:
  - cli
  - curl
  - imagick or gd
  - intl
  - mbstring
  - mysql
  - opcache
  - PDO
  - pdo_mysql
  - readline
  - xml
  - zip
- [Composer](https://getcomposer.org/)

For other Omeka S system requirements, refer to the 
[Omeka S documentation](https://omeka.org/s/docs/user-manual/install/#system-requirements).

## Installation

Clone the repository and rename the directory to your project name:

```bash
git clone https://github.com/Systemik-Solutions/ngc-omeka.git YOUR_PROJECT_NAME
```

The distribution requires [Composer](https://getcomposer.org/) to manage dependencies. Run the following command in 
the project directory:

```bash
composer install
```

## Configuration

### Distribution Configuration

The distribution requires some configuration before installation. In the `config` directory, create a copy of the
`config-example.json` file and rename it to `config.json`. Open the `config.json` file and update the configurations
as needed.

The configuration file include the following settings:

- `db`: the database connection information for the Omeka S instance. Note that the database should be created 
beforehand.
  - `host`: the database host (e.g., `localhost`).
  - `port`: the database port (e.g., `3306`).
  - `database`: the name of the database.
  - `username`: the database username.
  - `password`: the database password.
- `apache_user`: The linux user that runs the web server (e.g., `www-data` or `httpd`). This is used to set the correct
  permissions on certain directories.
- `url`: The base URL the instance is served at, with no trailing slash (e.g., `https://collections.example.org`).
  This is optional and used only by the health checker (see [Health Check](#health-check) below). Without it, the
  health checker skips every check that needs an HTTP request and still runs the database and filesystem checks.
- `admin`: The initial Omeka S user information.
  - `name`: the name of the user.
  - `email`: the user email address.
  - `password`: the user password.
- `title`: The title of the Omeka S instance.
- `timezone`: The timezone for the Omeka S instance (e.g., `Australia/Sydney`).
- `site`: the [Omeka S site](https://omeka.org/s/docs/user-manual/sites/) to create during installation. This is
  optional if you want to create the site later via the Omeka S admin interface.
  - `name`: the name of the site.
  - `slug`: the URL slug for the site.
  - `summary`: a brief summary of the site.
  - `theme`: the theme to use for the site (e.g., `default`). Note that this is the theme ID (normally the same as the
    theme folder name).

### Omeka S Configuration

You can put a file named `local.config.php` in the `config` directory to override default Omeka S configurations during
installation. This is optional if you want to keep the default Omeka S configurations. For more information about
the Omeka S configurations, refer to the [Omeka S documentation](https://omeka.org/s/docs/user-manual/configuration/).

## Distribution Installation

To install the NGC Omeka S distribution, run the `install` command from the project root:

```bash
php console install
```

You can pass the `-y` option to skip the confirmation prompt:

```bash
php console install -y
```

This will create the `public` directory under the project root. Set your web server's document root to the `public` 
directory or configure a virtual host accordingly.

> [!NOTE]
> If it's running on a Unix-based system (Linux or macOS), make sure the current user running the command has write
> permissions to the project directory.

> [!NOTE]
> If it's running on a Unix-based system (Linux or macOS), it will try to run the `chown` command to set the correct 
> permissions on the `public/files` and `public/logs` directories based on the `apache_user` setting in the
> `config.json` file, which requires `sudo` privileges without a password prompt. If it fails, you need to manually 
> set the correct permissions on those directories after the installation. Make sure the `apache_user` has write
> permissions to those directories.

Once it's done, you can access the Omeka S site by navigating to your server's URL or the configured host name 
in a web browser.

### Code-only Installation

If you want to manually install and set up the Omeka S instance, you can pass the `--code-only` or `-c` option to the 
install command:

```bash
php console install --code-only
```

This will only set up the Omeka S core, modules, and themes based on the `distribution.json` file without performing
any database setup or content import. You will need to manually finish the installation via the Omeka S admin 
interface.

## Updating the Distribution

To update the NGC Omeka S distribution, pull the latest changes from the repository and update the Composer 
dependencies:

```bash
git pull
composer update
```

Once it's done, run the update command to update the installed Omeka S instance:

```bash
php console update
```

You can pass the `-y` option to skip the confirmation prompt:

```bash
php console update -y
```

This checks for newer versions of the Omeka S core, modules, and themes based on the `distribution.json` file,
downloads them, and then applies any pending database migrations and module installations or upgrades.

Pass `--health-check` to verify the instance once the update has been applied:

```bash
php console update --health-check
```

This captures the resource counts before the download, runs the full health check suite afterwards,
and reports both the check results and what changed. It is off by default.

If a check fails, the command exits with a non-zero status. **The update has still been applied** —
nothing is rolled back, and the failures describe what to fix. If the update itself fails, the health
checks are skipped and the update's own error is reported instead.

Once it's done, log in to the Omeka S admin interface to verify that everything is working correctly.

> [!NOTE]
> The update command only updates the core, modules, and themes of the installed instance based on the changes in
> `distribution.json`. Changes to contents such as vocabularies, taxonomies and resource templates will not be applied
> to prevent data loss and inconsistencies. You may choose to update those contents manually via the Omeka S admin
> interface if needed.

### Updating the code and the database separately

The `update` command runs two steps in sequence, and each is also available on its own:

```bash
php console update:code   # download the core, module and theme updates
php console update:db     # apply the pending database migrations and module upgrades
```

These are useful for recovery. If a run fails part way through, for example because a download failed or a module
upgrade errored, you can re-run whichever step still needs to complete rather than starting over. Both accept the
`-y` option.

If you run them separately, `update:code` must always be followed by `update:db`. Between the two, the instance has
new code running against an un-migrated database.

All of the update commands exit with a non-zero status if any component fails to update, so a scripted run can tell
a clean update from a partial one.

### Updating a single component

Each part of the distribution can also be updated on its own. Unlike `update:code` and `update:db`, which split the
update by *stage*, these split it by *component*, and each one does both the download and the matching database work
in a single run:

```bash
php console update:core                       # the Omeka S core only
php console update:module                     # every module in distribution.json
php console update:module Oidc MergeItems     # only the named modules
php console update:theme                      # every theme in distribution.json
php console update:theme lively               # only the named themes
```

`distribution.json` remains the source of truth. Running `update:module` or `update:theme` without arguments covers
only the modules and themes the distribution defines, so anything you added to the installation by hand is left
alone. Naming a module or theme that is not in `distribution.json` is an error, so a typo fails loudly instead of
quietly doing nothing.

Themes have no database step — Omeka S picks them up straight off the filesystem — so `update:theme` never touches
the database, and it is the one update command that needs neither `config/config.json` nor a working database
connection.

All three accept `-y` to skip the confirmation prompt, and `--force` to re-download a component that is already at
the version in `distribution.json`:

```bash
php console update:module --force MergeItems   # re-lay a corrupted or hand-modified module
php console update:theme --force               # reset every distribution theme to the manifest version
```

`--force` re-downloads the files only; it never re-runs a database upgrade that Omeka S considers already applied.
Note that a module or theme is backed up and restored if its download fails, but the core is replaced in place with
no automatic rollback.

### Upgrading from v1.0.0 to v1.1.0

The v1.1.0 release includes the update of the "MappingExtensions" module from version 1.0.0 to version 1.0.1, 
which includes a fix for the namespace conflict. This changes the module directory name from `Mapping` to
`MappingExtensions`. Therefore, the built-in update process of the distribution will not be able to handle 
this change and you will need to manually update the module first before running the distribution update command. 
For more information about the update, refer to the
[MappingExtensions module page](https://github.com/Systemik-Solutions/OmekaS-MappingExtensions#upgrading-from-100-to-101).

## Health Check

The health checker verifies that an installed instance is working. Run it on its own, or as part of an
update with `php console update --health-check`.

```bash
php console health:check
php console health:check --url https://collections.example.org
php console health:check --json
```

It runs seven checks:

| Check | Needs | What it verifies |
|---|---|---|
| `core.version` | database | The core version on disk matches the version the database is migrated to. |
| `core.migrations` | database | Every migration file shipped with the core has been applied. |
| `modules.state` | database | Every module's `module.ini` version matches its row in the `module` table. Also reports deactivated modules and modules that are not part of the distribution. |
| `distribution.manifest` | nothing | Every component is at the version `distribution.json` names. |
| `api` | URL | The API answers, reports the same core version the code on disk has, and agrees with the database about how many public items exist. |
| `pages` | URL | The home page, login page, admin route, API index and every public site's home and item browse page all respond. |
| `media` | URL and database | A sample of stored files and their thumbnails are being served. |

The checks that need a URL are skipped when none is configured, so the command is useful with no setup
at all — it still reports version skew, pending migrations and manifest drift.

Omeka S serves HTTP 200 on every one of those pages even while its database still needs migrating —
it renders a maintenance page rather than erroring — so the `pages` check inspects the response body,
not just the status code. That is the single best reason to run a health check right after an update.

Results carry one of five statuses. `FAIL` means something is broken and produces a non-zero exit code.
`WARN` is advisory — a slow page, or drift from the manifest — and does not, unless `--strict` is
passed. `INFO` reports a fact with no judgement, such as which modules are deactivated. `SKIP` means a
check could not run because a probe was not configured, which is different from a check that ran and
failed.

### Options

| Option | Default | Effect |
|---|---|---|
| `--url` | the `url` key in `config.json` | The instance base URL. |
| `--json` | off | Emit the report as JSON instead of text. |
| `--strict` | off | Treat warnings as failures for the exit code. |
| `--media-samples` | 5 | How many media records to sample. |
| `--slow-ms` | 5000 | Response time above which a request warns. |
| `--timeout` | 30 | HTTP request timeout, in seconds. |
| `--insecure` | off | Skip TLS certificate verification, for staging environments with self-signed certificates. |

The health checker never writes anything and never changes the instance. It needs no admin credentials,
and works whether or not `config/config.json` exists.

## Contributing

### Distribution manifest

The `distribution.json` file defines the Omeka S core, modules, themes and contents to be included in the 
distribution. The following are the available properties in the manifest:

- `core`: The Omeka S core version to be installed.
  - `url`: The URL to download the Omeka S core package.
  - `version`: The version of the Omeka S core.
- `modules`: The list of modules to be installed.
  - `name`: The name of the module. Note that this is the module ID (normally the same as the module folder name 
    without any spaces) instead of the human-readable name.
  - `url`: The URL to download the module package.
  - `version`: The version of the module.
- `themes`: The list of themes to be installed.
  - `name`: The name of the theme. Note that this is the theme ID (normally the same as the theme folder name 
    without any spaces) instead of the human-readable name.
  - `url`: The URL to download the theme package.
  - `version`: The version of the theme.
- `vocabularies`: The list of vocabularies to be imported.
  - `label`: The label of the vocabulary.
  - `comment`: The comment/description of the vocabulary.
  - `namespace_uri`: The LOV namespace URI of the vocabulary.
  - `prefix`: The LOV prefix of the vocabulary.
  - `file`: The path to the vocabulary file relative to the `vocabularies` directory.
  - `format`: The format of the vocabulary file (e.g., `rdfxml`, `turtle`, `ntriples`, `jsonld`).
- `taxonomies`: The list of taxonomies (custom vocabs) to be imported.
  - `label`: The label of the vocabulary.
  - `file`: The path to the taxonomy file relative to the `taxonomies` directory.
- `resource_templates`: The list of resource templates to be imported.
  - `label`: The label of the resource template.
  - `file`: The path to the resource template file relative to the `resource-templates` directory.

### Vocabularies

All vocabularies included in the distribution should be placed in the `vocabularies` directory. The supported file
formats are `rdfxml`, `turtle`, `ntriples`, and `jsonld`.

After adding a new vocabulary definition file, update the `distribution.json` file to include the new vocabulary.

### Taxonomies

All taxonomies (custom vocabs) included in the distribution should be placed in the `taxonomies` directory. The files
are in the same format as exported from 
[Custom Vocab](https://omeka.org/s/docs/user-manual/modules/customvocab/#manage-custom-vocabs) module.

### Resource Templates

All resource templates included in the distribution should be placed in the `resource-templates` directory. The files
are in the same format as exported from the built-in 
[Resource Templates](https://omeka.org/s/docs/user-manual/content/resource-template/#export-a-resource-template) 
feature in Omeka S. Note that the supported modules should be included in the distribution as well if custom
value types are used in the resource templates.
