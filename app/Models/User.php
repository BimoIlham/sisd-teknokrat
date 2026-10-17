<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'lecturer_id',
        'faculty_id',
        'study_program_id',
        'name',
        'email',
        'password',
        'is_active',
        'must_change_password',
        'is_root_superadmin',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_root_superadmin' => 'boolean',
        ];
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->hasRole('superadmin') && $this->is_root_superadmin;
    }

    public function isLpmStaff(): bool
    {
        return $this->hasAnyRole(['superadmin', 'lpm_admin']);
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole('supervisor');
    }

    public function isOrganizationalStaff(): bool
    {
        return $this->hasAnyRole(['fakultas', 'prodi']);
    }
}
