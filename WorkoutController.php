<?php

namespace App\Http\Controllers\v2\Workouts;

use App\Http\Controllers\Controller;
use App\v2\Data\TS;
use App\v2\Facades\Auth;
use App\v2\Facades\Gapi;
use App\v2\Models\Exercise;
use App\v2\Models\ExerciseField;
use App\v2\Models\ExerciseGroup;
use App\v2\Models\Group;
use App\v2\Models\GroupType;
use App\v2\Models\Program;
use App\v2\Models\Tree;
use App\v2\Models\Workout;
use Aws\CloudFront\Signer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WorkoutController extends Controller
{
    public function index()
    {
        return view('v2.workouts.index');
    }

    public function getList()
    {
        // **** workouts ****
        $tsSA = Group::getGroupTS(GroupType::SUPER_ADMIN);
        $superAdminPrograms = Program::getDefaultPrograms();
        $superAdminWorkout = Workout::getWorkouts($tsSA);

        if (Auth::isSuperAdmin()) {
            $programs = $superAdminPrograms;
            $workouts = $superAdminWorkout;
        } else {
            $facility = Auth::userFacility();
            $programsFacility = $facility->getPrograms();
            $programs = $superAdminPrograms->merge($programsFacility);
            $workouts = $superAdminWorkout->merge($facility->getWorkouts());
        }

        foreach ($programs as $program) {
            $ts = $program->makeTS();
            $groupFieldS = Gapi::getGroupSList($ts, ExerciseField::TABLE);
            $exerciseGroups = ExerciseField::find($groupFieldS);
            $program->exerciseFields = $exerciseGroups;
        }

        $programDefault = Group::getGroupTS(GroupType::GROUP_PROGRAM_DEFAULT);
        $groupFieldS = Gapi::getGroupSList($programDefault, ExerciseField::TABLE);
        $exerciseGroups = ExerciseField::find($groupFieldS);

        $noProgram = [
            'id' => 'no_program',
            'name' => 'No program',
            'exerciseFields' => $exerciseGroups
        ];

        $programs = $programs->push($noProgram);

        foreach ($workouts as $workout) {
            $exercises = [];
            foreach ($workout['exercises'] as $exercise) {
                $singleExercise = Exercise::find($exercise['id']);

                $fields = [];
                if (count($exercise['fields']) > 0) {
                    foreach ($exercise['fields'] as $field) {
                        $exFields= ExerciseField::find($field['id']);
                        if ($exFields) {
                            $exFields->value = $field['value'] ?? '';
                            $fields[] = $exFields;
                        }
                    }
                }

                $singleExercise->exerciseFields = $fields;
                $singleExercise->notes = $exercise['notes'] ?? '';

                $exercises[] = $singleExercise;
            }
            $workout->exercises = $exercises;
            $programsWO = Gapi::unitGroups($workout->makeTS(), Program::TABLE);
            $workout->program = $programsWO[0] ?? null;
            $workout->template = '-';
            $workout->assigned = 'No';
        };

        // **** role id ****
        $roleId = Auth::getRole()->id;

        return json_encode([
            'workouts' => $workouts,
            'programs' => $programs,
            'roleId' => $roleId,
        ]);
    }

    public function store(Request $request)
    {
        $dataWorkout = $request->get('data');

        $workout = new Workout();
        $workout->name = $dataWorkout['name'];
        $workout->type = Auth::isSuperAdmin() ? Workout::TYPE_BASE : Workout::TYPE_ORGANIZATION_BASE;
        $workout->description = $dataWorkout['description'];
        $workout->notes = $dataWorkout['notes'];
        $workout->is_published = $dataWorkout['is_published'];

        $exercises = [];
        foreach ($dataWorkout['exercises'] as $exercise) {
            $fields = [];
            foreach ($exercise['exerciseFields'] as $field) {
                $fields[] = [
                    'id' => $field['id'],
                    'value' => $field['value'] ?? ''
                ];
            }

            $exercises[] = [
                'id' => $exercise['id'],
                'fields' => $fields,
                'notes' => $exercise['notes'] ?? ''
            ];
        }
        $workout->exercises = $exercises;
        $workout->save();
        if (empty($dataWorkout['program']) || $dataWorkout['program'] == 'no_program') {
            $programTs = Group::getGroupTS(GroupType::GROUP_PROGRAM_DEFAULT);
        } else {
            $programTs = Program::find($dataWorkout['program'])->makeTS();
        }
        Gapi::union($programTs, [$workout->makeTS()]);

        if (Auth::isSuperAdmin()) {
            $superAdminTsG = Group::getGroupTS(GroupType::SUPER_ADMIN);
            Gapi::union($superAdminTsG, [$workout->makeTS()]);
        } else {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::union($facilityTs, [$workout->makeTS()]);
        }

        return json_encode(['success' => true]);
    }

    public function update(Request $request)
    {
        $dataWorkout = $request->get('data');

        $workout = Workout::find($dataWorkout['woId']);
        $workout->name = $dataWorkout['name'];
        $workout->description = $dataWorkout['description'];
        $workout->notes = $dataWorkout['notes'];
        $workout->is_published = $dataWorkout['is_published'];

        $exercises = [];
        foreach ($dataWorkout['exercises'] as $exercise) {
            $fields = [];
            foreach ($exercise['exerciseFields'] as $field) {
                $fields[] = [
                    'id' => $field['id'],
                    'value' => $field['value'] ?? ''
                ];
            }

            $exercises[] = [
                'id' => $exercise['id'],
                'fields' => $fields,
                'notes' => $exercise['notes'] ?? ''
            ];
        }
        $workout->exercises = $exercises;
        $workout->save();

        $programWO = Gapi::unitGroups($workout->makeTS(), Program::TABLE);

        if (count($programWO) > 0) {
            Gapi::without(new TS(Program::TABLE, $programWO[0]), [$workout->makeTS()]);
        }

        $programTs = Program::find($dataWorkout['program'])->makeTS();
        Gapi::union($programTs, [$workout->makeTS()]);

        return json_encode(['success' => true]);
    }

    public function delete(Request $request)
    {
        $woId = $request->get('woId');
        $workout = Workout::find($woId);

        $programId = Gapi::unitGroups($workout->makeTS(), Program::TABLE);

        if (count($programId) > 0) {
            Gapi::without(new TS(Program::TABLE, $programId[0]), [$workout->makeTS()]);
        }

        if (Auth::isSuperAdmin()) {
            $superAdminTsG = Group::getGroupTS(GroupType::SUPER_ADMIN);
            Gapi::without($superAdminTsG, [$workout->makeTS()]);
        } else {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::without($facilityTs, [$workout->makeTS()]);
        }
        $workout->delete();

        return json_encode(['success' => true]);
    }
}
