<?php

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Task;
use App\ObjectMapper\ProjectRelationTransformer;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Task',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    stateOptions: new Options(entityClass: Task::class),
)]
#[Map(target: Task::class)]
class TaskResource
{
    #[ApiProperty(identifier: true)]
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $title = null;

    public ?string $description = null;

    public bool $completed = false;

    public ?\DateTimeImmutable $dueDate = null;

    /**
     * Owned by the entity: Task::setCompleted() stamps and clears this, so the
     * mapper must never copy it back onto the entity. TargetClass limits the
     * mapping to the read direction — an unconditional `if: false` would also
     * hide the field in responses.
     */
    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(TaskResource::class))]
    public ?\DateTimeImmutable $completedAt = null;

    /**
     * Sent as an IRI: {"project": "/api/projects/1"}.
     */
    #[Assert\NotNull]
    #[Map(transform: ProjectRelationTransformer::class)]
    public ?ProjectResource $project = null;
}
