<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationExculdedWord extends Model
{
    use HasFactory;

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
