# Monero for Magento
Monero Payment Gateway for Magento 2

## Dependencies
- Magento2 This can be downloaded from [magento.com](https://magento.com/) or from cloning their [GitHub repo](https://github.com/magento/magento2)
- A webserver! preferably with the latest versions of PHP and MySQL
- A Monero wallet and monero-wallet-rpc This can be downloaded from [getmonero.org](https://getmonero.org/downloads/) or cloned from the [Monero-Project GitHub repo](https://github.com/monero-project/monero)

## Install Instructions
### Install with composer
Installing with composer is the easiest way to install this plugin.
- First, add this repo to your root composer.json file. The `"repositories"` section of your root composer.json should look like this:
`"repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/monero-integrations/moneromagento"
        }
    ],`
- Make sure that your `"minimum-stability"` is set to `"dev"`. It should look like this `"minimum-stability": "dev",`
- Then you can simply type `php composer require monerointegrations/moneropayment`

## After Install
### Clear Cache
- Run `php bin/magento setup:upgrade`
- Flush cache with `php bin/magento cache:flush`
- Clean cache with `php bin/magento cache:clean`

### Setting-Up monero-wallet-rpc
- Start up your monero-wallet-rpc with the following command: `./monero-wallet-rpc --rpc-bind-port 18082 --disable-rpc-login --log-level 2 --wallet-file /path/walletfile`. It is recommended that you use the `--rpc-login` flag.

### Setup
- First, navigate to you site admin panel
-Within that admin panel, navigate to `Stores > Configuration > Sales > Payment Methods`.
- Under "Other Payment Methods" select "Monero Payment"
- Select "Yes" for "Enabled" and enter your monero-wallet-rpc address and port
- "Number of confirmations" - Number of confirmations the transaction must recieve before the order is marked as complete. Use `0` for nearly instant confirmation. (Default: 5)
