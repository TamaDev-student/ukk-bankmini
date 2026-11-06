<?php

namespace Database\Seeders;

use App\Models\Major;
use App\Models\SchoolClass;
use App\Services\ClassRosterService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClassRosterSeeder extends Seeder
{
    public function run(): void
    {
        $roster = app(ClassRosterService::class);
        $majorRosters = $roster->majorRosters();
        $knownMajorNames = [
            'RPL' => 'Rekayasa Perangkat Lunak',
            'TKJ' => 'Teknik Komputer dan Jaringan',
        ];

        DB::transaction(function () use ($roster, $majorRosters, $knownMajorNames) {
            foreach ($majorRosters as $code => $maximumRombel) {
                $major = Major::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => $knownMajorNames[$code] ?? $code]
                );

                foreach (config('class_roster.grades', []) as $gradeLevel) {
                    for ($rombel = 1; $rombel <= $maximumRombel; $rombel++) {
                        $existingMatches = SchoolClass::query()
                            ->with('major:id,name,code')
                            ->where('major_id', $major->id)
                            ->get(['id', 'name', 'major_id'])
                            ->filter(function (SchoolClass $schoolClass) use (
                                $roster,
                                $gradeLevel,
                                $code,
                                $rombel
                            ) {
                                $identity = $roster->classIdentity($schoolClass);

                                return $identity !== null
                                    && $identity['grade_level'] === $gradeLevel
                                    && $identity['major_code'] === $code
                                    && $identity['rombel'] === $rombel;
                            });

                        if ($existingMatches->count() > 1) {
                            throw new RuntimeException(
                                "Duplikasi kelas terdeteksi untuk {$gradeLevel} {$code} {$rombel}."
                            );
                        }

                        if ($existingMatches->isEmpty()) {
                            SchoolClass::query()->create([
                                'name' => "{$gradeLevel} {$code} {$rombel}",
                                'major_id' => $major->id,
                            ]);
                        }
                    }
                }
            }
        });
    }
}
