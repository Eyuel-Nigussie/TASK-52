<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Facility;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MergeRequestFactory extends Factory
{
    protected $model = MergeRequest::class;

    public function definition(): array
    {
        // Default: create two real Patient rows in the same facility so the
        // approve() path (which relinks FKs and soft-deletes the source)
        // has something to operate on.
        $facility = Facility::factory()->create();
        $source = Patient::factory()->create(['facility_id' => $facility->id]);
        $target = Patient::factory()->create(['facility_id' => $facility->id]);

        return [
            'entity_type'  => 'patient',
            'source_id'    => $source->id,
            'target_id'    => $target->id,
            'status'       => 'pending',
            'requested_by' => User::factory(),
        ];
    }

    public function entityType(string $type): static
    {
        return $this->state(fn () => ['entity_type' => $type]);
    }
}
