<?php

namespace App\Http\Requests\Alpha\FamilyConference;

use Illuminate\Foundation\Http\FormRequest;

class SaveFamilyConferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conference_on' => ['required', 'date'],
            'lead_teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'report_version_id' => ['nullable', 'integer', 'exists:report_versions,id'],
            'summary' => ['nullable', 'string', 'max:10000', 'required_if:share_with_parent,1'],
            'strengths' => ['nullable', 'string', 'max:10000'],
            'areas_to_support' => ['nullable', 'string', 'max:10000'],
            'parent_observation' => ['nullable', 'string', 'max:10000'],
            'agreed_follow_up' => ['nullable', 'string', 'max:10000'],
            'next_review_on' => ['nullable', 'date', 'after_or_equal:conference_on'],
            'share_with_parent' => ['sometimes', 'boolean'],
        ];
    }
}
