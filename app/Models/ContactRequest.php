<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** A message sent through a storefront's contact form, kept whether or not the mail went out. */
class ContactRequest extends Model
{
    use IsStoreScoped;
    use IsTenantModel;

    protected $fillable = ['first_name', 'last_name', 'email', 'subject', 'message'];

    protected $casts = ['read_at' => 'datetime'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
