<?php

namespace App\Models;

use App\Enums\GoogleDriveExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveExport extends Model
{
    protected $fillable = ['user_id', 'export_type', 'scope_key', 'drive_file_id', 'destination_folder_id', 'status', 'error', 'last_attempt_at', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'status' => GoogleDriveExportStatus::class,
            'last_attempt_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function forDestination(int $userId, string $type, string $scope, string $destination): self
    {
        $record = static::query()->where('user_id', $userId)
            ->where('export_type', $type)->where('scope_key', $scope)
            ->where('destination_folder_id', $destination)->first();
        if ($record) {
            return $record;
        }
        // Adopt a legacy row the first time it is used by the consolidated export.
        $legacy = static::query()->where('user_id', $userId)->where('export_type', $type)
            ->where('scope_key', $scope)->whereNull('destination_folder_id')->first();
        if ($legacy) {
            $legacy->update(['destination_folder_id' => $destination]);

            return $legacy->fresh();
        }

        return static::create(['user_id' => $userId, 'export_type' => $type, 'scope_key' => $scope, 'destination_folder_id' => $destination]);
    }
}
