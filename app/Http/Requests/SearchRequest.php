<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        foreach (['q','location'] as $key) {
            if (is_string($this->input($key))) $this->merge([$key=>preg_replace('/\s+/u',' ',trim($this->input($key)))]);
        }
    }

    public function rules(): array
    {
        return [
            'q'=>['nullable','string','max:120'], 'location'=>['nullable','string','max:100'],
            'kind'=>['nullable',Rule::in(['video','property','agent'])],
            'sort'=>['nullable',Rule::in(['relevance','latest','price_asc','price_desc','name'])],
            'type'=>['nullable',Rule::in(['primary','secondary'])],
            'transaction'=>['nullable',Rule::in(['sale','rent'])],
            'category'=>['nullable','integer','min:1'],
            'min_price'=>['nullable','numeric','min:0','max:9999999999999999'],
            'max_price'=>['nullable','numeric','min:0','max:9999999999999999'],
            'price_period'=>['nullable',Rule::in(['total','month','year'])],
            'bedrooms'=>['nullable','integer','min:0','max:100'],
            'bathrooms'=>['nullable','integer','min:0','max:100'],
            'floors'=>['nullable','integer','min:0','max:100'],
            'balconies'=>['nullable','integer','min:0','max:50'],
            'min_area'=>['nullable','numeric','min:0','max:999999999'],
            'min_building_area'=>['nullable','numeric','min:0','max:999999999'],
            'page'=>['nullable','integer','min:1','max:100'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (is_numeric($this->min_price) && is_numeric($this->max_price) && (float)$this->min_price>(float)$this->max_price) {
                $validator->errors()->add('max_price','Harga maksimum harus sama atau lebih besar dari minimum.');
            }
            $sort=$this->input('sort','relevance');$kind=$this->input('kind','property');
            if (($kind==='agent' && in_array($sort,['price_asc','price_desc'])) || ($kind!=='agent' && $sort==='name')) {
                $validator->errors()->add('sort','Urutan tidak sesuai jenis hasil.');
            }
        }];
    }
}
