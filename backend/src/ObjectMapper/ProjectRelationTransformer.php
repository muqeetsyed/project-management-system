<?php

namespace App\ObjectMapper;

use App\ApiResource\ProjectResource;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * Converts the `project` relation between its API shape and its database shape.
 *
 * The ObjectMapper copies same-named properties across without understanding
 * Doctrine, so a relation has to be translated by hand in both directions:
 *
 *   write (TaskResource -> Task):  ProjectResource -> a Doctrine-managed Project
 *   read  (Task -> TaskResource):  Project         -> ProjectResource
 *
 * Without this, the write direction would recursively map the incoming
 * ProjectResource into a brand new detached Project and Doctrine would try to
 * insert a second copy of a project that already exists.
 *
 * @implements TransformCallableInterface<object, object>
 */
final class ProjectRelationTransformer implements TransformCallableInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        // Writing: the caller sent an IRI, which API Platform already resolved
        // into a ProjectResource — so the row is known to exist and a reference
        // is enough. getReference() gives a lazy proxy without a second query.
        if ($value instanceof ProjectResource) {
            return null === $value->id
                ? null
                : $this->entityManager->getReference(Project::class, $value->id);
        }

        // Reading: hand back the API shape so API Platform can render it as an IRI.
        if ($value instanceof Project) {
            $resource = new ProjectResource();
            $resource->id = $value->getId();
            $resource->name = $value->getName();
            $resource->description = $value->getDescription();
            $resource->status = $value->getStatus();
            $resource->createdAt = $value->getCreatedAt();

            return $resource;
        }

        return null;
    }
}
