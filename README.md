# Monero for Magento
Monero Payment Gateway for Magento 2

## Dependencies
- Magento 2.4.x / Mage-OS 3.x
- PHP 8.1, 8.2, 8.3, 8.4, or 8.5 with `bcmath`, `curl`, and `json`
- MySQL-compatible database supported by the Magento installation
- A Monero wallet and `monero-wallet-rpc` from [getmonero.org](https://getmonero.org/downloads/) or the [Monero Project GitHub repo](https://github.com/monero-project/monero)

Tested with Magento Open Source 2.4.9 and Mage-OS 3.0.0. The order-specific payment route is intentionally uncacheable and sends no-store headers because it contains customer-specific payment data.

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
- For local testing, start `monero-wallet-rpc` with a command like: `./monero-wallet-rpc --rpc-bind-port 18082 --disable-rpc-login --log-level 2 --wallet-file /path/walletfile`.
- For production, use `--rpc-login username:password` and configure the same username and password in Magento.

### Setup
- First, navigate to your site admin panel
- Within that admin panel, navigate to `Stores > Configuration > Sales > Payment Methods`.
- Under "Other Payment Methods" select "Monero Payment"
- Select "Yes" for "Enabled" and enter your monero-wallet-rpc host, port, and login details if `--rpc-login` is enabled. The host field is a bare host/IP only, without `http://`, port, or path.
- Plain unauthenticated wallet-rpc is allowed only for localhost or an SSH tunnel. Remote wallet-rpc hosts must use both HTTPS and RPC login.
- "Number of confirmations" - Number of confirmations the transaction must receive before the order is marked as paid. Use `0` to accept the first mined transaction; pool transactions are not final. (Default: 5)
- For fiat stores, configure a CryptoCompare API key or set a manual XMR rate plus its three-letter currency. The manual rate is the price of 1 XMR in that currency and is intended for local tests or controlled deployments where the store operator manages pricing. The unauthenticated CryptoCompare endpoint is rate-limited, so production fiat stores should set an API key.
- For orders denominated in XMR, the order total is used directly and no exchange-rate lookup is performed. Configuring Magento itself to use XMR as a store currency is outside this module's scope.
- The wallet-rpc network must match the addresses your customers expect: connect a mainnet wallet for a live store, or stagenet/testnet only for testing. The module reads incoming payments from wallet account `0`.
- When the method is enabled, incomplete or unsafe wallet-RPC settings are rejected with an admin save error. Checkout also hides the method if inherited or imported configuration is invalid. This validation does not test live wallet-RPC connectivity.
- The bundled QR renderer is [QR Code Generator for JavaScript 2.0.4](https://github.com/kazuhikoarase/qrcode-generator/tree/js2.0.4), Copyright (c) 2009 Kazuhiko Arase, distributed under the MIT license. The upstream license notice is included with the bundled file.
