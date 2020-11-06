<?php

namespace App\Services;


use App\Events\LogAction;
use App\Models\Facility;
use App\Models\FileExtension;
use App\Models\Level;
use App\Models\PlatformData;
use App\Models\Program;
use App\Models\SimpleUser;
use App\SubStatus;
use App\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class UserManagementService
{
    private $currentUserRole;
    
    public function setFacility(Facility $facility)
    {
        $this->facility = $facility;
    }
    
    public function setCurrentUserRole($currentUserRole)
    {
        $this->currentUserRole = $currentUserRole;
    }
    
    /**
     * @param User $user
     * @param Request $request
     */
    public function saveUser(User $user, Request $request)
    {
        if ($user->id) {
            $simpleUser = SimpleUser::find($user->id);
        } else {
            $simpleUser = new SimpleUser();
        }
        $isYoungAthlete = $user->isAthlete() && $user->isYoungAthlete() && !$user->isFreemiumAthlete();
        
        if ($user instanceof User) {
            $beforeData = $user->toArray();
            $errors = [];
            
            $data = $request->get('user');
            
            $validator = Validator::make($data, [
                'name' => 'required',
                'email' => 'required|email',
                'last_name' => 'required',
                'birthday' => ($user->isAthlete() && !$user->isYoungAthlete()) ? 'required' : 'nullable'
            ]);
    
            if (!$isYoungAthlete) {
                $validateData = [
                    'name' => 'required',
                    'email' => 'required|email',
                    'last_name' => 'required',
                ];
        
                if ($user->isAthlete()) {
                    $validateData['height'] = 'required|integer|min:10|max:100';
                    $validateData['weight'] = 'required|integer|min:10|max:300';
                }
            }

//            $facilitiesCount = Facility::where('id', $data['facility_id'])
//                ->whereIn('id', app('facilities'))
//                ->count();
//
//            if ($facilitiesCount === 0) {
//                $validator->errors()->add('facility_id', 'not_found');
//            }
//
//            if (isset($data['email']) && !empty($data['email'])) {
//                if (User::isEmailExist($data['email'], $user->id)) {
//                    $validator->errors()->add('email', 'A User with this Email already exists');
//                }
//            }
            
            $birthday = $data['birthday'];
            
            if ($birthday) {
                $date = User::validateBirthday($birthday);
                
                if ($date === null) {
                    $validator->errors()->add('birthday', 'Invalid birthday date format');
                }
                
                $birthday = $date;
                $data['birthday'] = $birthday;
            }
            
            $organizations = $data['organizations'];
            if (empty($organizations)) {
                $validator->errors()->add('organizations', 'Field can not be empty');
            }
            
            if (\count($validator->errors()->all()) > 0) {
                $errors = $validator->errors();
            }
            
            if (empty($errors)) {
                $user->fill($data);
                if (!$user->id) {
                    $user->password = bcrypt($data['password']);
                }
                
                switch ($data['role_id'])  {
                    case User::ROLE_ATHLETE:
                        $user->is_admin = false;
                        $user->is_trainer = false;
                        $user->status = User::STATUS_ACTIVE;
                        break;
                    case User::ROLE_TRAINER:
                        $user->is_trainer = true;
                        $user->is_admin = false;
                        $user->status = User::STATUS_ACTIVE;
                        break;
                    default:
                        $user->is_admin = true;
                        $user->is_trainer = false;
                        $user->status = User::STATUS_ACTIVE;
                        break;
                }
                
                $user->save();
                
                if (isset($data['organizations']) && !empty($data['organizations'])) {
                    $user->organizations()->detach();
                    
                    $organizations = [];
                    $element = [];
                    foreach ($data['organizations'] as $el) {
                        $element['facility_id'] = $el['id'];
                        $element['status'] = $el['status'] ?? null;
                        $element['sub_status'] = $el['sub_status'] ?? null;
                        $element['level'] = $el['level'] ?? null;
                        $element['program'] = $el['program'] ?? null;
                        $element['trainer'] = $el['trainer'] ?? null;
                        $element['platforms'] = !empty($el['platforms']) ? json_encode($el['platforms']) : null;
                        $element['right_admin'] = $el['right_admin'] ?? null;
                        $organizations[$element['facility_id']] = $element;
                        
                    }
                    $user->organizations()->attach($organizations);
                }
                
                if (isset($data['facilities']) && !empty($data['facilities'])) {
                    $user->facilities()->detach();
                    
                    $organizations = [];
                    foreach ($data['facilities'] as $el) {
                        $el['platforms'] = !empty($el['platforms']) ? json_encode($el['platforms']) : null;
                        $el['facility_id'] = $el['id'];
                        unset($el['id']);
                        unset($el['name']);
                        unset($el['parent_facility_id']);
                        $organizations[$el['facility_id']] = $el;
                    }
                    $user->facilities()->attach($organizations);
                }
                
                
                $simpleUser->id = $user->id;
                $simpleUser->name = $data['name'];
                $simpleUser->last_name = $data['last_name'];
                $simpleUser->save();
                
                $afterData = $user->toArray();
                (new LogAction())
                    ->setAction('edit')
                    ->setName('trainer')
                    ->setActionName('edit_trainer')
                    ->setBeforeData($beforeData)
                    ->setAfterData($afterData)
                    ->setForUserId($user->id)
                    ->setEntityId($user->id)
                    ->setEntityRepresentative($user->getName())
                    ->setEntityRepresentativeId((int)$user->id)
                    ->setEntity('Trainer Profile')
                    ->setUser($user->toArray())
                    ->save();
                
                if ($user->is_trainer) {
    
                    $client = new Client();
    
                    try {
                        $response = $client->post(env('REDASH_URL') . '/api/users?api_key=' . env('REDASH_KEY'), [
                            'json' => [
                                'email' => $user->email,
                                'password' => $this->genaratePassword(),
                                'name' => $user->getName()
                            ]
                        ]);
        
                        $json = json_decode($response->getBody());
        
                        if (isset($json->api_key) && !empty($json->api_key)) {
                            $user->redash_key = $json->api_key;
                            $user->save();
                        }
                    } catch (ClientException $e) {
                        //
                    } catch (RequestException $e) {
                        //
                    }
                }
                
               return response()->json(['failure' => false, 'success' => true, 'url' => $user->view_profile_route]);
            } else {
               return response()->json(['failure' => true, 'errors' => $errors]);
            }
        }
    }
    
    public function getAllOrganization()
    {
        return Facility::query()->where('type', Facility::TYPE_ORGANIZATION)->get(['id', 'name', 'parent_facility_id']);
    }
    
    public function getAllFacilities()
    {
        return Facility::query()->where('type', Facility::TYPE_FACILITY)->get(['id', 'name', 'parent_facility_id']);
    }
    
    public function getAllAthletes()
    {
        if ($this->currentUserRole == User::ROLE_ADMIN) {
            $athletes = User::query()->where('role_id', User::ROLE_ATHLETE)->get();
//        } else {
//            $athletes = $this->facility->athletes;
        }
        return $athletes;
    }
    
    public function getAllTrainers()
    {
        if ($this->currentUserRole == User::ROLE_ADMIN) {
            $trainers = User::query()->where('role_id', User::ROLE_TRAINER)->get();
        } else {
            $trainers = $this->facility->getTrainers();
        }
        return $trainers;
    }
    
    public function getUser($id)
    {
        $user = User::find($id);
        $user->organization = $user->organizationArray();
        $user->facilities = $user->facilitiesArray();
        
        return $user;
    }
    
    public function getAllSubstatuses()
    {
        return SubStatus::query()->orderBy('sort')->get(['id', 'name', 'type', 'default']);
    }
    
    public function getAllLevels()
    {
        return Level::orderBy('sort')->get(['id', 'name', 'default']);
    }
    
    public function getAllPrograms()
    {
        return Program::orderBy('sort')->get(['id', 'name', 'default']);
    }
    
    public function getAllPlatforms()
    {
        return PlatformData::PLATFORMS;
    }
    
    public function applyFilter($queryBuilder, $request, $filter)
    {
        $output = $queryBuilder
            ->join('facility_user', 'users.id', '=', 'facility_user.user_id')
            ->select(
                'users.*',
                'users.name as name',
                'users.last_name as last_name',
                'users.email as email',
                'facility_user.facility_id',
                'facility_user.status',
                'facility_user.sub_status',
                'facility_user.level',
                'facility_user.program',
                'facility_user.trainer'
            );
        $filter = $filter;
        
        if ($this->currentUserRole == User::ROLE_ADMIN) {
            if ($request->input('organization') && $request->input('organization') > 0) {
                $filter['organization'] = $request->input('organization');
                $output->where('facility_user.facility_id', $request->input('organization'));
            }
            
            if ($request->input('facility') && $request->input('facility') > 0) {
                $filter['facility'] = $request->input('facility');
                $output->where('facility_user.facility_id', $request->input('facility'));
            }
        }
        
        if ($request->input('search')) {
            if (!$request->session()->has('last_search_query')) {
                $request->session()->put('last_search_query', '');
            }
            
            $filter['search'] = $request->input('search');
            $output->where(function ($query) use ($filter) {
                $query->where(DB::raw('CONVERT(' . Aes::decrypt('users.name') . ' using utf8)'), 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere(DB::raw('CONVERT(' . Aes::decrypt('users.last_name') . ' using utf8)'), 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere(DB::raw("CONVERT(CONCAT(" . Aes::decrypt('users.name') . ", ' '," . Aes::decrypt('users.last_name') . ") using utf8)"), 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere(DB::raw("CONVERT(CONCAT(" . Aes::decrypt('users.last_name') . ", ' ', " . Aes::decrypt('users.name') . ") using utf8)"), 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere('users.email', 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere('users.address', 'LIKE', '%' . $filter['search'] . '%');
                $query->orWhere('users.state', 'LIKE', '%' . $filter['search'] . '%');
            });
        }
        
        if (isset(User::STATUS[(int)$request->input('active', -1)])) {
            $filter['active'] = $request->input('active');
            $output->where('facility_user.status', $request->input('active'));
        }
        if ((int)$request->input('status', -1) >= 0) {
            $filter['status'] = $request->input('status');
            $output->where('facility_user.sub_status', $request->input('status'));
        }
        if ($request->input('grade', -1) >= 0) {
            $filter['grade'] = $request->input('grade');
            $output->where('facility_user.level', $request->input('grade'));
        }
        if ((int)$request->input('trainer_id', -1) > 0) {
            $filter['trainer_id'] = $request->input('trainer_id');
            $output->where('facility_user.trainer', $request->input('trainer_id'));
        }
        if ((int)$request->input('program', -1) >= 0) {
            $filter['program'] = $request->input('program');
            $output->where('facility_user.program', $request->input('program'));
        }
        if ((int)$request->input('role', -1) >= 0) {
            $filter['role'] = $request->input('role');
            $output->where('users.role_id', $request->input('role'));
        }
        
        return ['output' => $output, 'filter' => $filter];
    }
    
    public function uploadPicture($id = null, Request $request)
    {
        if ($id) {
            $user = User::whereIn('facility_id', app('facilities'))->find($id);
            $storagePath = 'app/public';
        } else {
            $storagePath = 'app/tmp';
        }
        
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $file = $request->file('file');
            
            $extension = strtolower($file->getClientOriginalExtension());
            if (\in_array($extension, FileExtension::USER_ICON_FORMATS, true)) {
                try {
                    $fileName = $id ? $user->id . '.' . $extension : time() . '.' . $extension;
                    
                    if ($file->move(storage_path($storagePath), $fileName)) {
                        
                        if ($user) {
                            $beforeData = $user->toArray();
                            $user->picture = $extension;
                            $user->save();
                            $afterData = $user->toArray();
                            
                            (new LogAction())
                                ->setAction('edit')
                                ->setName('athlete')
                                ->setActionName('upload_image')
                                ->setBeforeData($beforeData)
                                ->setAfterData($afterData)
                                ->setForUserId($user->id)
                                ->setEntityId($user->id)
                                ->setEntity('Athlete Profile')
                                ->setEntityRepresentative($user->getName())
                                ->setUser($user->toArray())
                                ->setEntity($user->getName())
                                ->save();
                        }
                        return response()->json([
                            'success' => true,
                            'icon' => $fileName
                        ]);
                    }
                } catch (FileException $exception) {
                    return response()->json([
                        'failure' => true,
                        'error' => 'FileException'
                    ]);
                }
            }
        }
        
        return response()->json([
            'failure' => true,
            'error' => 'File not uploaded'
        ]);
    }
    
    private function genaratePassword()
    {
        $password = '';
        $chars = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));
        for ($i = 0; $i < 6; $i++) {
            $password .= $chars[array_rand($chars)];
        }
        return $password;
    }
    
}