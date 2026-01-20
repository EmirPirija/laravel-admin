<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportReasonTranslation extends Model
{
    use HasFactory;
     protected $fillable = [
        'report_reason_id',
        'language_id',
        'reason'
    ];

    public function reportReason() {
        return $this->belongsTo(ReportReason::class);
    }

    public function language() {
        return $this->belongsTo(Language::class);
    }
}
