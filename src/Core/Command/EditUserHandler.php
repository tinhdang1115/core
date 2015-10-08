<?php
/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Core\Command;

use Flarum\Core\Access\AssertPermissionTrait;
use Flarum\Core\User;
use Flarum\Core\Repository\UserRepository;
use Flarum\Event\UserWillBeSaved;
use Flarum\Event\UserGroupsWereChanged;
use Flarum\Core\Support\DispatchEventsTrait;
use Illuminate\Contracts\Events\Dispatcher;

class EditUserHandler
{
    use DispatchEventsTrait;
    use AssertPermissionTrait;

    /**
     * @var UserRepository
     */
    protected $users;

    /**
     * @param Dispatcher $events
     * @param UserRepository $users
     */
    public function __construct(Dispatcher $events, UserRepository $users)
    {
        $this->events = $events;
        $this->users = $users;
    }

    /**
     * @param EditUser $command
     * @return User
     * @throws \Flarum\Core\Exception\PermissionDeniedException
     */
    public function handle(EditUser $command)
    {
        $actor = $command->actor;
        $data = $command->data;

        $user = $this->users->findOrFail($command->userId, $actor);

        $canEdit = $actor->can('edit', $user);
        $isSelf = $actor->id === $user->id;

        $attributes = array_get($data, 'attributes', []);
        $relationships = array_get($data, 'relationships', []);

        if (isset($attributes['username'])) {
            $this->assertPermission($canEdit);
            $user->rename($attributes['username']);
        }

        if (isset($attributes['email'])) {
            if ($isSelf) {
                $user->requestEmailChange($attributes['email']);
            } else {
                $this->assertPermission($canEdit);
                $user->changeEmail($attributes['email']);
            }
        }

        if (isset($attributes['password'])) {
            $this->assertPermission($canEdit);
            $user->changePassword($attributes['password']);
        }

        if (isset($attributes['bio'])) {
            if (! $isSelf) {
                $this->assertPermission($canEdit);
            }

            $user->changeBio($attributes['bio']);
        }

        if (! empty($attributes['readTime'])) {
            $this->assertPermission($isSelf);
            $user->markAllAsRead();
        }

        if (! empty($attributes['preferences'])) {
            $this->assertPermission($isSelf);

            foreach ($attributes['preferences'] as $k => $v) {
                $user->setPreference($k, $v);
            }
        }

        if (isset($relationships['groups']['data']) && is_array($relationships['groups']['data'])) {
            $this->assertPermission($canEdit);

            $newGroupIds = [];
            foreach ($relationships['groups']['data'] as $group) {
                if ($id = array_get($group, 'id')) {
                    $newGroupIds[] = $id;
                }
            }

            $user->raise(
                new UserGroupsWereChanged($user, $user->groups()->get()->all())
            );

            $user->afterSave(function (User $user) use ($newGroupIds) {
                $user->groups()->sync($newGroupIds);
            });
        }

        $this->events->fire(
            new UserWillBeSaved($user, $actor, $data)
        );

        $user->save();

        $this->dispatchEventsFor($user, $actor);

        return $user;
    }
}