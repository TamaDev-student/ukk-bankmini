<?php

namespace App\Services;

use App\Models\Major;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;

class ClassRosterService
{
    public function majorRosters(): array
    {
        return config('class_roster.majors', []);
    }

    public function configuredClasses(): Collection
    {
        return SchoolClass::query()
            ->with('major:id,name,code')
            ->get(['id', 'name', 'major_id'])
            ->filter(fn (SchoolClass $schoolClass) => $this->classIdentity($schoolClass) !== null)
            ->values();
    }

    public function configuredClassIds(): array
    {
        return $this->configuredClasses()->modelKeys();
    }

    public function classIdentity(SchoolClass $schoolClass): ?array
    {
        $majorCode = mb_strtoupper((string) $schoolClass->major?->code);
        $majorRosters = $this->majorRosters();
        if (! array_key_exists($majorCode, $majorRosters)) {
            return null;
        }

        if (! preg_match(
            '/\A\s*(XII|XI|X|12|11|10)\s+'.preg_quote($majorCode, '/').'\s+([1-9]\d*)\s*\z/i',
            $schoolClass->name,
            $matches
        )) {
            return null;
        }

        $gradeLevel = $this->normalizeGradeLevel($matches[1]);
        $rombel = (int) $matches[2];
        if ($gradeLevel === null || $rombel > $majorRosters[$majorCode]) {
            return null;
        }

        return [
            'grade_level' => $gradeLevel,
            'major_code' => $majorCode,
            'rombel' => $rombel,
        ];
    }

    public function normalizeGradeLevel(string $gradeLevel): ?string
    {
        return match (mb_strtoupper(trim($gradeLevel))) {
            'X', '10' => 'X',
            'XI', '11' => 'XI',
            'XII', '12' => 'XII',
            default => null,
        };
    }

    public function resolveMajor(string $identifier, Collection $majors): ?Major
    {
        $normalized = $this->normalizeMasterValue($identifier);
        if ($normalized === '') {
            return null;
        }

        $matches = $majors->filter(function (Major $major) use ($normalized) {
            $code = mb_strtoupper((string) $major->code);
            if (! array_key_exists($code, $this->majorRosters())) {
                return false;
            }

            return $normalized === $this->normalizeMasterValue($major->name);
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function resolveClass(
        string $gradeLevel,
        string $majorCode,
        int $rombel,
        Collection $classes
    ): ?SchoolClass {
        $grade = $this->normalizeGradeLevel($gradeLevel);
        $majorCode = mb_strtoupper(trim($majorCode));
        $maximumRombel = $this->majorRosters()[$majorCode] ?? null;
        if (
            $grade === null
            || $maximumRombel === null
            || $rombel < 1
            || $rombel > $maximumRombel
        ) {
            return null;
        }

        $matches = $classes->filter(function (SchoolClass $schoolClass) use ($grade, $majorCode, $rombel) {
            $identity = $this->classIdentity($schoolClass);

            return $identity !== null
                && $identity['grade_level'] === $grade
                && $identity['major_code'] === $majorCode
                && $identity['rombel'] === $rombel;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalizeMasterValue(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtoupper($collapsed ?? trim($value));
    }
}
