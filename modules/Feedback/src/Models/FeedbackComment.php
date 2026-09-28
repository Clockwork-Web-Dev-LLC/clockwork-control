<?php

namespace Modules\Feedback\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $feedback_item_id
 * @property int $user_id
 * @property string $content
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?FeedbackItem $item
 * @property-read ?User $user
 */
class FeedbackComment extends Model
{
    protected $table = 'feedback_comments';

    protected $fillable = [
        'feedback_item_id',
        'user_id',
        'content',
    ];

    /**
     * @return BelongsTo<FeedbackItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(FeedbackItem::class, 'feedback_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
