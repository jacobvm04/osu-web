<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Models\Chat;

use App\Events\ChatChannelEvent;
use App\Exceptions\API;
use App\Exceptions\InvariantException;
use App\Jobs\Notifications\ChannelAnnouncement;
use App\Jobs\Notifications\ChannelMessage;
use App\Libraries\AuthorizationResult;
use App\Libraries\Chat\MessageTask;
use App\Models\LegacyMatch\LegacyMatch;
use App\Models\Multiplayer\Room;
use App\Models\User;
use App\Traits\Memoizes;
use App\Traits\Validatable;
use Carbon\Carbon;
use ChaseConey\LaravelDatadogHelper\Datadog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use LaravelRedis as Redis;

/**
 * @property string[] $allowed_groups
 * @property int $channel_id
 * @property \Carbon\Carbon $creation_time
 * @property string $description
 * @property \Illuminate\Database\Eloquent\Collection $messages Message
 * @property int|null $match_id
 * @property int $moderated
 * @property string $name
 * @property int|null $room_id
 * @property mixed $type
 */
class Channel extends Model
{
    use Memoizes {
        Memoizes::resetMemoized as origResetMemoized;
    }

    use Validatable;

    const ANNOUNCE_MESSAGE_LENGTH_LIMIT = 1024; // limited by column length
    const CHAT_ACTIVITY_TIMEOUT = 60; // in seconds.

    public ?string $uuid = null;

    protected $primaryKey = 'channel_id';

    protected $casts = [
        'moderated' => 'boolean',
    ];

    protected $dates = [
        'creation_time',
    ];

    private ?Collection $pmUsers;
    private array $preloadedUserChannels = [];

    const TYPES = [
        'announce' => 'ANNOUNCE',
        'public' => 'PUBLIC',
        'private' => 'PRIVATE',
        'multiplayer' => 'MULTIPLAYER',
        'spectator' => 'SPECTATOR',
        'temporary' => 'TEMPORARY',
        'pm' => 'PM',
        'group' => 'GROUP',
    ];

    /**
     * Creates a chat broadcast Channel and associated UserChannels.
     *
     * @param Collection $users
     * @param array $rawParams
     * @return Channel
     */
    public static function createAnnouncement(Collection $users, array $rawParams, ?string $uuid = null): self
    {
        $params = get_params($rawParams, null, [
            'description:string',
            'name:string',
        ], ['null_missing' => true]);

        $params['moderated'] = true;
        $params['type'] = static::TYPES['announce'];

        $channel = new static($params);
        $connection = $channel->getConnection();
        $connection->transaction(function () use ($channel, $connection, $users, $uuid) {
            $channel->saveOrExplode();
            $channel->uuid = $uuid;
            $userChannels = $channel->userChannels()->createMany($users->map(fn ($user) => ['user_id' => $user->getKey()]));
            foreach ($userChannels as $userChannel) {
                // preset to avoid extra queries during permission check.
                $userChannel->setRelation('channel', $channel);
                $userChannel->channel->setUserChannel($userChannel);
            }

            foreach ($users as $user) {
                (new ChatChannelEvent($channel, $user, 'join'))->broadcast(true);
            }

            $connection->afterCommit(fn () => Datadog::increment('chat.channel.create', 1, ['type' => $channel->type]));
        });

        return $channel;
    }

    public static function createMultiplayer(Room $room)
    {
        if (!$room->exists) {
            throw new InvariantException('cannot create Channel for a Room that has not been persisted.');
        }

        return static::create([
            'name' => "#lazermp_{$room->getKey()}",
            'type' => static::TYPES['multiplayer'],
            'description' => $room->name,
        ]);
    }

    public static function createPM(User $user1, User $user2)
    {
        $channel = new static([
            'name' => static::getPMChannelName($user1, $user2),
            'type' => static::TYPES['pm'],
            'description' => '', // description is not nullable
        ]);

        $connection = $channel->getConnection();
        $connection->transaction(function () use ($channel, $connection, $user1, $user2) {
            $channel->saveOrExplode();
            $channel->addUser($user1);
            $channel->addUser($user2);
            $channel->setPmUsers([$user1, $user2]);

            $connection->afterCommit(fn () => Datadog::increment('chat.channel.create', 1, ['type' => $channel->type]));
        });

        return $channel;
    }

