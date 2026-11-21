<?php

namespace App\Enums;

enum SkillSource: string
{
    case Cv = 'cv';
    case PracticalWork = 'practical_work';
    case ExternalCourse = 'external_course';

    public function table(): string
    {
        return match ($this) {
            self::Cv => 'cv_uploads',
            self::PracticalWork => 'practical_works',
            self::ExternalCourse => 'external_courses',
        };
    }

    public function key(): string
    {
        return match ($this) {
            self::Cv => 'cv_id',
            self::PracticalWork => 'work_id',
            self::ExternalCourse => 'ext_course_id',
        };
    }

    public function textColumn(): string
    {
        return $this === self::Cv ? 'extracted_text' : 'description';
    }

    public static function isCourse(string $source): bool
    {
        return in_array($source, [self::ExternalCourse->value, 'university_course'], true);
    }
}
