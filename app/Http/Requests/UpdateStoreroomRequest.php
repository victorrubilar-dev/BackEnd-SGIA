<?php

namespace App\Http\Requests;

class UpdateStoreroomRequest extends StoreStoreroomRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('storeroom')) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'][0] = 'sometimes';
        $rules['code'][0] = 'sometimes';

        return $rules;
    }
}
