<?php namespace App\v2\Models;

use App\v2\Data\TS;
use App\v2\Facades\Gapi;
use App\v2\Facades\Mapi;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Class User
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $picture
 * @property array $status
 * @property int $bats
 * @property int $throw
 * @property string $age
 * @property array $subStatus
 * @property array $program
 * @property array $level
 * @property array $social_links
 * @property int $creator_id
 *
 * @property Carbon $birthday
 *
 * @method static User|User[]|Collection find(int|int[]|Collection $id)
 * @method static User findOrFail(int $id)
 *
 * @package App\v2\Models
 */
class User extends Model
{
    use SoftDeletes;

    const ICON_WIDTH = 96;
    const ICON_HEIGHT = 96;

    /**
     * @var string
     */
    const TABLE = 'v2_user';
    const PROGRAM_NAMES = \App\User::PROGRAM_NAMES;
    const SUB_STATUS = \App\User::SUB_STATUS;
    const HANDEDNESS = \App\User::HANDEDNESS;

    protected $table = self::TABLE;

    /**
     * @var array
     */
    public $fillable = [
        'first_name',
        'last_name',
        'password',
        'creator_id',
        'created_at',
        'updated_at',
        'email',
        'phone',
        'comment',
        'birthday',
        'picture',
        'height',
        'weight',
        'bats',
        'program',
        'status',
        'subStatus',
        'level',
        'social_links',
        'throw'
    ];

    protected $fillableEncrypt = [
        'first_name',
        'last_name',
        'email',
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'program' => 'array',
        'status' => 'array',
        'subStatus' => 'array',
        'level' => 'array',
        'trainer_id' => 'array',
        'social_links' => 'array',
        'phone' => 'array',
    ];

    /**
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'birthday'];

    /************ RELATIONS *************/

    public function trainers()
    {
        return $this->hasMany(Trainer::class, 'user_id', 'id');
    }

    public function athletes()
    {
        return $this->hasMany(Athlete::class, 'user_id', 'id');
    }

    /********** END_RELATIONS ***********/

    /**
     * @return string
     */
    public function getBirthDay()
    {
        return $this->birthday ? $this->birthday->format('m/d/Y') : '';
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getAgeAttribute()
    {
        $birthday = $this->birthday ?: false;
        $birthday = new Carbon($birthday);

        if ($birthday) {
            $now = new \DateTime('now');

            return $birthday->diff($now)->y;
        }

        return '&mdash;';
    }

    /**
     * @return string
     */
    public function getCreatedDate()
    {
        return $this->created_at && $this->created_at->format('Y') != '-0001' ? $this->created_at->format('m/d/Y') : '';
    }

    /**
     * @return Facility[]
     */
    public function getFacilities()
    {
        $sList = Gapi::unitGroups($this->makeTS(), Facility::TABLE, Facility::getAllFacilitiesId());

        return Facility::find($sList);
    }

    /**
     * @return bool
     */
    public function isSuperAdmin()
    {
        $sList = Gapi::unitGroups($this->makeTS(), Group::TABLE, [Group::getGroupTS(GroupType::SUPER_ADMIN)->S]);

        return \count($sList) > 0;
    }

    /**
     * @return bool
     */
    public function hasRightSuperAdmin()
    {
        return Mapi::and($this->makeTS(), Group::getGroupTS(GroupType::SUPER_ADMIN), [Marker::getMarker('@updated_super_admin')->uid]) > 0;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * @return \App\v2\Data\TS
     */
    public function makeTS()
    {
        return new TS(self::TABLE, $this->id);
    }

    /**
     * @return array
     */
    public function prepeare()
    {
        return [
            'id' => $this->id,
            'name' => $this->getName(),
        ];
    }
}