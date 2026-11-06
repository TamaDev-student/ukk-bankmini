<?php

namespace App\Http\Requests;

use App\Services\ClassRosterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules(): array
    {
        $configuredClassIds = app(ClassRosterService::class)->configuredClassIds();

        return [
            'student_number' => [
                'required',
                'string',
                'max:30',
                'unique:customers,student_number',
                'unique:users,username',
            ],

            'student_name' => [
                'required',
                'string',
                'max:100',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'class_id' => [
                'required',
                'integer',
                'exists:classes,id',
                Rule::in($configuredClassIds),
            ],

            'opening_balance' => [
                'required',
                'integer',
                'min:10000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'student_number.required' => 'NIS wajib diisi.',
            'student_number.unique' => 'NIS sudah terdaftar.',

            'student_name.required' => 'Nama siswa wajib diisi.',

            'email.email' => 'Format email tidak valid.',
            'email.required' => 'Email pribadi wajib diisi.',
            'email.string' => 'Email harus berupa teks.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email sudah digunakan oleh akun lain.',

            'class_id.required' => 'Kelas wajib dipilih.',
            'class_id.exists' => 'Kelas yang dipilih tidak ditemukan.',
            'class_id.in' => 'Kelas tidak termasuk dalam konfigurasi rombel sekolah.',

            'opening_balance.required' => 'Setoran awal wajib diisi.',
            'opening_balance.min' => 'Setoran awal minimal Rp10.000.',
            'opening_balance.integer' => 'Setoran awal harus berupa angka bulat.',
        ];
    }
}
