<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAcknowledgement extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'document_id', 'user_id', 'assigned_name', 'acknowledged_at', 'remarks', 'from_office'
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime'
    ];

    protected static ?bool $hasFromOfficeColumn = null;

    public static function checkFromOfficeColumn()
    {
        if (self::$hasFromOfficeColumn === null) {
            self::$hasFromOfficeColumn = \Illuminate\Support\Facades\Schema::hasColumn('document_acknowledgements', 'from_office');
        }
        return self::$hasFromOfficeColumn;
    }

    protected static function booted()
    {
        static::saving(function ($ack) {
            if (!static::checkFromOfficeColumn()) {
                if (isset($ack->attributes['from_office'])) {
                    $fromOffice = $ack->attributes['from_office'];
                    unset($ack->attributes['from_office']);
                    
                    $remarks = $ack->attributes['remarks'] ?? '';
                    $remarks = preg_replace('/^\[FROM_OFFICE:[^\]]*\]\r?\n?/', '', $remarks);
                    
                    if ($fromOffice) {
                        $ack->attributes['remarks'] = "[FROM_OFFICE:{$fromOffice}]\n" . $remarks;
                    } else {
                        $ack->attributes['remarks'] = $remarks;
                    }
                }
            }
        });
    }

    public function getFromOfficeAttribute($value)
    {
        if (!static::checkFromOfficeColumn()) {
            $remarks = $this->attributes['remarks'] ?? '';
            if (preg_match('/^\[FROM_OFFICE:([^\]]*)\]/', $remarks, $matches)) {
                return $matches[1];
            }
            return null;
        }
        return $value;
    }

    public function getRemarksAttribute($value)
    {
        if (!static::checkFromOfficeColumn() && $value) {
            return preg_replace('/^\[FROM_OFFICE:[^\]]*\]\r?\n?/', '', $value);
        }
        return $value;
    }

    public function toArray()
    {
        $array = parent::toArray();
        if (!static::checkFromOfficeColumn()) {
            $array['from_office'] = $this->from_office;
        }
        return $array;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
