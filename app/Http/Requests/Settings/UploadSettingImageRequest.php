<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A picture for a settings field, such as the company logo.
 *
 * The same rules as a product photo. SVG is refused: it is XML that can
 * carry script, and it would be served from this system's own domain.
 *
 * Who may upload is decided by the route's `permission:settings.update`.
 */
class UploadSettingImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Choose a picture to upload.',
            'image.image' => 'The file must be a picture.',
            'image.mimes' => 'The picture must be a JPG, PNG or WebP. SVG is not allowed.',
            'image.max' => 'The picture must be 2 MB or smaller.',
        ];
    }
}
