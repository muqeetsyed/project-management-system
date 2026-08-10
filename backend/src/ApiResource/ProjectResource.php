<?php

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Project;
use App\Enum\ProjectStatus;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(),
        new Get(),
        new Post()
    ],
    stateOptions: new Options(entityClass: Project::class),
)]
#[Map(target: Project::class)]
class ProjectResource {
    #[ApiProperty(identifier: true)]
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $name = '';

    public ?string $description = null;

    public ProjectStatus $status = ProjectStatus::Active;

    #[ApiProperty(writable: false)]
    #[Map(if: false)]
    public ?\DateTimeImmutable $createdAt = null;
}
