<?php

namespace Modules\ClientManagement\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ClientReports\Models\ClientReport;

/**
 * @property int $id
 * @property string $name
 * @property ?string $company_name
 * @property string $email
 * @property ?array<int, string> $additional_emails
 * @property ?string $phone
 * @property ?string $address
 * @property ?string $notes
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, ClientReport> $reports
 */
class Client extends Model
{
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'name',
        'company_name',
        'email',
        'additional_emails',
        'phone',
        'address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'additional_emails' => 'array',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ClientReport::class);
    }

    /**
     * Get all recipients (primary email + any valid secondary/CC emails).
     *
     * @return array<int, string>
     */
    public function allRecipients(): array
    {
        $recipients = array_filter([trim($this->email)]);

        if (! empty($this->additional_emails) && is_array($this->additional_emails)) {
            foreach ($this->additional_emails as $email) {
                $email = trim((string) $email);
                if (! empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $recipients, true)) {
                    $recipients[] = $email;
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * Human-friendly display label (e.g. "Acme Corp (Jane Doe)").
     */
    public function displayName(): string
    {
        if (! empty($this->company_name)) {
            return "{$this->company_name} ({$this->name})";
        }

        return $this->name;
    }
}
