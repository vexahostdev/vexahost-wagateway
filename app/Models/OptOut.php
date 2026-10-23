<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penerima yang meminta berhenti menerima pesan dari satu workspace,
 * dengan membalas kata seperti STOP atau BERHENTI.
 *
 * Per workspace, bukan per nomor pengirim: orang yang membalas STOP ke
 * toko A meminta berhenti dari toko A, lewat nomor mana pun toko itu
 * mengirim. Toko B tidak terpengaruh.
 */
class OptOut extends Model
{
    protected $fillable = ['workspace_id', 'phone', 'keyword'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
