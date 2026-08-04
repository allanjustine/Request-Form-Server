<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RequestForm extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'form_type',
        'form_data',
        'user_id',
        'noted_by',
        'attachment',
        'approved_by',
        'branch_code',
        'request_code',
        'currency',
        'status',
        'completed_code',
        'kind_of_request',
        'cancelation_reason'
    ];

    protected $attributes = [
        'status' => 'Pending',
    ];

    protected $casts = [
        'attachment' => 'array',
        'form_data' => 'array',
        'approved_by' => 'array',
        'noted_by' => 'array',
        'date'  => 'datetime',
    ];


    // Accessor to decode form_data
    public function getFormDataAttribute($value)
    {
        return json_decode($value, true);
    }

    // Mutator to encode form_data
    public function setFormDataAttribute($value)
    {
        $this->attributes['form_data'] = json_encode($value);
        $this->attributes['attachment'] = json_encode($value);
        $this->attributes['noted_by'] = json_encode($value);
        $this->attributes['approved_by'] = json_encode($value);
    }

    // Relationship definition
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function customApprover()
    {
        return $this->belongsTo(CustomApprovers::class, "custom_approvers_id");
    }

    public function approvalProcess()
    {
        return $this->hasMany(ApprovalProcess::class);
    }
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
    public function branchCode()
    {
        return $this->belongsTo(Branch::class, 'branch_code');
    }

    public function sharedUsers()
    {
        return $this->belongsToMany(User::class, 'shared_requests');
    }
}