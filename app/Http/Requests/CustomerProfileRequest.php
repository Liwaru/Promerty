<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerProfileRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasRole('customer') ?? false; }

    public function rules(): array
    {
        return [
            'name'=>['required','string','max:150'],
            'username'=>['nullable','string','max:50','regex:/^[A-Za-z0-9_.]+$/',Rule::unique('users','username')->ignore($this->user()->id)],
            'bio'=>['nullable','string','max:1000'],
            'photo'=>['nullable','image','mimes:jpeg,png,webp','max:2048','dimensions:max_width=4096,max_height=4096'],
            'role'=>['prohibited'], 'role_id'=>['prohibited'], 'branch_id'=>['prohibited'],
            'permissions'=>['prohibited'], 'status'=>['prohibited'], 'email'=>['prohibited'],
            'phone'=>['prohibited'], 'phone_verified_at'=>['prohibited'], 'email_verified_at'=>['prohibited'],
            'password'=>['prohibited'], 'whatsapp'=>['prohibited'],
        ];
    }
}
