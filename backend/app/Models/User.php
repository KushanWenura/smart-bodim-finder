<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['role', 'name', 'email', 'phone', 'avatar_path', 'status', 'password', 'preferred_locale', 'notification_email_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public function tenantProfile(): HasOne
    {
        return $this->hasOne(TenantProfile::class);
    }

    public function ownerProfile(): HasOne
    {
        return $this->hasOne(OwnerProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'owner_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'tenant_id');
    }

    public function tenantViewings(): HasMany
    {
        return $this->hasMany(ViewingRequest::class, 'tenant_id');
    }

    public function ownerViewings(): HasMany
    {
        return $this->hasMany(ViewingRequest::class, 'owner_id');
    }

    public function tenantReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'tenant_id');
    }

    public function ownerReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'owner_id');
    }

    public function verificationEvidence(): HasMany
    {
        return $this->hasMany(VerificationEvidence::class);
    }

    public function isRole(string $role): bool
    {
        return $this->role === $role && $this->status === 'active';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'notification_email_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
