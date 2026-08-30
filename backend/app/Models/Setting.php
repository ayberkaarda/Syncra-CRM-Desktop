<?php

namespace App\Models;

use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Sistem ayarı — key/value, type alanına göre cast edilir (string, integer, boolean, json).
class Setting extends Model
{
    use HasFactory, LogsCrmActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'is_public',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * key'e göre ayarı okur ve type alanına göre cast eder.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return static::castValue($setting->value, $setting->type);
    }

    /**
     * key'e göre ayarı yazar/günceller, değeri type'a uygun şekilde saklar.
     */
    public static function set(string $key, mixed $value): self
    {
        $type = match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };

        $stored = $type === 'json' ? json_encode($value) : (string) $value;

        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type]
        );
    }

    protected static function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