    public static function findPM(User $user1, User $user2)
    {
        $channelName = static::getPMChannelName($user1, $user2);

        $channel = static::where('name', $channelName)->first();

        $channel?->setPmUsers([$user1, $user2]);

        return $channel;
    }

    public static function getAckKey(int $channelId)
    {
        return "chat:channel:{$channelId}";
    }

    /**
     * @param User $user1
     * @param User $user2
     *
     * @return string
     */
    public static function getPMChannelName(User $user1, User $user2)
    {
        $userIds = [$user1->getKey(), $user2->getKey()];
        sort($userIds);

        return '#pm_'.implode('-', $userIds);
    }

    public function activeUserIds()
    {
        return $this->isPublic()
            ? Redis::zrangebyscore(static::getAckKey($this->getKey()), now()->subSeconds(static::CHAT_ACTIVITY_TIMEOUT)->timestamp, 'inf')
            : $this->userIds();
    }

    /**
     * This check is used for whether the user can enter into the input box for the channel,
     * not if a message is actually allowed to be sent.
     */
    public function checkCanMessage(User $user): AuthorizationResult
    {
        return priv_check_user($user, 'ChatChannelCanMessage', $this);
    }

    public function displayIconFor(?User $user): ?string
    {
        return $this->pmTargetFor($user)?->user_avatar;
    }

    public function displayNameFor(?User $user): ?string
    {
        if (!$this->isPM()) {
            return $this->name;
        }

        return $this->pmTargetFor($user)?->username;
    }

    public function setDescriptionAttribute(?string $value)
    {
        $this->attributes['description'] = $value !== null ? trim($value) : null;
    }

    public function setNameAttribute(?string $value)
    {
        $this->attributes['name'] = presence(trim($value));
    }

    public function isVisibleFor(User $user): bool
    {
        if (!$this->isPM()) {
            return true;
        }

        $targetUser = $this->pmTargetFor($user);

        return !(
            $targetUser === null
            || $user->hasBlocked($targetUser)
            && !($targetUser->isBot() || $targetUser->isModerator() || $targetUser->isAdmin())
        );
    }

