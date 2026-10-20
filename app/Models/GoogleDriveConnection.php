<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'google_account_id', 'google_email', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'connected_at', 'last_used_at', 'drive_folder_id', 'drive_folder_name', 'drive_resource_key', 'drive_id', 'destination_source', 'destination_selected_at', 'last_export_file_id'])]
class GoogleDriveConnection extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'destination_selected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
