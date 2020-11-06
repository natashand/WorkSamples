<?php

namespace App\Http\Controllers\v2\Exercises;

use App\Http\Controllers\Controller;
use App\v2\Data\TS;
use App\v2\Facades\Auth;
use App\v2\Facades\Gapi;
use App\v2\Models\Exercise;
use App\v2\Models\ExerciseGroup;
use App\v2\Models\Facility;
use App\v2\Models\Group;
use App\v2\Models\GroupType;
use App\v2\Models\Role;
use App\v2\Models\Tree;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function index()
    {
        return view('v2.exercises.index');
    }

    public function getTree() {
        $superAdminTsG = Group::getGroupTS(GroupType::SUPER_ADMIN);
        $superAdminExerciseGroups = ExerciseGroup::getExerciseGroups($superAdminTsG);
        $superAdminExerciseGroups = $superAdminExerciseGroups->transform(function ($group) {
            $group->isBase = true;
            return $group;
        });

        if (Auth::isSuperAdmin()) {
            $exerciseGroups = $superAdminExerciseGroups;
            $authTs = Group::getGroupTS(GroupType::SUPER_ADMIN);
        } else {
            $facility = Auth::userFacility();
            $facilityExersiseGroup = $facility->getExerciseGroups();
            $facilityExersiseGroup = $facilityExersiseGroup->transform(function ($group) {
                $group->isBase = false;
                return $group;
            });
            $exerciseGroups = $superAdminExerciseGroups->merge($facilityExersiseGroup);
            $authTs = Auth::userFacility()->makeTS();
        }

        $treeData = [];

        $paramsForBase = [
            '@copy_user',
        ];

        $params = [
            '@add_folder',
            '@rename_folder',
            '@delete_folder',
            '@copy_user',
            '@paste_user',
            '@move_user',
            '@delete_user',
        ];

        $paramsNoFolderSA = [
            '@move_user',
            '@no_CRUD_folder'
        ];

        $paramsNoFolderFA = [
            '@copy_user',
            '@no_CRUD_folder'
        ];

        $noFolder = [
            'id' => '',
            'text' => 'No Folder',
            'items' => [],
            'children' => [],
            'params' => []
        ];

        $type = GroupType::GROUP_EXERCISES_TREE;
        $getTree = Tree::getTree($authTs, $type);

        if (isset($getTree['id'])){
            $treeData = $getTree->data;

            for ($i = 0; $i < count($treeData) - 1; $i++) {
                $exGroup = $exerciseGroups->first(function (ExerciseGroup $group) use($treeData, $i){
                    return $group->id == $treeData[$i]['id'];
                });

                $id = $treeData[$i]['id'].'_no_folder';
                $treeData[$i]['params'] = ($exGroup->isBase && !Auth::isSuperAdmin()) ? $paramsForBase : $params;
                $index = array_search($id, array_column($treeData[$i]['children'], 'id'));
                $treeData[$i]['children'][$index]['params'] = ($exGroup->isBase && !Auth::isSuperAdmin()) ? $paramsNoFolderFA : $paramsNoFolderSA;
            }
            $dataId = array_column($treeData, 'id');
            $exId = $exerciseGroups->pluck('id')->toArray();
            $differentId = array_diff($exId, $dataId);

            if(count($differentId)){
                foreach ($differentId as $id) {
                    $exGroup = $exerciseGroups->first(function (ExerciseGroup $group) use($id){
                        return $group->id == $id;
                    });
//                    $exGroup = ExerciseGroup::find($id);
                    $noFolder['id'] = $exGroup->id . '_no_folder';
                    $noFolder['params'] = ($exGroup->isBase && !Auth::isSuperAdmin()) ? $paramsNoFolderFA : $paramsNoFolderSA;
                    $treeData[] = [
                        'id' => $exGroup->id,
                        'text' => ($exGroup->isBase && !Auth::isSuperAdmin()) ? $exGroup->name.' (Base)' : $exGroup->name,
                        'items' => [],
                        'children' => [$noFolder],
                        'params' => ($exGroup->isBase && !Auth::isSuperAdmin()) ? $paramsForBase : $params,
                    ];
                }
            }

        } else {
            foreach ($exerciseGroups as $group) {
                $noFolder['id'] = $group->id . '_no_folder';
                $noFolder['params'] = ($group->isBase && !Auth::isSuperAdmin()) ? $paramsNoFolderFA : $paramsNoFolderSA;
                $treeData[] = [
                    'id' => $group->id,
                    'text' => ($group->isBase && !Auth::isSuperAdmin()) ? $group->name.' (Base)' : $group->name,
                    'items' => [],
                    'children' => [$noFolder],
                    'params' => ($group->isBase && !Auth::isSuperAdmin()) ? $paramsForBase : $params,
                ];
            }

            $exUngroup = ExerciseGroup::where('name', 'Ungrouped')->first();
            $treeData[] = [
                'id' => $exUngroup->id,
                'text' => $exUngroup->name,
                'items' => [],
                'params' => ['@read_user'],
            ];
        }

        $treeData = collect($treeData)->prepend([
            'id' => 'all_ex',
            'text' => 'All exercises',
            'items' => [],
            'children' => [],
            'params' => [
                '@copy_user',
                '@forbidden_add',
            ]
        ])->toArray();
        return json_encode($treeData);
    }

    public function getList()
    {
        // **** exercises ****
        $exercisesBase = Exercise::where('type', Exercise::TYPE_BASE)->where('parent_id', null)->get();

        $superAdminTsG = Group::getGroupTS(GroupType::SUPER_ADMIN);
        $superAdminExerciseGroups = ExerciseGroup::getExerciseGroups($superAdminTsG);

        if (Auth::isSuperAdmin()) {
            $exerciseGroups = $superAdminExerciseGroups;
            $exercises = $exercisesBase->toArray();
        } else {
            $facility = Auth::userFacility();

            // todo получение групп упражнений через visibility - пока отложено на будущее
            /*
            $vs = $facility->visibilities;
            $exerciseGroups = collect();
            foreach ($vs as $visibility) {
                $exerciseGroups = $exerciseGroups->merge($visibility->exercise_groups);
            }
            */

            $exerciseGroups = $superAdminExerciseGroups->merge($facility->getExerciseGroups());
            $exercises = $exercisesBase->merge($facility->getExercise())->keyBy('id');
            $parentIds = [];
            foreach ($exercises as $exercise) {
                if ($exercise->parent_id) {
                    $parentIds[] = $exercise->parent_id;
                }
            };

            $parentIds = array_unique($parentIds);
            $exercises = $exercises->except($parentIds)->values()->toArray();
        }

        foreach ($exercises as &$exercise) {
            $exerciseObject = Exercise::find($exercise['id']);
            $group = Gapi::unitGroups($exerciseObject->makeTS(), ExerciseGroup::TABLE);
            if (count($group) == 0) {
                $exercise['group'] = 0;
                $exercise['color'] = '';
            } else {
                $exercise['group'] = ExerciseGroup::find($group[0])->id;
                $exercise['color'] = ExerciseGroup::find($group[0])->color ?? '';
            }

            $exercise['createdBy'] = empty($exerciseObject->user) ? '' :  $exerciseObject->user->getName();

            $exercise['assigned'] = 'No';
            $exercise['shortDescription'] = implode(' ', array_slice(explode(' ', $exercise['description']), 0, 7));

            if ($exerciseObject->edit_user_id && $exerciseObject->type == Exercise::TYPE_BASE) {
                $exercise['tooltip'] = Role::getById($exerciseObject->edit_user_id)->title ?? '';
            }

            if (!empty($exercise['parent_id'])) {
                $parent = $exerciseObject->parent()->withTrashed()->first();

                $exercise['link'] = $parent->link;
                $exercise['description'] = $parent->description;

                $exercise['localLink'] = $exerciseObject->link;
                $exercise['localDescription'] = $exerciseObject->description;
            }
        }

        // **** groups ****
        $groups = $exerciseGroups->pluck('name', 'id');

        // **** role id ****
        $roleId = Auth::getRole()->id;

        return json_encode([
            'exercises' => $exercises,
            'groups' => $groups->toArray(),
            'roleId' => $roleId
        ]);
    }

    public function store(Request $request)
    {
        if (!Auth::can('@create_exercises')) {
            return $this->noPermission();
        }

        if ($request->group == 0) {
            $groupTS = Group::getGroupTS(GroupType::GROUP_EXERCISE_DEFAULT);
        } else {
            $group = ExerciseGroup::find($request->group);
            $groupTS = $group->makeTS();
        }

        if (Auth::isSuperAdmin()) {
            $type = Exercise::TYPE_BASE;
        } elseif (Auth::getRole()->id == 5 || Auth::getRole()->id == 6) {
            $type = Exercise::TYPE_ORDINARY;
        } else {
            $type = $request->type;
        }

        $exercise = new Exercise();
        $exercise->fill($request->all());
        $exercise->user_id = Auth::getUserId();
        $exercise->type = $type;
        $exercise->is_published = $request->isPublished;
        $exercise->save();

        Gapi::union($groupTS, [$exercise->makeTS()]);

        if (!Auth::isSuperAdmin()) {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::union($facilityTs, [$exercise->makeTS()]);
        }

        return json_encode(['success' => true]);
    }

    public function update(Request $request)
    {
        if (!Auth::can('@update_exercises')) {
            return $this->noPermission();
        }

        $exId = $request->exId;

        if (Auth::isSuperAdmin()) {
            $type = Exercise::TYPE_BASE;
        } elseif (Auth::getRole()->id == 5 || Auth::getRole()->id == 6) {
            $type = Exercise::TYPE_ORDINARY;
        } else {
            $type = $request->type;
        }

        $exercise = Exercise::find($exId);
        $groupId = Gapi::unitGroups($exercise->makeTS(), ExerciseGroup::TABLE);

        if (empty($request->parentId) && (Auth::getRole()->id == 3 || Auth::getRole()->id == 4) && $request->type == Exercise::TYPE_BASE) {
            // Facility admin/Chief Trainer create child ex
            $copyExercise = $exercise->replicate();
            $copyExercise->link = $request->localLink;
            $copyExercise->description = $request->localDescription;
            $copyExercise->user_id = Auth::getUserId();
            $copyExercise->edit_user_id = Auth::getRole()->id;
            $copyExercise->parent_id = $exId;
            $copyExercise->is_published = $request->isPublished;
            $copyExercise->save();

            $exerciseGroup = ExerciseGroup::find($groupId[0]);
            Gapi::union($exerciseGroup->makeTS(), [$copyExercise->makeTS()]);

            if (!Auth::isSuperAdmin()) {
                $facilityTs = Auth::userFacility()->makeTS();
                Gapi::union($facilityTs, [$copyExercise->makeTS()]);
            }
        } else if (!empty($request->parentId) && (Auth::getRole()->id == 3 || Auth::getRole()->id == 4) && $request->type == Exercise::TYPE_BASE) {
            // Facility admin/Chief Trainer edit child ex
            $exercise->link = $request->localLink;
            $exercise->description = $request->localDescription;
            $exercise->edit_user_id = Auth::getRole()->id;
            $exercise->save();

        } else {
            // Edit exercise
            $exercise->fill($request->all());
            $exercise->type = $type;
            $exercise->is_published = $request->isPublished;
            $exercise->edit_user_id = Auth::getRole()->id;
            $exercise->save();

            $groupReq = $request->group;

            if ($groupReq != 0 && count($groupId) > 0 && $groupId[0] != $groupReq) {
                $exerciseGroup = ExerciseGroup::find($groupReq);
                Gapi::without(new TS(ExerciseGroup::TABLE, $groupId[0]), [$exercise->makeTS()]);
                Gapi::union($exerciseGroup->makeTS(), [$exercise->makeTS()]);
            } elseif ($groupReq != 0 && count($groupId) == 0) {
                $exerciseGroup = ExerciseGroup::find($groupReq);
                Gapi::union($exerciseGroup->makeTS(), [$exercise->makeTS()]);
            }
        }

        return json_encode(['success' => true]);
    }

    public function copy(Request $request)
    {
        $exId = $request->exId;
        $exercise = Exercise::find($exId);
        $newExercise = $exercise->replicate();
        $newExercise->name = $exercise->name . '_(copy)';
        $newExercise->user_id = Auth::getUserId();

        if (Auth::getRole()->id == 3 || Auth::getRole()->id == 4) {
            $newExercise->type = Exercise::TYPE_ORGANIZATION_BASE;
        } elseif (Auth::isTrainer()) {
            $newExercise->type = Exercise::TYPE_ORDINARY;
        }

        $newExercise->save();

        $groupId = Gapi::unitGroups($exercise->makeTS(), ExerciseGroup::TABLE);
        Gapi::union(new TS(ExerciseGroup::TABLE, $groupId[0]), [$newExercise->makeTS()]);

        if (!Auth::isSuperAdmin()) {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::union($facilityTs, [$newExercise->makeTS()]);
        }

        return json_encode(['success' => true]);
    }

    public function delete(Request $request)
    {
        if (!Auth::can('@delete_exercises')) {
            return $this->noPermissionInJson();
        }

        $exId = $request->exId;
        $exercise = Exercise::find($exId);

        $groupId = Gapi::unitGroups($exercise->makeTS(), ExerciseGroup::TABLE);
        Gapi::without(new TS(ExerciseGroup::TABLE, $groupId[0]), [$exercise->makeTS()]);

        $exercisesPrototip = Exercise::where('parent_id', $exId)->get();
        if (count($exercisesPrototip) > 0) {
            foreach ($exercisesPrototip as $prototip) {
                $groupId = Gapi::unitGroups($prototip->makeTS(), ExerciseGroup::TABLE);
                Gapi::without(new TS(ExerciseGroup::TABLE, $groupId[0]), [$prototip->makeTS()]);
                $facilityTs = Gapi::unitGroups($prototip->makeTS(), Facility::TABLE);
                Gapi::without(new TS(Facility::TABLE, $facilityTs[0]), [$prototip->makeTS()]);
            }
        }

        if (!Auth::isSuperAdmin()) {
            $facilityTs = Auth::userFacility()->makeTS();
            Gapi::without($facilityTs, [$exercise->makeTS()]);
        }

        $exercise->delete();

        return json_encode(['success' => true]);
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function noPermission()
    {
        return $this->response->view('v2.error.forbidden', ['message' => 'You don\'t have permission.']);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function noPermissionInJson()
    {
        return $this->response->json([
            'success' => true,
            'errors' => 'You don\'t have permission.',
        ]);
    }

    public function saveTree(Request $request) {
        $treeData = $request->get('tree');
        $supAdm = array_shift($treeData);

        if (Auth::isSuperAdmin()) {
            $authTs = Group::getGroupTS(GroupType::SUPER_ADMIN);
        } else {
            $authTs = Auth::userFacility()->makeTS();
        }

        $type = GroupType::GROUP_EXERCISES_TREE;
        $getTree = Tree::getTree($authTs, $type);

        if (isset($getTree['id'])){
            $tree = Tree::find($getTree['id']);
        } else {
            $tree = new Tree();
        }

        $tree->type = $type;
        $tree->data = $treeData;
        $tree->fillTS($authTs);
        $tree->save();

        return json_encode(['success' => true]);
    }
}
