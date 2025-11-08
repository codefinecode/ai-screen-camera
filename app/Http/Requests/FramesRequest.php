<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FramesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error' => ['nullable', 'integer'],
            'timestamp' => ['required', 'integer'],
            'playerUUID' => ['nullable', 'string'],
            'cameraId' => ['nullable', 'string'],
            'imgDataBase64' => ['nullable', 'string'],
            'imgWidth' => ['nullable', 'integer'],
            'imgHeight' => ['nullable', 'integer'],
            'faceDetections' => ['nullable', 'array'],
            'faceDetections.*.faceID' => ['nullable', 'integer'],
            'faceDetections.*.age' => ['nullable', 'integer'],
            'faceDetections.*.ageConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.gender' => ['nullable', 'integer'],
            'faceDetections.*.genderConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.dwellTime' => ['nullable', 'numeric'],
            'faceDetections.*.attentionTime' => ['nullable', 'numeric'],
            'faceDetections.*.emotion' => ['nullable', 'integer'],
            'faceDetections.*.emotionConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.glasses' => ['nullable'],
            'faceDetections.*.glassesConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.firstTimeSeen' => ['nullable', 'integer'],
            'faceDetections.*.isLastTimeSeen' => ['nullable'],
            'faceDetections.*.x' => ['nullable', 'numeric'],
            'faceDetections.*.y' => ['nullable', 'numeric'],
            'faceDetections.*.width' => ['nullable', 'numeric'],
            'faceDetections.*.height' => ['nullable', 'numeric'],
        ];
    }
}
