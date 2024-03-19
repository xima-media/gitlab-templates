# Gitlab Templates

Collection of reusable GitLab steps.

```yml
include:
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/.deploy-prepare.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/build-php.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/build-node.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-php-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-php-cs-fixer.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-php-stan.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-php-unit.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-php-functional.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-typoscript-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-xml-lint.yml'
  - 'https://raw.githubusercontent.com/xima-media/gitlab-templates/main/test-yaml-lint.yml'
```

## Available jobs

### No stage
* `.deploy-prepare.yml`

### Stage `build`
* `build-php`
* `build-node` ⚠️ needs configuration

### Stage `test`
* `test-php-lint`
* `test-php-cs-fixer`
* `test-php-stan`
* `test-php-unit`
* `test-php-functional`
* `test-typoscript-lint`
* `test-xml-lint`
* `test-yaml-lint`

## Configure jobs

### `build-node`

Add this to your `.gitlab-ci.yml` to configure the node version and asset paths:

```
build-node:
  image:
    name: node:16
  artifacts:
    paths:
      - packages/xm_dkfz_net_site/Resources/Public/Css/dist
      - packages/xm_dkfz_net_site/Resources/Public/JavaScript/dist
```