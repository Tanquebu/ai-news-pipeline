<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:approved,rejected,published'],
            'title'  => ['sometimes', 'string', 'max:500'],
            'body'   => ['sometimes', 'string'],
        ];
    }
}
