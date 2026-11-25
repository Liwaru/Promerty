<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerMessageRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('customer') ?? false; }
    public function rules(): array
    {
        return ['body'=>['required','string','max:4000'], 'sender_member_id'=>['prohibited'],
            'conversation_id'=>['prohibited'], 'user_id'=>['prohibited']];
    }
}
