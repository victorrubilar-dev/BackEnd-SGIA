<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateUserChartRequest extends StoreUserChartRequest
{
    /**
     * Un gráfico ajeno responde 404 (UserChartPolicy).
     */
    public function authorize(): bool|Response
    {
        return Gate::inspect('update', $this->route('userChart'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['chart_template_id'][0] = 'sometimes';
        $rules['user_id'] = ['prohibited'];

        return $rules;
    }

    public function messages(): array
    {
        return parent::messages() + ['user_id.prohibited' => 'El gráfico no se puede traspasar a otro usuario.'];
    }
}
