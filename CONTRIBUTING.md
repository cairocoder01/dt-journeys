Thank you for joining us in contributing to Disciple.Tools! These are the guidelines we expect you to follow in writing code that will be used in or with D.T.

### Translations
D.T  is already being used in multiple languages. Please help us make D.T translable by taking  full advantage of Wordpress’ translatable strings. Any string that will be read by the user must be marked as translatable. Ex:
`<label class="section-header"><?php esc_html_e( 'Other', 'dt-journeys' )?></label>`

Make sure you look for these in PHP, HTML and JavaScript code.

### PHPCS
We use [PHPCS](https://github.com/squizlabs/PHP_CodeSniffer) and [PHPCS WordPress Coding Standards](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards) to test for syntax errors, security vulnerabilities and some styling rules. We expect your commits to pass these tests.

In the theme you can run `./tests/test_phpcs.sh` or create a pull request to our repo and Github Actions CI will run the tests for you.

If you are working on a plugin based off our starter plugin run `./includes/admin/test/test_phpcs.sh`

You might need to run `composer install` first.

Note: rules for PHPCS are located in the `phpcs.xml` file. We sometimes update the rule list as PHPCS updates. We’ll update the [starter plugin](https://github.com/cairocoder01/dt-journeys) `phpcs.xml`, you might want to look there to get the latest version.

### Running the tests
The unit tests run against a real WordPress install with the Disciple.Tools theme active, so there is
a one-time setup step before `composer run test` will work.

**Prerequisites**

- PHP 8.1 or newer, and [Composer](https://getcomposer.org/)
- Docker, for the throwaway MariaDB container the tests use
- `subversion` and `jq`. The setup script fetches the WordPress test suite over svn, which macOS has
  not shipped since Xcode 11, and reads the GitHub API with jq. On macOS: `brew install subversion jq`

**One-time setup**

```bash
composer install
composer run test:db-start   # MariaDB container `dt-verify-db` on port 33306
composer run test:install    # downloads WordPress, the WP test suite and the latest D.T theme
```

`test:install` puts WordPress and the test suite in your system temp directory (`$TMPDIR`) and
symlinks this plugin into that install's `wp-content/plugins`. Nothing is written outside the repo and
`$TMPDIR`.

**Running them**

```bash
composer run test                              # all tests, --testdox output
vendor/bin/phpunit --filter JourneysProgress    # a single test class
```

The ▶ run button next to `test` in `composer.json` in VS Code works too, but *only* after the one-time
setup above — it runs PHPUnit and nothing else.

When you're done, `composer run test:db-stop` removes the database container. `composer run test:clean`
deletes the downloaded WordPress and test suite.

### Test setup troubleshooting

**`Could not find .../wordpress-tests-lib/includes/functions.php, have you run tests/install-wp-tests.sh ?`**

The test suite isn't installed. Run the one-time setup above.

If you have already run `test:install` and still see this, the download probably failed partway (most
often because `svn` was missing). The script skips the checkout whenever the tests directory merely
*exists*, so an empty directory left behind by a failed run makes every later attempt report success
and fail here. Clear it and start over:

```bash
composer run test:clean
composer run test:install
```

This error can also appear out of nowhere weeks later: everything lives in `$TMPDIR`, which macOS
purges periodically. Re-running `composer run test:install` is the whole fix.

To keep the install somewhere permanent instead, export `WP_CORE_DIR` and `WP_TESTS_DIR` before both
the install and the test run — put them in your shell profile, and restart VS Code so its run buttons
inherit them:

```bash
export WP_CORE_DIR=~/wp-tests/wordpress
export WP_TESTS_DIR=~/wp-tests/wordpress-tests-lib
```

**`Failed to open stream: No such file or directory ... /wp-content/plugins/dt-journeys/dt-journeys.php`**

The plugin symlink is broken. Two known causes:

1. *Your checkout path contains a space* — for example a clone inside LocalWP's `~/Local Sites/`. The
   setup script does not quote the path when creating the symlink, so word splitting leaves a dangling
   link (plus a stray one named after the first word of the path, which you can delete). `ln` reports
   success either way, so the install looks fine and only fails here.

   The simplest fix is to clone the plugin somewhere without spaces in the path. Otherwise, re-create
   the symlink by hand after running `test:install`:

   ```bash
   ln -sfn "$PWD" "${TMPDIR%/}/wordpress/wp-content/plugins/dt-journeys"
   ```

   Every `composer run test:install` re-creates the broken link, so this has to be repeated each time
   you re-install.

   Setting `WP_PLUGIN_FILE="$PWD/dt-journeys.php"` gets the suite running without a working symlink,
   but it is not a complete substitute: `PluginTest::test_plugin_installed` calls `activate_plugin()`,
   which needs WordPress to actually find the plugin in its plugins directory, so that one test still
   fails (45 of 46 pass).

2. *Your directory is not named `dt-journeys`* — the bootstrap builds the plugin path from the
   directory name, expecting `<dir>/<dir>.php`. A clone named `dt-journeys-fork`, or an unzipped
   GitHub download named `dt-journeys-master`, won't resolve. Rename the directory to `dt-journeys`.

**Database connection errors**

Confirm the container is running with `docker ps` (look for `dt-verify-db`) and start it with
`composer run test:db-start` if not. To use a MySQL server you already have instead of Docker, call
the setup script directly and let it create the database:

```bash
./test/install-wp-tests.sh wordpress_test <user> <pass> 127.0.0.1:3306 latest
```

### GitHub and Commits
For new plugins copy our [starter plugin](https://github.com/cairocoder01/dt-journeys).

To commit to the theme or an existing plugin start by creating a fork of the repository. When you are ready, create a pull request into our repo.

Note: Depending on your context you may wish to use an anonymous GitHub account.

### `WP_DEBUG`
Enable `WP_DEBUG` in your `wp-config.php`: `define('WP_DEBUG', true);`
Checking out a PR and seeing the orange debug table is disappointing.

We look forward to hearing from you!
