<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\User;
use App\Observers\LinkedAccountObserver;
use App\Services\Providers\ProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class);
    }

    public function boot(): void
    {
        // Cegah Chrome warning: "was preloaded using link preload but not used" akibat Cloudflare Early Hints / Rocket Loader
        Vite::usePreloadTagAttributes(fn () => false);

        if (
            str_contains(request()->header('x-forwarded-proto', ''), 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || request()->isSecure()
        ) {
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();

        // Setiap perubahan identitas masuk ikut dikirim ke aplikasi vexahost,
        // dari jalur mana pun perubahannya datang. Lihat docs/AKUN_TERTAUT.md.
        User::observe(LinkedAccountObserver::class);
    }

    /**
     * Rate limit REST API dihitung per API key, bukan per IP.
     *
     * Beberapa aplikasi Flustra berjalan di VPS yang sama, sehingga limit
     * per IP akan membuat mereka saling menghabiskan jatah satu sama lain.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-key', function (Request $request) {
            $key = $request->attributes->get('api_key');

            if (! $key instanceof ApiKey) {
                return Limit::perMinute(30)->by($request->ip());
            }

            $perMinute = $key->rate_limit_per_minute
                ?? $key->workspace->api_rate_limit_per_minute
                ?? config('gateway.defaults.api_rate_limit_per_minute');

            return Limit::perMinute($perMinute)->by("key:{$key->id}");
        });

        /*
         | Form Enterprise terbuka untuk tamu, jadi ia butuh batasnya sendiri.
         |
         | Dihitung per IP saja — tamu tidak punya identitas lain — dan tiga per
         | jam sudah jauh di atas kebutuhan orang sungguhan: satu permintaan
         | penawaran per perusahaan, sekali. Tanpa ini, satu skrip bisa mengisi
         | tabelnya sampai permintaan yang sungguhan tidak bisa ditemukan lagi
         | di antara ribuan baris sampah.
        */
        RateLimiter::for('enterprise', fn (Request $request) => Limit::perHour(3)->by($request->ip()));

        // Melindungi form login dari percobaan menebak password.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
