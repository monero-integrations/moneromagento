# Monero for Magento
Monero Payment Gateway for Magento 2

## Dependencies
- Magento2 This can be downloaded from [magento.com](https://magento.com/) or from cloning their [github repo](https://github.com/magento/magento2)
- A webserver! preferably with the latest versions of PHP and MySQL
- A Monero wallet and monero-wallet-rpc This can be downloaded from [getmonero.org](https://getmonero.org/downloads/) or cloned from the [Monero-Project github repo](https://github.com/monero-project/monero)

## Install Instructions
### Install with composer
Installing with composer is the easiest way to install this plugin.
- First, add this repo to your root composer.json file. The `"repositories"` section of yout root composer.json should look like this:
`"repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/monero-integrations/moneromagento"
        }
    ],`
- Make sure that your `"minimum-stability"` is set to `"dev"`. It should lool like this `"minimum-stability": "dev",`
- Then you can simply type `php composer require monerointegrations/moneropayment`

## After Install
- Run `php bin/magento setup:upgrade`
- Flush cache with `php bin/magento cache:flush`
- Clean cache with `php bin/magento cache:clean`
