<?php

namespace App\v2\Models;

use App\v2\Data\TS;
use App\v2\Facades\Gapi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Class ExerciseField
 *
 * @property int $id
 * @property int[] $programs
 *
 * @method static ExerciseGroup|ExerciseGroup[]|Collection find(int|int[] $id)
 *
 * @package App\v2\Models
 */
class ExerciseField extends Model
{
    use SoftDeletes;

    const TABLE = 'v2_exercises_fields';

    protected $fillable = [
        'name',
        'comment',
        'format',
        'sort',
    ];

    protected $table = self::TABLE;

    /**
     * @return \App\v2\Data\TS
     */
    public function makeTS()
    {
        return new TS(self::TABLE, $this->id);
    }

    /**
     * @return int[]
     */
    public function getProgramsList()
    {
        $programs = Program::all();

        return Gapi::unitGroups(new TS(self::TABLE, $this->id), Program::TABLE, $programs->pluck('id')->all());
    }

    /**
     * @param TS $tsG
     *
     * @return ExerciseGroup[]|Collection
     */
    public static function getExerciseFields(TS $tsG)
    {
        $exerciseFieldS = Gapi::getGroupSList($tsG, self::TABLE);
        $exerciseField = self::find($exerciseFieldS);

        $exerciseField = \count($exerciseFieldS) > 1 ? $exerciseField : collect($exerciseField);

        return $exerciseField->transform(function(ExerciseField $exerciseField) {
            $exerciseField->programs = $exerciseField->getProgramsList();

            return $exerciseField;
        })
            ->sortBy('sort')
            ->values();
    }
}