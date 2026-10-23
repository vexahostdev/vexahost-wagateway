<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'wa_session_id',
        'api_key_id',
        'direction',
        'wa_message_id',
        'chat_id',
        'to_number',
        'from_number',
        'type',
        'body',
        'media_path',
        'media_mime',
        'media_filename',
        'status',
        'error',
        'attempts',
        'provider_response',
        'batch_id',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WaSession::class, 'wa_session_id');
    }

    /**
     * Status hanya boleh maju (queued -> sent -> delivered -> read). Event ack
     * dari WhatsApp bisa datang tidak berurutan, dan tanpa penjagaan ini pesan
     * yang sudah "read" bisa turun lagi jadi "delivered".
     */
    public function advanceStatus(string $status): bool
    {
        $rank = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

        if ($status === 'failed') {
            $this->status = 'failed';

            return true;
        }

        if (! isset($rank[$status]) || ! isset($rank[$this->status])) {
            return false;
        }

        if ($rank[$status] <= $rank[$this->status]) {
            return false;
        }

        $this->status = $status;

        return true;
    }
}
