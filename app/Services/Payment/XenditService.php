<?php

namespace App\Services\Payment;

use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionService;
use App\Support\PaymentGatewaySetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenditService
{
    private const BASE_URL = 'https://api.xendit.co';

    private ?string $secretKey;
    private ?string $webhookToken;
    private bool $isActive;

    public function __construct()
    {
        $rawSecret = (string) config('services.xendit.secret_key', '');
        $this->secretKey = filled($rawSecret) ? trim($rawSecret, '"\' ') : null;

        $rawWebhook = (string) config('services.xendit.webhook_token', '');
        $this->webhookToken = filled($rawWebhook) ? trim($rawWebhook, '"\' ') : null;
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey);
    }

    public function getWebhookToken(): ?string
    {
        return $this->webhookToken;
    }

    /**
     * Buat invoice pembayaran di Xendit.
     * Menggunakan prefix WAG- pada external_id untuk routing sentral VexaHost.
     *
     * @return array{link: string, id: string}
     */
    public function createInvoice(Invoice $invoice, Workspace $workspace): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Payment gateway Xendit belum dikonfigurasi (Secret Key kosong).');
        }

        // Jika invoice sudah punya link pembayaran Xendit yang aktif, pakai kembali
        if (filled($invoice->payment_url) && $invoice->payment_gateway === 'xendit' && str_contains((string) $invoice->payment_url, 'xendit') && filled($invoice->payment_reference)) {
            return [
                'link' => $invoice->payment_url,
                'id'   => $invoice->payment_reference,
            ];
        }

        $customerName = $workspace->billing_name ?: $workspace->name;
        $customerEmail = $workspace->billing_email ?: ($workspace->owner_email ?: ($workspace->owner?->email ?: 'billing@vexahost.id'));
        $rawPhone = $workspace->billing_phone ?: ($workspace->owner?->phone ?: '');
        $customerMobile = preg_replace('/[^0-9]/', '', (string) $rawPhone);
        if (strlen($customerMobile) < 10) {
            $customerMobile = null;
        }

        $itemDesc = $invoice->isTopup()
            ? 'Pengisian Saldo PAYG VexaHost WA'
            : "Paket {$invoice->plan()->name()} ({$invoice->periodLabel()}) — VexaHost WA";

        $externalId = "WAG-INV-{$invoice->id}-" . time();
        $totalAmount = (int) $invoice->total;

        $redirectUrl = request()->hasHeader('host')
            ? url("/billing/invoices/{$invoice->id}")
            : route('billing.invoice', $invoice);

        $payload = [
            'external_id'          => $externalId,
            'amount'               => $totalAmount,
            'description'          => "Tagihan {$invoice->number} — {$workspace->name}",
            'invoice_duration'     => 86400, // 24 jam
            'currency'             => 'IDR',
            'payer_email'          => $customerEmail,
            'success_redirect_url' => $redirectUrl,
            'failure_redirect_url' => $redirectUrl,
            'customer' => array_filter([
                'given_names'   => $customerName,
                'email'         => $customerEmail,
                'mobile_number' => !empty($customerMobile) ? (string) $customerMobile : null,
            ]),
            'items' => [
                [
                    'name'     => $itemDesc,
                    'quantity' => 1,
                    'price'    => $totalAmount,
                    'category' => 'WhatsApp Gateway Subscription',
                ]
            ],
            'extraData' => [
                'invoice_id'     => (string) $invoice->id,
                'invoice_number' => (string) $invoice->number,
                'workspace_id'   => (string) $workspace->id,
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->timeout(15)
                ->post(self::BASE_URL . '/v2/invoices', $payload);

            if (! $response->successful()) {
                Log::error('Xendit WAGateway create invoice failed', [
                    'status'   => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                    'invoice'  => $invoice->number,
                ]);

                throw new RuntimeException($response->json('message') ?? 'Gagal membuat invoice di Xendit.');
            }

            $data = $response->json();
            $invoiceUrl = $data['invoice_url'] ?? '';
            $invoiceId = $data['id'] ?? '';

            $invoice->forceFill([
                'payment_gateway'   => 'xendit',
                'payment_url'       => $invoiceUrl,
                'payment_reference' => $invoiceId,
                'payment_payload'   => $data,
            ])->save();

            Log::info("Xendit invoice created for WAGateway {$invoice->number}", [
                'external_id' => $externalId,
                'xendit_id'   => $invoiceId,
            ]);

            return [
                'link' => $invoiceUrl,
                'id'   => $invoiceId,
            ];
        } catch (\Throwable $e) {
            Log::error("Xendit WAGateway create invoice exception: " . $e->getMessage(), [
                'invoice' => $invoice->number,
            ]);
            throw $e;
        }
    }

    /**
     * Ambil data invoice dari Xendit.
     */
    public function getInvoice(string $xenditId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->timeout(10)
                ->get(self::BASE_URL . '/v2/invoices/' . $xenditId);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::error("Xendit getInvoice exception: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Sinkronisasi status tagihan jika pending.
     */
    public function checkAndSyncStatus(Invoice $invoice, SubscriptionService $subscriptions): bool
    {
        if ($invoice->isPaid()) {
            return true;
        }

        $xenditId = $invoice->payment_reference;
        if (empty($xenditId)) {
            return false;
        }

        $data = $this->getInvoice($xenditId);
        if (! $data) {
            return false;
        }

        $status = strtoupper($data['status'] ?? '');
        if (in_array($status, ['PAID', 'SETTLED'], true)) {
            $channel = $data['payment_channel'] ?? ($data['payment_method'] ?? 'XENDIT');

            $invoice->forceFill([
                'channel'           => 'xendit',
                'payment_reference' => $xenditId,
                'payment_payload'   => $data,
            ])->save();

            $subscriptions->markPaid(
                invoice: $invoice,
                admin: null,
                note: "Lunas otomatis via Xendit Sync. Ref: {$xenditId} [{$channel}]",
            );

            return true;
        }

        return false;
    }
}
