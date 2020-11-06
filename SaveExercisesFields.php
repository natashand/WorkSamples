<?php

namespace App\Http\Controllers\v2\Settings\Exercises;

use App\Http\Controllers\BaseActionController;
use App\v2\Data\TS;
use App\v2\Facades\Auth;
use App\v2\Facades\Gapi;
use App\v2\Models\ExerciseField;
use App\v2\Models\ExerciseGroup;
use App\v2\Models\Facility;
use App\v2\Models\Group;
use App\v2\Models\GroupType;
use App\v2\Models\Program;
use App\v2\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

/**
 * Class SaveExercisesFields
 *
 * @package App\Http\Controllers\v2\Settings\Exercises
 */
class SaveExercisesFields extends BaseActionController
{
    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        if (!Auth::can('@view_general_settings')) {
            return $this->noPermissionInJson();
        }

        $exercisesFields = $request->get('exercisesFields');
        $removedExercisesGroups = $request->get('removedExercisesFields');

        $programDefault = Group::getGroupTS(GroupType::GROUP_PROGRAM_DEFAULT);
        $exercisesFieldsTsList = [];
        $programs = [];
        foreach ($exercisesFields as $exerciseFieldData) {
            $validator = Validator::make($exerciseFieldData, [
                'name' => 'max:8',
            ]);

            if ($validator->errors()->isNotEmpty()) {
                return $this->response->json([
                    'failure' => true,
                    'errors' => implode(\PHP_EOL, $validator->errors()->all()),
                ]);
            }

            if (Auth::isSuperAdmin()) {
                if (isset($exerciseFieldData['id'])) {
                    $exercisesField = ExerciseField::find($exerciseFieldData['id']);
                    $existPrograms = $exercisesField->getProgramsList();

                    foreach (array_diff($existPrograms, $exerciseFieldData['programs']) as $programS) {
                        Gapi::without(new TS(Program::TABLE, $programS), [$exercisesField->makeTS()]);
                    }
                } else {
                    $exercisesField = new ExerciseField();
                }
            } else {
                if ((isset($exerciseFieldData['id']) && isset($exerciseFieldData['isBase']))
                    || !isset($exerciseFieldData['id'])) {
                    $exercisesField = new ExerciseField();
                    if (isset($exerciseFieldData['id'])) {
                        $exercisesField->parent_id = $exerciseFieldData['id'];
                    }
                } else {
                    $exercisesField = ExerciseField::find($exerciseFieldData['id']);
                }
            }

            $exercisesField->fill($exerciseFieldData);
            $exercisesField->save();
            $ts = $exercisesField->makeTS();
            $exercisesFieldsTsList[] = $ts;

            if (empty($exerciseFieldData['programs'])) {
                $programs['no program'][] = $ts;
            } else {
                foreach ($exerciseFieldData['programs'] as $programS) {
                    $programs[$programS][] = $ts;
                }
            }
        }

        foreach ($programs as $programS => $tsList) {
            if ($programS == 'no program') {
                Gapi::union($programDefault, $tsList);
            } else {
                Gapi::union(new TS(Program::TABLE, $programS), $tsList);
            }
        }

        $removedTsList = [];
        $programs = [];
        foreach ($removedExercisesGroups as $exerciseFieldData) {
            $exercisesField = ExerciseField::find($exerciseFieldData['id']);
            if ($exercisesField !== null) {
                $ts = $exercisesField->makeTS();
                $removedTsList[] = $ts;
                $exercisesField->delete();

                if (empty($exerciseFieldData['programs'])) {
                    $programs['no program'][] = $ts;
                } else {
                    foreach ($exerciseFieldData['programs'] as $programS) {
                        $programs[$programS][] = $ts;
                    }
                }
            }
        }

        foreach ($programs as $programS => $tsList) {
            if ($programS == 'no program') {
                Gapi::without($programDefault, $tsList);
            } else {
                Gapi::without(new TS(Program::TABLE, $programS), $tsList);
            }
        }

        if (Auth::isSuperAdmin()) {
            $superAdminTsG = Group::getGroupTS(GroupType::SUPER_ADMIN);
            Gapi::union($superAdminTsG, $exercisesFieldsTsList);
            Gapi::without($superAdminTsG, $removedTsList);
        }
        else {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::union($facilityTs, $exercisesFieldsTsList);
            Gapi::without($facilityTs, $removedTsList);
        }

        return $this->response->json([
            'success' => true,
        ]);
    }
}