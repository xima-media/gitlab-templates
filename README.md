# Gitlab Templates

Collection of reusable GitLab steps.

```yml
include:
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/.deploy-prepare.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/build-php.yml'
  # build-node requires inputs since 2.0.0, see "Configure jobs" below
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/build-node.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-composer-normalize.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-editorconfig-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-composer-sitepackage.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-es-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-language-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-html-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-cs-fixer.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-stan.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-unit.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-functional.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-typoscript-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-yaml-lint.yml'
  - remote: 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-typo3-settings.yml'
    inputs:
      skip_rules: ""
  - remote: 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/deploy.yml'
    inputs:
      ci_server_url: https://git.example.com
      live_environment_url: 'https://example.com/'
      test_environment_url: 'https://test.example.com/'
```

## Available jobs by stages

* [No stage]
  * `.deploy-prepare.yml`

* build
  * `build-php`
  * `build-php-no-dev`
  * `build-php-v11`
  * `build-node` ⚠️ needs configuration

* reset
  * `reset-upload-source` ⚠️ needs configuration
  * `reset-download-target` ⚠️ needs configuration

* test
  * `test-composer-normalize`
  * `test-editorconfig-lint`
  * `test-composer-sitepackage` ⚠️ needs configuration
  * `test-es-lint` ⚠️ needs configuration
  * `test-html-lint` ⚠️ needs configuration
  * `test-language-lint`
  * `test-php-lint`
  * `test-php-cs-fixer`
  * `test-php-stan`
  * `test-php-unit`
  * `test-php-functional`
  * `test-typoscript-lint`
  * `test-typo3-settings`
  * `test-xml-lint` ⛔️ deprecated
  * `test-yaml-lint`

* docker.build
* deploy.dev
* test.dev
* deploy.test
  * `deploy-test`
* test.test
* release
* deploy.live
  * `deploy-live`
  * `deploy-live-warmup`
* test.live

## Configure jobs

### `build-php`

You can set custom options for the composer install command via environment variable:

```yaml
variables:
  COMPOSER_INSTALL_OPTIONS: "--ignore-platform-req=ext-ldap"

include:
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/build-php.yml'
```

### `test-composer-sitepackage`

Add this to your `.gitlab-ci.yml` and configure the path of the `composer.json` of your sitepackage:

```yaml
test-composer-sitepackage:
  script:
    - php sitepackage-req-checker.php packages/sitepackage_name/composer.json --ci=sitepackage-req-checker-report.xml
```

### `test-typo3-settings`

Checks that the TYPO3 configuration in the repository only contains production-safe values. `config/system/settings.php` and `additional.php` are deployed and act as fallback on every instance, including Live, whenever the environment sets nothing else. Debug settings belong into `.env` (local, dev) only.

The job reads the active `TYPO3_CONF_VARS` (core defaults, `settings.php`, `additional.php`, `ext_localconf.php`) via `typo3 configuration:showactive` in `Production` context, so the project needs `helhum/typo3-console`. While reading, it temporarily moves the dotenv files (`.env`, `.env.dist`, `.env.local`, `.env.dev`, `.env.dev.local`) aside and restores them afterwards, because a committed local `.env` is not deployed. It checks these rules, grouped by purpose (⚠ = warning only):

