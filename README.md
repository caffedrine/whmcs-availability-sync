## WHMCS Products/Services Quantity Sync

PHP Script used to syncronize prices from **online.net** with your products prices in case you are a reseller!

Add your own products from line 140. The ID is the id of the product from WHMCS database and description is corespondent name from **online.net** .

## Usage

Place **sync.php** into directory */crons* under your whmcs root!

Create a new cronjob task schedule it to make a GET request every hour on php script you just uploaded:

```
whmcs_root/crons/sync.php
```