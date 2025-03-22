<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Classes\Extension\Extension;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class BTCPay extends Gateway
{

    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    /**
     * Get all the configuration for the extension
     * 
     * @param array $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'instance_url',
                'label' => 'Instance URL',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'store_id',
                'label' => 'Store ID',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Secret',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'order_prefix',
                'label' => 'Order Prefix',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function getEndpoint()
    {
        $instanceUrl = $this->config('instance_url');
        $storeId = $this->config('store_id');
        return $instanceUrl . '/api/v1/stores/' . $storeId . '/invoices';
    }
    
    /**
     * Return a view or a url to redirect to
     * 
     * @param Invoice $invoice
     * @param float $total
     * @return string
     */
    public function pay(Invoice $invoice, $total)
    {
        $endpoint = $this->getEndpoint();
        $orderId = $this->config('order_prefix') . $invoice->id;
        $user = $invoice->user;
        $userName = $user->name;
        $userEmail = $user->email;
        $invoiceRoute = route('invoices.show', ['invoice' => $invoice->id]);
        $payload = [
            'metadata' => [
                'orderId' => $orderId,
                'buyerName' => $userName,
                'buyerEmail' => $userEmail,
            ],
            'checkout' => [
                'redirectURL' => $invoiceRoute,
            ],
            'amount' => $total,
            'currency' => $invoice->currency_code,
        ];
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'token ' . $this->config('api_key'),
        ])->post($endpoint, $payload);
        $responseJson = $response->json();
        
        if ($response->failed()) {
            Log::error('BTCPay Payment failed', [
                'invoice' => $invoice->id,
                'total' => $total,
                'response' => $responseJson,
            ]);
            return redirect()->route('invoices.show', ['invoice' => $invoice->id])->with('notification', [
                'message' => 'Payment failed',
                'type' => 'error',
            ]);
        }
        $checkoutUrl = $responseJson['checkoutLink'];
        return $checkoutUrl;
    }

    public function getBTCPayInvoice($invoiceId)
    {
        $apiKey = $this->config('api_key');
        $endpoint = $this->getEndpoint() . '/' . $invoiceId;
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'token ' . $apiKey,
        ])->get($endpoint);

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    public function webhook(Request $request)
    {
        $webhookSecret = $this->config('webhook_secret');
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody);
        $metadata = $payload->metadata;
        $reqSig = $request->header('BTCPay-Sig');
        $sigHashAlg = 'sha256';

        if (empty($rawBody)) {
            Log::error('BTCPay Webhook', ['error' => 'Missing request body']);
            return response()->json(['error' => 'Missing request body'], 400);
        }

        $eventType = $payload->type;

        if ($eventType !== 'InvoiceSettled') {
            return;
        }

        $hmac = hash_hmac($sigHashAlg, $rawBody, $webhookSecret);
        $digest = $sigHashAlg . '=' . $hmac;

        if (!hash_equals($reqSig, $digest)) {
            Log::error('BTCPay Webhook', ['error' => 'Invalid signature', 'reqSig' => $reqSig, 'digest' => $digest]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        if ($metadata === null) {
            Log::error('BTCPay Webhook', ['error' => 'Missing metadata']);
            return response()->json(['error' => 'Missing metadata'], 400);
        }

        $orderId = $metadata->orderId;
        $invoiceId = $this->extractInvoiceId($orderId);
        $invoice = Invoice::find($invoiceId);

        if (!$invoice) {
            Log::error('BTCPay Webhook', ['error' => 'Invoice not found', 'invoiceId' => $invoiceId]);
            return response()->json(['error' => 'Invoice not found'], 400);
        }

        $btcPayInvoiceId = $payload->invoiceId;
        $btcPayInvoice = $this->getBTCPayInvoice($btcPayInvoiceId);
        $amount = $btcPayInvoice['amount'];
        ExtensionHelper::addPayment($invoiceId, 'BTCPay', $amount, null, $orderId);
        return response()->json(['success' => true]);
    }

    public function extractInvoiceId($orderId)
    {
        $orderPrefix = $this->config('order_prefix');
        $invoiceId = (int) substr($orderId, strlen($orderPrefix));
        return $invoiceId;
    }
}
