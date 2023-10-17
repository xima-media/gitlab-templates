# Gitlab Templates

## Available jobs

* `build-php`
* `build-node` ⚠️ needs configuration
* `test-php-lint`
* `test-php-cs-fixer`
* `test-php-stan`
* `test-xml-lint`

## Configure jobs

### `build-node`

```
build-node:
  image:
    name: node:16
  artifacts:
    paths:
      - packages/xm_dkfz_net_site/Resources/Public/Css/dist
      - packages/xm_dkfz_net_site/Resources/Public/JavaScript/dist
```