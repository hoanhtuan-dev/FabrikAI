<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioApiKey extends Model
{
    protected $table = 'studio_api_keys';
    protected $fillable = ['provider', 'label', 'value', 'kind', 'scopes', 'priority', 'enabled', 'note'];
    protected $casts = ['enabled' => 'boolean', 'scopes' => 'array'];

    /**
     * The ciphertext value never reaches the browser — settingsData() returned whole
     * models, and without $hidden the encrypted blob was serialized into the JSON.
     */
    protected $hidden = ['value'];

    /**
     * Encrypt at the model layer so EVERY write path stores ciphertext — not only the
     * controller sites that remember to call Crypt::encryptString(). Before this mutator,
     * a stray StudioApiKey::create($data) or $key->value = $plain would persist plaintext.
     * Reads stay on the raw ciphertext attribute (no get accessor) so studio_api_key_value()
     * keeps decrypting exactly as before.
     */
    public function setValueAttribute($value): void
    {
        $this->attributes['value'] = ($value === null || $value === '')
            ? $value
            : \Illuminate\Support\Facades\Crypt::encryptString($value);
    }
}
