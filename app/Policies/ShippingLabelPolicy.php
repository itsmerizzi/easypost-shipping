<?php

namespace App\Policies;

use App\Models\ShippingLabel;
use App\Models\User;

class ShippingLabelPolicy
{
    public function view(User $user, ShippingLabel $label): bool
    {
        return $label->user_id === $user->id;
    }
}
