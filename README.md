# NGC Omeka S Distribution

Intro here...

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

- `db`: the database connection information for the Omeka S instance.
  - `host`: the database host (e.g., `localhost`).
  - `port`: the database port (e.g., `3306`).
  - `database`: the name of the database.
  - `username`: the database username.
  - `password`: the database password.
- `apache_user`: The linux user that runs the web server (e.g., `www-data` or `httpd`). This is used to set the correct
  permissions on certain directories.
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

Once it's done, run the update commands to update the installed Omeka S instance.

> [!NOTE]
> The update commands only update the core, modules, and themes of the installed instance based on the changes in
> `distribution.json`. Changes to contents such as vocabularies, taxonomies and resource templates will not be applied
> to prevent data loss and inconsistencies. You may choose to update those contents manually via the Omeka S admin
> interface if needed.

### Updating the instance code

To update the instance code, run the following command:

```bash
php console update:code
```

You can pass the `-y` option to skip the confirmation prompt:

```bash
php console update:code -y
```

This command will check for newer versions of the Omeka S core, modules, and themes based on the 
`distribution.json` file and update the files accordingly.

### Updating the instance database

After updating the instance code, you will need to update the instance database to apply any pending database
migrations. Run the following command:

```bash
php console update:db
```

You can pass the `-y` option to skip the confirmation prompt:

```bash
php console update:db -y
```

Once it's done, log in to the Omeka S admin interface to verify that everything is working correctly.

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
