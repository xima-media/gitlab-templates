# Gitlab Templates

Collection of reusable GitLab steps.

```yml
include:
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/.deploy-prepare.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/build-php.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/build-node.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-composer-normalize.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-editorconfig-lint'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-composer-sitepackage.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-es-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-language-lint'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-html-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-cs-fixer.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-stan.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-unit.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-php-functional.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-typoscript-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.6.0/test-yaml-lint.yml'
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


### `build-node`

Add this to your `.gitlab-ci.yml` to configure the node version and asset paths:

```yaml
build-node:
  image:
    name: node:18.17.1-slim
  artifacts:
    paths:
      - packages/xm_dkfz_net_site/Resources/Public/Css/dist
      - packages/xm_dkfz_net_site/Resources/Public/JavaScript/dist
```


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

- Configurable defaults for synced directories, file types and sizes are set in input specs of [reset.yml](reset.yml).
- RESET_UPLOAD_SOURCE_SELECTOR defaults to *stage=live*.

Notes about variables:
* **RESET_JOB** == "*true*" must always be set to indicate this is a reset job
* **RESET_UPLOAD_SOURCE_SELECTOR** and **RESET_DOWNLOAD_TARGET_SELECTOR** can match host names or labels defined in deploy.php:
  * RESET_UPLOAD_SOURCE_SELECTOR == *'hostname'*
  * RESET_DOWNLOAD_TARGET_SELECTOR == *'label=value'*

Default behaviour:
* `RESET_JOB == "true"` -> triggers **reset-upload-source** with RESET_UPLOAD_SOURCE_SELECTOR == *stage=live*
* `RESET_JOB == "true" && RESET_DOWNLOAD_TARGET_SELECTOR == '<selector>'` -> triggers **reset-download-target** for *selector*
* `RESET_JOB == "true" && RESET_UPLOAD_SOURCE_SELECTOR == 'xyz'` -> triggers **reset-upload-source** for host *xyz*

### Usage

Gitlab CI schedules for periodic resets are set as follows:
* Name: 'Upload data from live instance(s) in deploy.php'
  * Cron: '0 14 * * 0'
  * Variables:
    * RESET_JOB == "true"
* Name: 'Reset test instance(s) in deploy.php to previously uploaded data'
  * Cron: '0 15 * * 0'
  * Variables:
    * RESET_JOB == "true"
    * RESET_DOWNLOAD_TARGET_SELECTOR == "stage=test"

Jobs can be triggered manually from a pipeline using Gitlab UI with the same variables:
* Manually upload data from host 'xyz' in deploy.php
  * Variables:
    * RESET_JOB == "true"
    * RESET_UPLOAD_SOURCE_SELECTOR == "xyz"