    /**
     * Preset the UserChannel with Channel::setUserChannel when handling multiple channels.
     * UserChannelList will automatically do this.
     */
    public function lastReadIdFor(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        return $this->userChannelFor($user)?->last_read_id;
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function filteredMessages()
    {
        $messages = $this->messages();

        if ($this->isPublic()) {
            $messages = $messages->where('timestamp', '>', Carbon::now()->subHours(config('osu.chat.public_backlog_limit')));
        }

        // TODO: additional message filtering

        return $messages;
    }

    public function userChannels()
    {
        return $this->hasMany(UserChannel::class);
    }

    public function userIds(): array
    {
        return $this->memoize(__FUNCTION__, function () {
            // 4 = strlen('#pm_')
            if ($this->isPM() && substr($this->name, 0, 4) === '#pm_') {
                $userIds = get_arr(explode('-', substr($this->name, 4)), 'get_int');
            }

            return $userIds ?? $this->userChannels()->pluck('user_id')->all();
        });
    }

    public function users(): Collection
    {
        return $this->memoize(__FUNCTION__, function () {
            if ($this->isPM() && isset($this->pmUsers)) {
                return $this->pmUsers;
            }

            // This isn't a has-many-through because the relationship is cross-database.
            return User::whereIn('user_id', $this->userIds())->get();
        });
    }

    public function visibleUsers(?User $user)
    {
        if ($this->isPM() || $this->isAnnouncement() && priv_check_user($user, 'ChatAnnounce', $this)->can()) {
            return $this->users();
        }

        return new Collection();
    }

    public function scopePublic($query)
    {
        return $query->where('type', static::TYPES['public']);
    }

    public function scopePM($query)
    {
        return $query->where('type', static::TYPES['pm']);
    }

    public function getAllowedGroupsAttribute($allowed_groups)
    {
        return $allowed_groups === null ? [] : array_map('intval', explode(',', $allowed_groups));
    }

    public function isAnnouncement()
    {
        return $this->type === static::TYPES['announce'];
    }

    public function isHideable()
    {
        return $this->isPM() || $this->isAnnouncement();
    }

    public function isMultiplayer()
    {
        return $this->type === static::TYPES['multiplayer'];
    }

    public function isPublic()
    {
        return $this->type === static::TYPES['public'];
    }

    public function isPrivate()
    {
        return $this->type === static::TYPES['private'];
    }

    public function isPM()
    {
        return $this->type === static::TYPES['pm'];
    }

    public function isGroup()
    {
        return $this->type === static::TYPES['group'];
    }

    public function isBanchoMultiplayerChat()
    {
        return $this->type === static::TYPES['temporary'] && starts_with($this->name, ['#mp_', '#spect_']);
    }

    public function getMatchIdAttribute()
    {
        // TODO: add lazer mp support?
        if ($this->isBanchoMultiplayerChat()) {
            return intval(str_replace('#mp_', '', $this->name));
        }
    }

    public function isValid()
    {
        $this->validationErrors()->reset();

        if ($this->name === null) {
            $this->validationErrors()->add('name', 'required');
        }

        if ($this->description === null) {
            $this->validationErrors()->add('description', 'required');
        }

        return $this->validationErrors()->isEmpty();
    }

    public function getRoomIdAttribute()
    {
        // 9 = strlen('#lazermp_')
        if ($this->isMultiplayer() && substr($this->name, 0, 9) === '#lazermp_') {
            return get_int(substr($this->name, 9));
        }
    }

    public function multiplayerMatch()
    {
        return $this->belongsTo(LegacyMatch::class, 'match_id');
    }

    public function pmTargetFor(?User $user): ?User
    {
        if (!$this->isPM() || $user === null) {
            return null;
        }

        $userId = $user->getKey();

        return $this->memoize(__FUNCTION__.':'.$userId, function () use ($userId) {
            return $this->users()->firstWhere('user_id', '<>', $userId);
        });
    }

    public function receiveMessage(User $sender, ?string $content, bool $isAction = false, ?string $uuid = null)
    {
        if (!$this->isAnnouncement()) {
            $content = str_replace(["\r", "\n"], ' ', trim($content));
        }

        if (!present($content)) {
            throw new API\ChatMessageEmptyException(osu_trans('api.error.chat.empty'));
        }

        $maxLength = $this->isAnnouncement() ? static::ANNOUNCE_MESSAGE_LENGTH_LIMIT : config('osu.chat.message_length_limit');
        if (mb_strlen($content, 'UTF-8') > $maxLength) {
            throw new API\ChatMessageTooLongException(osu_trans('api.error.chat.too_long'));
        }

        if ($this->isPM()) {
            $limit = config('osu.chat.rate_limits.private.limit');
            $window = config('osu.chat.rate_limits.private.window');
            $keySuffix = 'PM';
        } else {
            $limit = config('osu.chat.rate_limits.public.limit');
            $window = config('osu.chat.rate_limits.public.window');
            $keySuffix = 'PUBLIC';
        }

        $key = "message_throttle:{$sender->user_id}:{$keySuffix}";
        $now = now();

        // This works by keeping a sorted set of when the last messages were sent by the user (per message type).
        // The timestamp of the message is used as the score, which allows for zremrangebyscore to cull old messages
        // in a rolling window fashion.
        [,$sent] = Redis::transaction()
            ->zremrangebyscore($key, 0, $now->timestamp - $window)
            ->zrange($key, 0, -1, 'WITHSCORES')
            ->zadd($key, $now->timestamp, (string) Str::uuid())
            ->expire($key, $window)
            ->exec();

        if (count($sent) >= $limit) {
            throw new API\ExcessiveChatMessagesException(osu_trans('api.error.chat.limit_exceeded'));
        }

        $chatFilters = app('chat-filters')->all();

        foreach ($chatFilters as $filter) {
            $content = str_replace($filter->match, $filter->replacement, $content);
        }

        $message = new Message([
            'content' => $content,
            'is_action' => $isAction,
            'timestamp' => $now,
        ]);

        $message->sender()->associate($sender)->channel()->associate($this)
            ->uuid = $uuid; // relay any message uuid back.

        $message->getConnection()->transaction(function () use ($message, $sender) {
            $message->save();

            $this->update(['last_message_id' => $message->getKey()]);

            $userChannel = $this->userChannelFor($sender);

            if ($userChannel) {
                $userChannel->markAsRead($message->message_id);
            }

            if ($this->isPM()) {
                if ($this->unhide()) {
                    // assume a join event has to be sent if any channels need to need to be unhidden.
                    (new ChatChannelEvent($this, $this->pmTargetFor($sender), 'join'))->broadcast();
                }

                (new ChannelMessage($message, $sender))->dispatch();
            } elseif ($this->isAnnouncement()) {
                (new ChannelAnnouncement($message, $sender))->dispatch();
            }

            $message->getConnection()->transaction(fn () => MessageTask::dispatch($message));
        });

        Datadog::increment('chat.channel.send', 1, ['target' => $this->type]);

        return $message;
    }

    public function addUser(User $user)
    {
        $userChannel = $this->userChannelFor($user);

        if ($userChannel) {
            // already in channel, just broadcast event.
            if (!$userChannel->isHidden()) {
                (new ChatChannelEvent($this, $user, 'join'))->broadcast(true);

                return;
            }

            $userChannel->update(['hidden' => false]);
        } else {
            $userChannel = new UserChannel();
            $userChannel->user()->associate($user);
            $userChannel->channel()->associate($this);
            $userChannel->save();
            $this->resetMemoized();
        }

        (new ChatChannelEvent($this, $user, 'join'))->broadcast(true);

        Datadog::increment('chat.channel.join', 1, ['type' => $this->type]);
    }

    public function removeUser(User $user)
    {
        $userChannel = $this->userChannelFor($user);

        if ($userChannel === null) {
            return;
        }

        if ($this->isHideable()) {
            if ($userChannel->isHidden()) {
                return;
            }

            $userChannel->update(['hidden' => true]);
        } else {
            $userChannel->delete();
        }

        $this->resetMemoized();

        (new ChatChannelEvent($this, $user, 'part'))->broadcast(true);

        Datadog::increment('chat.channel.part', 1, ['type' => $this->type]);
    }

    public function hasUser(User $user)
    {
        return $this->userChannelFor($user) !== null;
    }

    public function save(array $options = [])
    {
        return $this->isValid() && parent::save($options);
    }

    public function setPmUsers(array $users)
    {
        $this->pmUsers = new Collection($users);
    }

    public function setUserChannel(UserChannel $userChannel)
    {
        if ($userChannel->channel_id !== $this->getKey()) {
            throw new InvariantException('userChannel does not belong to the channel.');
        }

        $this->preloadedUserChannels[$userChannel->user_id] = $userChannel;
    }

    public function validationErrorsTranslationPrefix()
    {
        return 'chat.channel';
    }

    protected function resetMemoized(): void
    {
        $this->origResetMemoized();
        // simpler to reset preloads since its use-cases are more specific,
        // rather than trying to juggle them to ensure userChannelFor returns as expected.
        $this->preloadedUserChannels = [];
    }

    private function unhide()
    {
        if (!$this->isHideable()) {
            return;
        }

        return UserChannel::where([
            'channel_id' => $this->channel_id,
            'hidden' => true,
        ])->update([
            'hidden' => false,
        ]);
    }

    private function userChannelFor(User $user): ?UserChannel
    {
        $userId = $user->getKey();

        return $this->memoize(__FUNCTION__.':'.$userId, function () use ($user, $userId) {
            $userChannel = $this->preloadedUserChannels[$userId] ?? UserChannel::where([
                'channel_id' => $this->channel_id,
                'user_id' => $userId,
            ])->first();

            $userChannel?->setRelation('user', $user);

            return $userChannel;
        });
    }
}
