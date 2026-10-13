<?php

namespace App\Console\Commands;

use App\Support\MailIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailIdentityCommand extends Command
{
    protected $signature = 'mail:test-identity {to? : Alamat email penerima} {--type=noreply : Tipe identitas (noreply, support, admin)}';
    protected $description = 'Kirim email uji coba untuk memverifikasi identitas pengirim (From & Reply-To) dan sumbernya';

    public function handle(): int
    {
        $type = strtolower((string) ($this->option('type') ?: 'noreply'));
        if (!in_array($type, ['noreply', 'support', 'admin'], true)) {
            $this->error("Tipe tidak valid: '{$type}'. Gunakan 'noreply', 'support', atau 'admin'.");
            return self::FAILURE;
        }

        $noreply = MailIdentity::noreply();
        $support = MailIdentity::support();
        $replyTo = MailIdentity::replyTo();
        $adminRecipients = MailIdentity::adminRecipients();

        $sender = ($type === 'support') ? $support : $noreply;

        $targetArg = $this->argument('to');
        if ($type === 'admin') {
            $recipients = $targetArg ? [$targetArg] : $adminRecipients;
        } else {
            $recipients = $targetArg ? [$targetArg] : [$adminRecipients[0] ?? $support['address']];
        }

        $this->info("==================================================");
        $this->info("   UJI IDENTITAS EMAIL DOMAIN — " . config('app.name', 'VexaHost WA Gateway'));
        $this->info("==================================================");
        $this->line("<comment>Tipe Uji:</comment> {$type}");
        $this->line("<comment>Mailer Driver:</comment> " . config('mail.default'));
        $this->line("<comment>Pengirim (From):</comment> {$sender['name']} <{$sender['address']}> [Sumber: {$sender['source']}]");
        $this->line("<comment>Reply-To:</comment> {$replyTo['name']} <{$replyTo['address']}> [Sumber: {$replyTo['source']}]");
        $this->line("<comment>Penerima:</comment> " . implode(', ', $recipients));
        $this->info("--------------------------------------------------");

        $appName = config('app.name', 'VexaHost WA Gateway');
        $allSuccess = true;

        foreach ($recipients as $recipient) {
            $this->line("Mengirim email uji ke: {$recipient} ...");

            try {
                Mail::raw(
                    "Halo,\n\nIni adalah email uji coba identitas pengirim dari {$appName}.\n\n"
                    . "• Tipe: {$type}\n"
                    . "• From: {$sender['name']} <{$sender['address']}> (Sumber: {$sender['source']})\n"
                    . "• Reply-To: {$replyTo['name']} <{$replyTo['address']}> (Sumber: {$replyTo['source']})\n"
                    . "• Penerima: {$recipient}\n\n"
                    . "Email ini memverifikasi bahwa konfigurasi email domain VexaHost telah aktif dan berjalan semestinya.",
                    function ($message) use ($recipient, $sender, $replyTo, $type, $appName) {
                        $message->to($recipient)
                            ->from($sender['address'], $sender['name'])
                            ->replyTo($replyTo['address'], $replyTo['name'])
                            ->subject("[{$appName}] Uji Identitas Email ({$type})");
                    }
                );

                $this->info("✓ BERHASIL dikirim ke {$recipient}.");
            } catch (\Throwable $e) {
                $allSuccess = false;
                $this->error("✗ GAGAL dikirim ke {$recipient}: " . $e->getMessage());
            }
        }

        return $allSuccess ? self::SUCCESS : self::FAILURE;
    }
}
