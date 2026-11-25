<?php

namespace App\Http\Requests;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentListingRequest extends FormRequest
{
    public function authorize(): bool { return true; } // Route guards + locked ownership check.

    public function attributes(): array
    {
        return ['title'=>'judul properti','category_id'=>'jenis properti','transaction_type'=>'transaksi',
            'price'=>'harga','price_period'=>'periode harga','location_label'=>'kawasan',
            'address'=>'alamat','description'=>'deskripsi','land_area'=>'luas tanah','building_area'=>'luas bangunan',
            'bedrooms'=>'kamar tidur','bathrooms'=>'kamar mandi','floors'=>'jumlah lantai'];
    }
    public function messages(): array
    {
        return ['required'=>':attribute wajib diisi.','numeric'=>':attribute harus berupa angka.',
            'integer'=>':attribute harus berupa bilangan bulat.','regex'=>'Format :attribute tidak valid.',
            'max'=>':attribute melebihi batas yang diperbolehkan.','min'=>':attribute kurang dari batas minimum.',
            'between'=>':attribute di luar rentang yang diperbolehkan.','in'=>'Pilihan :attribute tidak valid.',
            'exists'=>'Pilihan :attribute tidak tersedia.','prohibited'=>':attribute tidak boleh ditentukan melalui form ini.'];
    }

    public function rules(): array
    {
        return [
            'title'=>['required','string','max:200'], 'description'=>['nullable','string','max:10000'],
            'category_id'=>['required','integer',Rule::exists('property_categories','id')],
            'transaction_type'=>['required',Rule::in(['sale','rent'])],
            'price'=>['required','numeric','regex:/^\d{1,16}(?:\.\d{1,2})?$/D','min:1'],
            'price_period'=>['required',Rule::in($this->input('transaction_type')==='sale'?['total']:['month','year'])],
            'location_label'=>[$this->input('listing_type')==='primary'?'nullable':'required','string','max:180'],
            'address'=>[$this->input('listing_type')==='primary'?'prohibited':'nullable','string','max:2000'],
            'bedrooms'=>['nullable','integer','between:0,100'], 'bathrooms'=>['nullable','integer','between:0,100'],
            'floors'=>['nullable','integer','between:1,100'],
            'land_area'=>['nullable','numeric','min:0','max:9999999999.99'],
            'building_area'=>['nullable','numeric','min:0','max:9999999999.99'],
            'furnishing'=>['nullable',Rule::in(['unknown','unfurnished','semi_furnished','full_furnished'])],
            'orientation'=>['nullable',Rule::in(Listing::PUBLIC_ORIENTATIONS)],
            'certificate'=>['nullable','string','max:100'], 'unit_type'=>['nullable','string','max:100'],
            'additional_specs'=>['nullable','array:carport,garage,apartment_floor,tower,balconies,concept,allow_video_download'],
            'additional_specs.allow_video_download'=>['sometimes','required','boolean'],
            'additional_specs.balconies'=>['nullable','integer','between:0,50'],
            'additional_specs.concept'=>['nullable','string','max:100'],
            'additional_specs.carport'=>['nullable','integer','between:0,50'],
            'additional_specs.garage'=>['nullable','integer','between:0,50'],
            'additional_specs.apartment_floor'=>['nullable','integer','between:0,200'],
            'additional_specs.tower'=>['nullable','string','max:100'],
            'agent_id'=>['prohibited'], 'branch_id'=>['prohibited'], 'status'=>['prohibited'],
            'listing_code'=>['prohibited'], 'listing_type'=>['sometimes',Rule::in(['secondary','primary'])],
            'project_id'=>[$this->input('listing_type')==='primary'?'required':'prohibited','integer','min:1'],
            'secondary_property_id'=>['prohibited'], 'published_at'=>['prohibited'], 'moderation_status'=>['prohibited'],
        ];
    }
}
