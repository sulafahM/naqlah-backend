<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticalWork extends Model
{
    protected $table = 'practical_works';

    protected $primaryKey = 'work_id';

    // This table does not have created_at and updated_at columns.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'course_id',
        'work_type',
        'title',
        'description',
        'github_url',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }
}
