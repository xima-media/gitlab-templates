# Gitlab Templates

Collection of reusable GitLab steps.

```yml
include:
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/.deploy-prepare.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/build-php.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/build-node.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-composer-normalize.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-editorconfig-lint'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-composer-sitepackage.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-es-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-language-lint'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-html-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-php-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-php-cs-fixer.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-php-stan.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-php-unit.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-php-functional.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-typoscript-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-xml-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/1.0.0/test-yaml-lint.yml'
```

## Available jobs

### No stage
* `.deploy-prepare.yml`

### Stage `build`
* `build-php`
* `build-php-v11`
* `build-node` ⚠️ needs configuration

### Stage `test`
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
* `test-xml-lint`
* `test-yaml-lint`

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
