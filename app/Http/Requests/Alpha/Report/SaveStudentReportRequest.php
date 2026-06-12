<?php

namespace App\Http\Requests\Alpha\Report;

use App\Models\Student;
use App\Models\Report;
use App\Services\Alpha\AccessScopeService;
use App\Support\Alpha\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStudentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $student = $this->route('student');

        if (! $user || ! $student instanceof Student || $user->role === Role::PARENT) {
            return false;
        }

        return app(AccessScopeService::class)->canViewStudent($user, $student);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'term_id' => ['nullable', 'exists:terms,id'],
            'status' => ['required', Rule::in(['draft', 'ready', 'published', 'archived'])],
            'manual_present_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_sick_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_excused_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_absent_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_late_total' => ['nullable', 'integer', 'min:0', 'max:999'],
            'manual_attendance_note' => ['nullable', 'string', 'max:1200'],
            'teacher_narrative' => ['nullable', 'string', 'max:6000'],
            'general_narrative' => ['nullable', 'string', 'max:6000'],
            'social_emotional_narrative' => ['nullable', 'string', 'max:6000'],
            'independence_narrative' => ['nullable', 'string', 'max:6000'],
            'academic_narrative' => ['nullable', 'string', 'max:6000'],
            'parent_meeting_note' => ['nullable', 'string', 'max:3000'],
            'principal_note' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status rapor wajib dipilih.',
            'status.in' => 'Status rapor yang dipilih tidak valid.',
            'term_id.exists' => 'Term yang dipilih tidak valid.',
            'manual_present_total.integer' => 'Jumlah hadir harus berupa angka.',
            'manual_present_total.min' => 'Jumlah hadir tidak boleh negatif.',
            'manual_present_total.max' => 'Jumlah hadir terlalu besar.',
            'manual_sick_total.integer' => 'Jumlah sakit harus berupa angka.',
            'manual_sick_total.min' => 'Jumlah sakit tidak boleh negatif.',
            'manual_sick_total.max' => 'Jumlah sakit terlalu besar.',
            'manual_excused_total.integer' => 'Jumlah izin harus berupa angka.',
            'manual_excused_total.min' => 'Jumlah izin tidak boleh negatif.',
            'manual_excused_total.max' => 'Jumlah izin terlalu besar.',
            'manual_absent_total.integer' => 'Jumlah alfa harus berupa angka.',
            'manual_absent_total.min' => 'Jumlah alfa tidak boleh negatif.',
            'manual_absent_total.max' => 'Jumlah alfa terlalu besar.',
            'manual_late_total.integer' => 'Jumlah terlambat harus berupa angka.',
            'manual_late_total.min' => 'Jumlah terlambat tidak boleh negatif.',
            'manual_late_total.max' => 'Jumlah terlambat terlalu besar.',
            'manual_attendance_note.max' => 'Catatan presensi maksimal 1200 karakter.',
            'teacher_narrative.max' => 'Narasi guru maksimal 6000 karakter.',
            'general_narrative.max' => 'Narasi umum maksimal 6000 karakter.',
            'social_emotional_narrative.max' => 'Narasi sosial emosional maksimal 6000 karakter.',
            'independence_narrative.max' => 'Narasi kemandirian maksimal 6000 karakter.',
            'academic_narrative.max' => 'Narasi akademik maksimal 6000 karakter.',
            'parent_meeting_note.max' => 'Catatan untuk orang tua maksimal 3000 karakter.',
            'principal_note.max' => 'Catatan kepala sekolah maksimal 3000 karakter.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('status') !== 'published') {
                return;
            }

            $user = $this->user();
            $student = $this->route('student');
            $isExistingPublished = $student instanceof Student
                && $this->integer('term_id') > 0
                && Report::query()
                    ->where('student_id', $student->id)
                    ->where('term_id', $this->integer('term_id'))
                    ->where('status', 'published')
                    ->exists();

            if (! $isExistingPublished && ! in_array($user?->role, [Role::SUPER_ADMIN, Role::ADMIN], true)) {
                $validator->errors()->add('status', 'Hanya admin yang dapat mempublish rapor.');
            }
        });
    }
}