| Rule | Requirement |
|---|---|
| **Debug output** | |
| `BE.debug` | `false` |
| `EXTENSIONS.*debug*` | every extension setting with `debug` in its key is falsy |
| `FE.debug` | `false` |
| `SYS.devIPmask` | no entry that matches every address (`*`, `*.*.*.*`, `0.0.0.0/0`, `::/0`); explicit IPs are fine |
| `SYS.displayErrors` | `0` (or `-1` with a `devIPmask` without such an entry) |
| `SYS.productionExceptionHandler` | not the `DebugExceptionHandler` |
| **Security** | |
| `BE.fileDenyPattern` | still blocks `.php`, `.php5`, `.phtml`, `.phar`, `.php.jpg`, `.htaccess` (unset = core default, fine) |
| `BE.lockSSL` | `true` |
| `BE.passwordPolicy` | not `''` and defined in `SYS.passwordPolicies` |
| `FE.pageNotFoundOnCHashError` | `true` |
| `FE.passwordPolicy` | not `''` and defined in `SYS.passwordPolicies` |
| `HTTP.verify` | not `false` (TLS verification of outgoing requests) |
| `SYS.features.security` | `security.backend.enforceReferrer` on, `security.frontend.allowInsecure*` off |
| `SYS.reverseProxyIP` ⚠ | no entry that matches every address |
| `SYS.systemMaintainers` ⚠ | not empty |
| `SYS.trustedHostsPattern` | does not accept any host (e.g. `.*`) |
| **Operation** | |
| `*.devHosts` ⚠ | no DDEV/Docker hosts (`*.ddev.site`, `host.docker.internal`, `ddev-*-db`, DB host `db`) in `DB`, `EXTENSIONS`, `HTTP` |
| `BE.adminOnly` | `0` (`1` also blocks the TYPO3 CLI, the job then fails with that error) |
| `FE.pageUnavailable_force` | `false` |
| `HTTP.timeout` | `> 0` (core default `0` waits forever) |
| `LOG.debug` ⚠ | no enabled log writer at level `debug` |
| `LOG.deprecations` | deprecation log writers disabled |
| `MAIL.defaultMailFromAddress` ⚠ | a valid e-mail address |
| `MAIL.transport` | not `mbox`/`null`, no SMTP mail catcher (Mailpit, MailHog, port 1025) |
| `SYS.caching` ⚠ | no cache with `NullBackend` |
| **Project standards** | |
| `BE.warning_email_addr` | a valid e-mail address |
| `EXTCONF.lang.availableLanguages` | contains `de` |
| `FE.disableNoCacheParameter` | `true` |
| `LOG.Scheduler` ⚠ | an enabled `FileWriter` with `logFile` in `LOG.TYPO3.CMS.Scheduler.writerConfiguration` |
| `SYS.defaultScheme` | `https` |

A failing rule fails the job. A failing warning rule (⚠) or a skipped rule is listed as `WARN` (orange) or `SKIP` (yellow) and the job passes with a warning (exit code 2, `allow_failure: exit_codes`).

No configuration is needed. The include takes these inputs:

| Input | Default | Description |
|---|---|---|
| `skip_rules` | `""` | Comma-separated rules to skip, e.g. `SYS.devIPmask,BE.lockSSL`; skipped rules turn the job into a warning |
| `checker_url` | script of the release tag | URL of `typo3-settings-checker.php`, e.g. to test a branch of this repository |

```yaml
include:
  - remote: 'https://github.xima.workers.dev/gitlab-templates/<release>/test-typo3-settings.yml'
    inputs:
      skip_rules: "SYS.devIPmask,BE.lockSSL"
```

The script lives in [`scripts/typo3-settings-checker.php`](scripts/typo3-settings-checker.php); the job downloads it from the release tag (`checker_url`). To run it locally, copy it into the project root and run:

```bash
ddev exec php typo3-settings-checker.php
```

The local site may fail for the second or two the dotenv files are moved aside. In Development context TYPO3 may use a cached `ext_localconf.php`; run `ddev typo3 cache:flush` after changing one.

### `build-node`

Configured via inputs only. Every package directory needs its own `package.json`,
`package-lock.json` and a `build` script. CI runs `npm ci` + `npm run build` in each one.
All inputs are documented in [build-node.yml](build-node.yml).

`test-es-lint`, `test-html-lint` and `test-typescript-lint` run in the project root and reuse
`node_modules/` from the build-node artifacts. Projects using them must add `node_modules/`
(of the root package) to `artifact_paths`.

```yaml
include:
  - remote: 'https://github.xima.workers.dev/gitlab-templates/2.0.0/build-node.yml'
    inputs:
      image: "node:24-slim@sha256:..."
      # only for multiple packages (default: ".")
      package_dirs: "packages/xima_sitepackage packages/xima_api_client"
      artifact_paths:
        - packages/xima_sitepackage/Resources/Public/Css/dist
        - packages/xima_api_client/Resources/Public/JavaScript/dist
      # only for multiple packages (default: package-lock.json, max. 2 files)
      cache_key_files:
        - packages/xima_sitepackage/package-lock.json
        - packages/xima_api_client/package-lock.json
```

#### Migration 1.x → 2.0.0

`image` is now a required input. Without it, the pipeline is not created.

1. Add `inputs.image` to the `build-node.yml` include (keep the digest).
2. Move `artifacts.paths` of your `build-node` job to `inputs.artifact_paths`.
   Keep `node_modules/` only if the project depends on artifacts in following jobs.
3. Delete the local `build-node:` job. A local `script` or `cache` would override the template.
4. Projects that built several packages in a custom `script`: use `package_dirs` and `cache_key_files`.


### `test-es-lint`

Add this to your `.gitlab-ci.yml` to configure the node version:

```yaml
test-es-lint:
  image:
    name: node:18.17.1-slim
```

### `test-html-lint`

