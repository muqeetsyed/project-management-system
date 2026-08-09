<?php

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Project;
use App\Enum\ProjectStatus;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(),
        new Get()
    ],
    stateOptions: new Options(entityClass: Project::class),
)]
#[Map(target: Project::class)]
class ProjectResource {


    #[ApiProperty(identifier: true)]
    public ?int $id = null;

    public ProjectStatus $name = ProjectStatus::Active;

    public ?string $description = null;

    public string $status = 'active';

    public ?\DateTimeImmutable $createdAt = null;
}
