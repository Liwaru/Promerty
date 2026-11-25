<?php

namespace App\Policies;

use App\Models\{Comment, User};

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return $user->hasPermission('interact_posts') && (int) $comment->user_id === (int) $user->id
            && $comment->moderation_status === 'active';
    }
    public function delete(User $user, Comment $comment): bool { return $this->update($user, $comment); }
}
