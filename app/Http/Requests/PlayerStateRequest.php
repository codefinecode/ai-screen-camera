<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlayerStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:player.state'],
            'data.playerId' => ['required', 'string'],
            'data.content' => ['required', 'array'],
            'data.content.*.contentId' => ['required', 'string'],
            'data.content.*.contentType' => ['required', 'string'],
            'data.timestamp' => ['required', 'integer'],
        ];
    }
}
