<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\BTCPay\BTCPay;

Route::post('/extensions/btcpay/webhook', [BTCPay::class, 'webhook'])->name('extensions.gateways.btcpay.webhook');
