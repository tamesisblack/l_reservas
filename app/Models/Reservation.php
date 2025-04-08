<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;
   
    protected $fillable = [
        'user_id',
        'consulta_id',
        'reservation_date',
        'start_time',
        'end_time',
        'reservation_status',
        'total_amount',
        'payment_status',
        'cancellation_reason',
    ];

    // relationships

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function consulta()
    {
        return $this->belongsTo(User::class,'consulta_id');
    }   

    
}
