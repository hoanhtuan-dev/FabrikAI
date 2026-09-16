<?php

namespace Tests\Unit;

use App\Models\StudioApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Guards the API-key exposure fix.
 *
 * Before: StudioApiKey had no $hidden, so settingsData() serialized the encrypted
 * blob into the browser JSON, and no model-level mutator existed — meaning any write
 * path that forgot to call Crypt::encryptString() persisted the key in plaintext.
 *
 * Now: 'value' is hidden from serialization, and a setValueAttribute mutator encrypts
 * on every write. These tests pin both the leak-fix and the round-trip the runtime read
 * path (studio_api_key_value) depends on.
 */
class StudioApiKeyEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_encrypts_at_model_layer_and_round_trips(): void
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen', 'label' => 't', 'value' => 'sk-plain-secret',
            'kind' => 'bearer', 'priority' => 5, 'enabled' => true,
        ]);

        // The DB column must NOT contain the plaintext.
        $raw = $key->getRawOriginal('value');
        $this->assertNotSame('sk-plain-secret', $raw);
        $this->assertNotSame('', $raw);

        // And it must decrypt back to the plaintext the runtime expects.
        $this->assertSame('sk-plain-secret', Crypt::decryptString($raw));
        $this->assertSame('sk-plain-secret', studio_api_key_value($key->value));
    }

    public function test_value_is_hidden_from_array_json_serialization(): void
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen', 'label' => 't', 'value' => 'sk-leak-me',
            'kind' => 'bearer', 'priority' => 5, 'enabled' => true,
        ]);

        $arr = $key->toArray();
        $json = json_encode($arr);

        $this->assertArrayNotHasKey('value', $arr, 'value must be hidden so settingsData() does not leak ciphertext');
        $this->assertStringNotContainsString('sk-leak-me', $json);
        $this->assertStringNotContainsString($key->getRawOriginal('value'), $json);
    }

    public function test_direct_assignment_encrypts_and_round_trips(): void
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen', 'label' => 't', 'value' => 'sk-first',
            'kind' => 'bearer', 'priority' => 5, 'enabled' => true,
        ]);

        $key->value = 'sk-rotated';
        $key->save();

        $raw = $key->fresh()->getRawOriginal('value');
        $this->assertNotSame('sk-rotated', $raw);
        $this->assertSame('sk-rotated', Crypt::decryptString($raw));
        $this->assertSame('sk-rotated', studio_api_key_value($key->fresh()->value));
    }

    public function test_update_without_value_field_preserves_existing_ciphertext(): void
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen', 'label' => 't', 'value' => 'sk-keep',
            'kind' => 'bearer', 'priority' => 5, 'enabled' => true,
        ]);
        $originalRaw = $key->getRawOriginal('value');

        // Update only the label — must not touch the stored value.
        $key->update(['label' => 'renamed']);

        $fresh = $key->fresh();
        $this->assertSame($originalRaw, $fresh->getRawOriginal('value'));
        $this->assertSame('sk-keep', Crypt::decryptString($fresh->getRawOriginal('value')));
    }

    public function test_empty_value_is_stored_verbatim_and_does_not_encrypt(): void
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen', 'label' => 't', 'value' => '',
            'kind' => 'bearer', 'priority' => 5, 'enabled' => true,
        ]);

        $this->assertSame('', $key->getRawOriginal('value'));
    }
}
