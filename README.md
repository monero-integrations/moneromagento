# Monero for Magento
Monero Payment Gateway for Magento 2

## Dependencies
- Magento 2.4.x / Mage-OS 3.x
- PHP 8.1, 8.2, 8.3, 8.4, or 8.5 with `bcmath`, `curl`, and `json`
- MySQL-compatible database supported by the Magento installation
- A Monero wallet and `monero-wallet-rpc` from [getmonero.org](https://getmonero.org/downloads/) or the [Monero Project GitHub repo](https://github.com/monero-project/monero)

Tested with Magento Open Source 2.4.9 and Mage-OS 3.0.0. The payment page is order-specific and must not be served from full-page cache.

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
- The wallet-rpc network must match the addresses your customers expect: connect a mainnet wallet for a live store, or stagenet/testnet only for testing. The module reads incoming payments from wallet account `0`.
- The method stays hidden at checkout until the wallet-rpc host and port are configured.
- The bundled QR renderer is Kazuhiko Arase's MIT-licensed QR Code Generator for JavaScript.
