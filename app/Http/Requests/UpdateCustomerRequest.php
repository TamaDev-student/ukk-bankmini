<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Services\ClassRosterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && $this->input('email') !== null) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules(): array
    {
        $customer = Customer::find($this->route('id'));
        $configuredClassIds = app(ClassRosterService::class)->configuredClassIds();
        if ($customer?->class_id) {
            $configuredClassIds[] = (int) $customer->class_id;
        }

        $emailRules = [
            'sometimes',
            'required',
            'string',
            'email',
            'max:255',
        ];

        if ($customer?->user_id) {
            $emailRules[] = Rule::unique('users', 'email')
                ->ignore($customer->user_id);
        } else {
            $emailRules[] = Rule::unique('users', 'email');
        }

        return [
            'student_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'class_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:classes,id',
                Rule::in(array_values(array_unique($configuredClassIds))),
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:active,inactive,blocked',
            ],

            'email' => $emailRules,
        ];
    }

    public function messages(): array
    {
        return [
            'student_name.required' => 'Nama siswa wajib diisi.',
            'student_name.max' => 'Nama siswa maksimal 100 karakter.',

            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas yang dipilih tidak ditemukan.',
            'class_id.in' => 'Kelas tidak termasuk dalam konfigurasi rombel sekolah.',

            'status.required' => 'Status rekening wajib diisi.',
            'status.in' => 'Status rekening tidak valid.',

            'email.email' => 'Format email tidak valid.',
            'email.required' => 'Email pribadi wajib diisi.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email sudah digunakan oleh akun lain.',
        ];
    }
}
