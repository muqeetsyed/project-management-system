<?php

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\SortFilter;
use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Project;
use App\Enum\ProjectStatus;
use App\ObjectMapper\TaskRelationTransformer;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'Project',
    operations: [
        new GetCollection(
            parameters:[
                'createdAt' => new QueryParameter(
                    filter: new SortFilter(),
                    property: 'createdAt'
                ),
                'id' => new QueryParameter(
                    filter: new ExactFilter(),
                ),
                'name' => new QueryParameter(
                    filter: new PartialSearchFilter(),
                ),
            ]
        ),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    stateOptions: new Options(entityClass: Project::class),
    paginationItemsPerPage: 2,
    normalizationContext: ['skip_null_values' => false],
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

    #[ApiProperty(
        writable: false,
        readable: true,
        description: 'This shows the time project is created'
    )]
    public \DateTimeImmutable $createdAt;

    /**
     * @var TaskResource[]
     */
    #[ApiProperty(
        writable: false
    )]
    #[Map(transform: TaskRelationTransformer::class)]
    public array $tasks = [];
}