Add this to your `.gitlab-ci.yml` to configure the node version:

```yaml
test-html-lint:
  image:
    name: node:18.17.1-slim
```

## Configure Reset jobs

Reset jobs are useful for syncing assets and databases between environments.

[Rclone](https://rclone.org/) supports various storage providers to accomplish that:
- [S3 remote](https://rclone.org/s3/) for file storage
- [CRYPT remote](https://rclone.org/crypt/) for client-side encryption to S3 remote
- [SFTP remote](https://rclone.org/sftp/) for synchronisation with SSH hosts.

### Prerequisites

* PHP Deployer deploy.php with SSH hosts
* S3 storage provider
* Gitlab **file variable** in project settings: `RCLONE_RESET_STORAGE_ENV`

*RCLONE_RESET_STORAGE_ENV*
```bash
# Rclone configuration with environment variables for S3 backend: https://rclone.org/docs/#config-file and https://rclone.org/s3/
RCLONE_CONFIG_STORAGE_TYPE=s3
RCLONE_CONFIG_STORAGE_PROVIDER=AWS
RCLONE_CONFIG_STORAGE_ACCESS_KEY_ID=<secret>
RCLONE_CONFIG_STORAGE_SECRET_ACCESS_KEY=<secret>
RCLONE_CONFIG_STORAGE_ENDPOINT=eu-central-1
RCLONE_CONFIG_STORAGE_REGION=EU
RCLONE_CONFIG_STORAGE_ACL=private
# Crypt remote for client-side encryption is created on the fly from these variables in *.reset-prepare* job
# GitLab CI/CD variable expansion must be disabled!
CRYPT_NAME=<crypt_name>
CRYPT_REMOTE="storage:<s3-bucket-name>/${CRYPT_NAME}"
CRYPT_PASSWORD=<secret>
CRYPT_PASSWORD2=<secret>
```

### Defaults

Configurable defaults for synced directories, file types and sizes are set in input specs of [reset.yml](reset.yml).

Notes about variables:
* **RESET_JOB** must be set to *"true"* to trigger reset jobs.
* **RESET_UPLOAD_SOURCE_SELECTOR** and **RESET_DOWNLOAD_TARGET_SELECTOR** can match host names or labels defined in deploy.php:
  * RESET_UPLOAD_SOURCE_SELECTOR == *'hostname'*
  * RESET_DOWNLOAD_TARGET_SELECTOR == *'label=value'*
* **RESET_UPLOAD_SOURCE_SELECTOR** defaults to *stage=live* when unset.
* **reset-download-target** aborts if the last deployed commit branch on target does not match `BRANCH_ID` (the `$CI_COMMIT_BRANCH` of the pipeline run, normalized by truncating at the first underscore).

Default behaviour:
* `RESET_JOB == "true"` and `RESET_DOWNLOAD_TARGET_SELECTOR,RESET_UPLOAD_SOURCE_SELECTOR` unset -> triggers **reset-upload-source**
* `RESET_JOB == "true"` and `RESET_UPLOAD_SOURCE_SELECTOR == '<selector>'` -> triggers **reset-upload-source** for host *<selector>*
* `RESET_JOB == "true"` and `RESET_DOWNLOAD_TARGET_SELECTOR == '<selector>'` -> Triggers **reset-download-target** for *<selector>*, which validates that this selector already is a deployment target of the $CI_COMMIT_BRANCH from which the pipeline was run before importing assets + database, followed up by **deploy-test** if applicable (no Release created in this case!).

### Usage

Gitlab CI schedules for periodic resets are set as follows:
* Name: 'Upload data from live instance(s) in deploy.php'
  * Cron: '0 14 * * 0'
  * Variables:
    * RESET_JOB == "true"
    * RESET_UPLOAD_SOURCE_SELECTOR == "stage=live"
  * Run for branch name:
    * Any, does not need to match "stage=live" for upload.

* Name: 'Reset test instance(s) in deploy.php to previously uploaded data'
  * Cron: '0 15 * * 0'
  * Variables:
    * RESET_JOB == "true"
    * RESET_DOWNLOAD_TARGET_SELECTOR == "stage=test"
  * Run for branch name:
    * Branch that is already associated with hosts matching 'stage=test'.

Jobs can be triggered manually from a pipeline using Gitlab UI with the same variables:
* Reset host 'xyz' in deploy.php to previously uploaded data
  * Variables:
    * RESET_JOB == "true"
    * RESET_DOWNLOAD_TARGET_SELECTOR == "xyz"
  * Run for branch name:
    * Branch that is already associated with host 'xyz'.
