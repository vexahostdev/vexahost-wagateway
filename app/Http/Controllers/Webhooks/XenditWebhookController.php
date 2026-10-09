<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentGatewayLog;
use App\Services\Billing\SubscriptionService;
use App\Services\Payment\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly XenditService $xendit,
    ) {}

    /**
     * Menangani callback webhook Xendit (baik yang diteruskan dari VexaHost pusat maupun langsung).
     */
    public function handle(Request $request): JsonResponse
    {
        // Respond to GET ping / healthcheck
        if ($request->isMethod('get')) {
            return response()->json([
                'status'  => 'ok',
                'message' => 'WAGateway Xendit webhook endpoint is active.',
            ], 200);
        }

        // 1. Validasi Autentikasi: Cek Internal Token (dari VexaHost Forwarder) atau Callback Token Xendit
        $internalToken = $request->header('X-Internal-Token');
        $callbackToken = $request->header('x-callback-token')
            ?? $request->header('X-CALLBACK-TOKEN')
            ?? $request->input('callback_token');

        $expectedInternal = config('services.xendit.internal_secret', 'vexahost_internal_xnd_token_38c92a');
        $expectedCallback = config('services.xendit.webhook_token') ?: $this->xendit->getWebhookToken();

        $authorized = false;
        if ($internalToken && hash_equals((string) $expectedInternal, (string) $internalToken)) {
            $authorized = true;
        } elseif ($callbackToken && hash_equals((string) $expectedCallback, (string) $callbackToken)) {
            $authorized = true;
        }

        if (! $authorized) {
            Log::warning('WAGateway Xendit webhook unauthorized', [
                'ip' => $request->ip(),
                'internal_token' => $internalToken ? 'provided' : 'missing',
                'callback_token' => $callbackToken ? 'provided' : 'missing',
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized webhook token or internal secret.',
            ], 403);
        }

        $payload = $request->all();
        $externalId = (string) ($payload['external_id'] ?? '');
        $xenditId = (string) ($payload['id'] ?? '');
        $status = strtoupper((string) ($payload['status'] ?? ''));

        // 2. Simpan audit log gateway masuk
        $log = PaymentGatewayLog::create([
            'gateway'      => 'xendit',
            'event'        => $status,
            'reference_id' => $xenditId ?: $externalId,
            'status'       => 'pending',
            'payload'      => $payload,
        ]);

        // Tanggapi uji coba / ping test
        if (in_array(strtolower($status), ['test', 'ping'], true)) {
            $log->update(['status' => 'processed']);
            return response()->json([
                'status'  => 'success',
                'message' => 'Webhook test ping acknowledged.',
            ], 200);
        }

        // 3. Hanya proses pembayaran sukses
        if (! in_array($status, ['PAID', 'SETTLED'], true)) {
            $log->update(['status' => 'ignored']);
            return response()->json([
                'status'  => 'ignored',
                'message' => "Status '{$status}' diabaikan.",
            ], 200);
        }

        // 4. Cari tagihan berdasarkan:
        //    a) extraData.invoice_id
        //    b) external_id (format: WAG-INV-{id}-{time})
        //    c) payment_reference == xenditId
        //    d) number == extraData.invoice_number
        $invoice = null;
        $extraInvoiceId = $payload['extraData']['invoice_id'] ?? null;
        if ($extraInvoiceId) {
            $invoice = Invoice::find($extraInvoiceId);
        }

        if (! $invoice && preg_match('/^WAG-INV-(\d+)/i', $externalId, $m)) {
            $invoice = Invoice::find((int) $m[1]);
        }

        if (! $invoice && filled($xenditId)) {
            $invoice = Invoice::where('payment_reference', $xenditId)->first();
        }

        if (! $invoice && filled($payload['extraData']['invoice_number'] ?? null)) {
            $invoice = Invoice::where('number', $payload['extraData']['invoice_number'])->first();
        }

        if (! $invoice) {
            $msg = 'Tagihan tidak ditemukan untuk referensi Xendit: ' . ($xenditId ?: $externalId);
            Log::warning($msg, ['payload' => $payload]);
            $log->update([
                'status'        => 'failed',
                'error_message' => $msg,
            ]);

            return response()->json(['status' => 'not_found', 'message' => $msg], 200);
        }

        $log->update(['invoice_id' => $invoice->id]);

        // 5. Idempotency Guard: jika sudah lunas, jangan proses ulang
        if ($invoice->isPaid()) {
            $log->update(['status' => 'processed']);
            return response()->json([
                'status'  => 'already_paid',
                'message' => "Tagihan {$invoice->number} sudah berstatus lunas sebelumnya.",
            ]);
        }

        // 6. Eksekusi Pelunasan via SubscriptionService
        try {
            $channel = $payload['payment_channel'] ?? ($payload['payment_method'] ?? 'XENDIT');

            $invoice->forceFill([
                'channel'           => 'xendit',
                'payment_reference' => $xenditId ?: $externalId,
                'payment_payload'   => $payload,
            ])->save();

            $this->subscriptions->markPaid(
                invoice: $invoice,
                admin:   null,
                note:    "Lunas otomatis via Xendit. Ref: {$xenditId} [{$channel}]",
            );

            $log->update(['status' => 'processed']);

            Log::info("Tagihan WAGateway {$invoice->number} berhasil dilunasi via Xendit webhook.", [
                'invoice_id' => $invoice->id,
                'xendit_id'  => $xenditId,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Tagihan {$invoice->number} berhasil dilunasi.",
            ]);
        } catch (\Throwable $e) {
            Log::error("Gagal melunasi tagihan WAGateway {$invoice->number} dari webhook Xendit: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal memproses pelunasan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
